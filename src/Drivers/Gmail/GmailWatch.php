<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\Gmail;

use DateTimeImmutable;
use Psr\Http\Message\RequestInterface;
use Pyle\Mailbox\Contracts\GmailPushVerifier;
use Pyle\Mailbox\Contracts\GrantAccessGuard;
use Pyle\Mailbox\DTOs\GmailChangeHint;
use Pyle\Mailbox\DTOs\GmailWatchConfiguration;
use Pyle\Mailbox\DTOs\GmailWatchRegistration;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\InvalidChangeNotificationException;
use Pyle\Mailbox\Exceptions\InvalidSyncStateException;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;
use Pyle\Mailbox\Support\ResponseBudget;

final readonly class GmailWatch
{
    public function __construct(private GmailClient $client, private GrantContext $context, private GrantAccessGuard $guard) {}

    /** @param array<array-key, mixed> $labelIds */
    public function register(GmailWatchConfiguration $configuration, array $labelIds): GmailWatchRegistration
    {
        if (! array_is_list($labelIds)) {
            throw new \InvalidArgumentException('Gmail watch labels must be a list of non-empty label IDs.');
        }
        foreach ($labelIds as $label) {
            if (! is_string($label) || trim($label) === '' || preg_match('/[\x00-\x1f\x7f]/', $label)) {
                throw new \InvalidArgumentException('Gmail watch labels must be a list of non-empty label IDs.');
            }
        }
        $payload = ['topicName' => $configuration->topic];
        if ($labelIds !== []) {
            $payload += ['labelIds' => $labelIds, 'labelFilterBehavior' => 'include'];
        }
        $this->guard->assertActive($this->context);
        $response = $this->client->requestOnce('POST', 'users/'.rawurlencode($this->context->mailbox).'/watch', ['json' => $payload], $this->context->mailbox);
        $this->guard->assertActive($this->context);
        $data = json_decode($response->body() ?? '', true);
        if ($response->status !== 200 || ! is_array($data)
            || ! is_string($data['historyId'] ?? null) || preg_match('/\A[0-9]+\z/', $data['historyId']) !== 1
            || ! is_string($data['expiration'] ?? null) || preg_match('/\A[0-9]{4,15}\z/', $data['expiration']) !== 1) {
            throw new ApiRequestException('Gmail watch registration was not confirmed; inspect setup and reconcile before retrying.', status: $response->status);
        }
        $expiresAt = DateTimeImmutable::createFromFormat('U.v', substr($data['expiration'], 0, -3).'.'.substr($data['expiration'], -3));
        if ($expiresAt === false) {
            throw new ApiRequestException('Gmail returned an invalid watch expiration.');
        }

        return new GmailWatchRegistration($this->context, $configuration, $data['historyId'], $expiresAt);
    }

    public function stop(GmailWatchRegistration $registration): GmailWatchRegistration
    {
        $this->assertRegistration($registration);
        $response = $this->client->requestOnce('POST', 'users/'.rawurlencode($this->context->mailbox).'/stop', mailbox: $this->context->mailbox);
        $this->guard->assertActive($this->context);
        $data = json_decode($response->body() ?? '');
        if (! ($response->status === 204 && ($response->body() ?? '') === '')
            && ! ($response->status === 200 && $data instanceof \stdClass && get_object_vars($data) === [])) {
            throw new ApiRequestException('Gmail watch stopping was not confirmed; inspect registration state and reconcile before retrying.', status: $response->status);
        }

        return new GmailWatchRegistration($registration->context, $registration->configuration, $registration->historyId, $registration->expiresAt, 'stopped');
    }

    public function notification(#[\SensitiveParameter] RequestInterface $request, GmailWatchRegistration $registration, #[\SensitiveParameter] ?GmailPushVerifier $verifier, int $maxRequestBytes): GmailChangeHint
    {
        $this->assertRegistration($registration);
        if ($verifier === null) {
            throw new UnsupportedCapabilityException('Authenticated Gmail push requires a configured trusted host verifier.');
        }
        try {
            $claims = $verifier->verify($request);
        } catch (\Throwable) {
            throw new InvalidChangeNotificationException('The trusted host rejected Pub/Sub authentication.');
        }
        $this->guard->assertActive($this->context);
        $configuration = $registration->configuration;
        if (! in_array($claims['iss'] ?? null, ['https://accounts.google.com', 'accounts.google.com'], true)
            || ($claims['aud'] ?? null) !== $configuration->audience
            || ($claims['sub'] ?? null) !== $configuration->serviceAccountSubject
            || ($claims['email'] ?? null) !== $configuration->serviceAccountEmail
            || ($claims['email_verified'] ?? null) !== true
            || ! is_int($claims['exp'] ?? null) || $claims['exp'] <= time()
            || ! is_int($claims['iat'] ?? null) || $claims['iat'] > time() || $claims['iat'] >= $claims['exp']) {
            throw new InvalidChangeNotificationException('The verified Pub/Sub claims do not match trusted push configuration.');
        }
        if ($request->getMethod() !== 'POST' || strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0])) !== 'application/json') {
            throw new InvalidChangeNotificationException('Gmail push requires a wrapped JSON POST request.');
        }
        $budget = new ResponseBudget($maxRequestBytes);
        $stream = $request->getBody();
        $body = '';
        try {
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            while (true) {
                $chunk = $stream->read(min(8192, $budget->remaining()) + 1);
                if ($chunk === '') {
                    if ($stream->eof()) {
                        break;
                    }
                    throw new \RuntimeException;
                }
                $budget->consume(strlen($chunk));
                $body .= $chunk;
            }
            $envelope = json_decode($body, flags: JSON_THROW_ON_ERROR, depth: 16);
        } catch (\Throwable) {
            throw new InvalidChangeNotificationException('The Pub/Sub envelope is unreadable, malformed or exceeds its byte budget.');
        }
        if (! $envelope instanceof \stdClass || ($envelope->subscription ?? null) !== $configuration->subscription
            || ! ($envelope->message ?? null) instanceof \stdClass
            || ! is_string($envelope->message->messageId ?? null) || preg_match('/\A[A-Za-z0-9_-]{1,512}\z/', $envelope->message->messageId) !== 1
            || ! is_string($envelope->message->data ?? null) || preg_match('/\A[A-Za-z0-9+\/_-]+={0,2}\z/', $envelope->message->data) !== 1) {
            throw new InvalidChangeNotificationException('The Pub/Sub envelope does not match the trusted Gmail registration.');
        }
        $encoded = strtr($envelope->message->data, '-_', '+/');
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || rtrim(base64_encode($decoded), '=') !== rtrim($encoded, '=')) {
            throw new InvalidChangeNotificationException('The Gmail notification data is not valid base64.');
        }
        try {
            $data = json_decode($decoded, flags: JSON_THROW_ON_ERROR, depth: 8);
        } catch (\JsonException) {
            throw new InvalidChangeNotificationException('The Gmail notification data is not a valid JSON object.');
        }
        if (! $data instanceof \stdClass || ! is_string($data->emailAddress ?? null)
            || strcasecmp($data->emailAddress, $this->context->mailbox) !== 0
            || ! is_string($data->historyId ?? null) || preg_match('/\A[0-9]+\z/', $data->historyId) !== 1) {
            throw new InvalidChangeNotificationException('The Gmail notification account or history position does not match the selected mailbox.');
        }
        $this->guard->assertActive($this->context);

        $reason = $registration->lifecycle() === 'active' ? 'mailbox_changed' : 'watch_'.$registration->lifecycle();

        return new GmailChangeHint($registration->context, $configuration->topic, $configuration->subscription, $envelope->message->messageId, $data->historyId, $reason);
    }

    public function catchUpHint(GmailWatchRegistration $registration): GmailChangeHint
    {
        $this->assertRegistration($registration);
        $configuration = $registration->configuration;
        $reason = $registration->lifecycle() === 'active' ? 'missed_delivery' : 'watch_'.$registration->lifecycle();

        return new GmailChangeHint($registration->context, $configuration->topic, $configuration->subscription, null, null, $reason);
    }

    private function assertRegistration(GmailWatchRegistration $registration): void
    {
        if (! $registration->context->matches($this->context)) {
            throw new InvalidSyncStateException('The Gmail watch belongs to a different grant, mailbox or lifecycle generation.');
        }
        $this->guard->assertActive($this->context);
    }
}
