<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use InvalidArgumentException;
use Pyle\Mailbox\DTOs\EmailAddressDto;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\PreparedMessage;
use Pyle\Mailbox\DTOs\SubmissionPreparation;
use Pyle\Mailbox\DTOs\SubmissionResult;
use Pyle\Mailbox\Enums\PreparationOutcome;
use Pyle\Mailbox\Enums\SubmissionOutcome;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;
use Pyle\Mailbox\Support\PreparedMessageMime;

final readonly class MsGraphDraftSubmission
{
    public function __construct(private GraphClient $client, private GrantContext $context) {}

    public function prepare(PreparedMessage $message, int $maxRequestBytes): SubmissionPreparation
    {
        $payload = $this->payload($message, $maxRequestBytes);
        $response = $this->client->requestOnce('POST', $this->endpoint(), [
            'body' => $payload, 'headers' => ['Content-Type' => 'application/json', 'Prefer' => 'IdType="ImmutableId"'],
        ], $this->context->mailbox, maxResponseBytes: 4_194_304);
        $data = json_decode($response->body() ?? '', true);
        $draftId = is_array($data) && self::validId($data['id'] ?? null) ? $data['id'] : null;
        $outcome = $response->status === 201 && $draftId !== null ? PreparationOutcome::Prepared : PreparationOutcome::Uncertain;
        if (self::rejected($response->status)) {
            $outcome = PreparationOutcome::Rejected;
        }

        return new SubmissionPreparation(
            $this->context, $outcome, $message->correlationId,
            $outcome === PreparationOutcome::Prepared ? $draftId : null,
            $response->status, $response->headers, hash('sha256', $payload),
        );
    }

    public function submit(SubmissionPreparation $preparation, PreparedMessage $message): SubmissionResult
    {
        $payload = $this->payload($message, 4_000_000);
        if (! $preparation->context->matches($this->context)
            || $preparation->outcome !== PreparationOutcome::Prepared
            || ! self::validId($preparation->draftId)
            || $preparation->correlationId !== $message->correlationId
            || ! hash_equals($preparation->intentFingerprint(), hash('sha256', $payload))) {
            throw new InvalidArgumentException('Submission preparation does not match the selected grant, generation, mailbox or original message intent.');
        }
        $endpoint = $this->endpoint().'/'.rawurlencode($preparation->draftId);
        $verification = $this->client->requestOnce('GET', $endpoint, [
            'query' => [
                '$select' => 'id,isDraft,from,toRecipients,ccRecipients,bccRecipients,subject,body,internetMessageHeaders,hasAttachments',
                '$expand' => 'attachments($select=id)',
            ],
            'headers' => ['Prefer' => 'IdType="ImmutableId", outlook.body-content-type="'.($message->htmlBody === null ? 'text' : 'html').'"'],
        ], $this->context->mailbox, maxResponseBytes: 4_194_304);
        $draft = json_decode($verification->body() ?? '', true);
        if ($verification->status !== 200 || ! is_array($draft) || ! $this->matchesDraft($draft, $message, $preparation->draftId)) {
            throw new ApiRequestException('The Graph draft could not be verified against the prepared intent; no send was attempted.', status: $verification->status);
        }
        $response = $this->client->requestOnce('POST', $endpoint.'/send', [
            'headers' => ['Prefer' => 'IdType="ImmutableId"', 'Content-Length' => '0'],
        ], $this->context->mailbox);
        $outcome = $response->status === 202 && $response->body() === '' ? SubmissionOutcome::Accepted : SubmissionOutcome::Uncertain;
        if (self::rejected($response->status)) {
            $outcome = SubmissionOutcome::Rejected;
        }

        return new SubmissionResult('ms-graph', $outcome, $preparation->correlationId, $response->status, $response->headers);
    }

    private function payload(PreparedMessage $message, int $maxRequestBytes): string
    {
        if ($maxRequestBytes < 1) {
            throw new InvalidArgumentException('The submission byte budget must be positive.');
        }
        if ($message->files !== [] || ($message->textBody !== null && $message->htmlBody !== null)) {
            throw new UnsupportedCapabilityException('Graph draft submission supports one text or HTML body without files.');
        }
        PreparedMessageMime::validate($message);
        $headers = [];
        foreach ($message->headers as $name => $value) {
            if (! str_starts_with(strtolower($name), 'x-')) {
                throw new UnsupportedCapabilityException('Graph draft submission supports only custom X- headers.');
            }
            $headers[] = ['name' => $name, 'value' => $value];
        }
        $headers[] = ['name' => 'X-Mailbox-Correlation-Id', 'value' => $message->correlationId];
        $payload = json_encode([
            'from' => self::recipient($message->sender),
            'toRecipients' => array_map(self::recipient(...), $message->to),
            'ccRecipients' => array_map(self::recipient(...), $message->cc),
            'bccRecipients' => array_map(self::recipient(...), $message->bcc),
            'subject' => $message->subject,
            'body' => ['contentType' => $message->htmlBody === null ? 'Text' : 'HTML', 'content' => $message->htmlBody ?? $message->textBody],
            'internetMessageHeaders' => $headers,
        ], JSON_THROW_ON_ERROR);
        if (strlen($payload) > $maxRequestBytes || strlen($payload) >= 4_000_000) {
            throw new UnsupportedCapabilityException('The Graph JSON request exceeds the caller budget or the 4 MB write limit.');
        }

        return $payload;
    }

    /** @return array{emailAddress: array{name: string, address: string}} */
    private static function recipient(EmailAddressDto $address): array
    {
        return ['emailAddress' => ['name' => $address->name, 'address' => $address->address]];
    }

    /** @param array<string, mixed> $draft */
    private function matchesDraft(array $draft, PreparedMessage $message, string $draftId): bool
    {
        if (($draft['id'] ?? null) !== $draftId || ($draft['isDraft'] ?? null) !== true
            || ($draft['hasAttachments'] ?? null) !== false || ($draft['attachments'] ?? null) !== []
            || array_key_exists('attachments@odata.nextLink', $draft)) {
            return false;
        }
        if (($draft['subject'] ?? null) !== $message->subject
            || self::addresses([$draft['from'] ?? null]) !== self::addresses([self::recipient($message->sender)])) {
            return false;
        }
        foreach (['toRecipients' => $message->to, 'ccRecipients' => $message->cc, 'bccRecipients' => $message->bcc] as $field => $recipients) {
            if (self::addresses($draft[$field] ?? null) !== self::addresses(array_map(self::recipient(...), $recipients))) {
                return false;
            }
        }
        $body = $draft['body'] ?? null;
        if (! is_array($body) || ! is_string($body['contentType'] ?? null)
            || strtolower($body['contentType']) !== ($message->htmlBody === null ? 'text' : 'html')
            || ! is_string($body['content'] ?? null)
            || str_replace("\r\n", "\n", $body['content']) !== str_replace("\r\n", "\n", $message->htmlBody ?? $message->textBody ?? '')) {
            return false;
        }
        if (! is_array($draft['internetMessageHeaders'] ?? null) || ! array_is_list($draft['internetMessageHeaders'])) {
            return false;
        }
        foreach ([...$message->headers, 'X-Mailbox-Correlation-Id' => $message->correlationId] as $name => $value) {
            $matches = array_filter($draft['internetMessageHeaders'], static fn (mixed $header): bool => is_array($header)
                && is_string($header['name'] ?? null) && strtolower($header['name']) === strtolower($name));
            if (count($matches) !== 1 || (array_values($matches)[0]['value'] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array{name: string, address: string}>|null */
    private static function addresses(mixed $recipients): ?array
    {
        if (! is_array($recipients) || ! array_is_list($recipients)) {
            return null;
        }
        $addresses = [];
        foreach ($recipients as $recipient) {
            $email = is_array($recipient) ? ($recipient['emailAddress'] ?? null) : null;
            if (! is_array($email) || ! is_string($email['address'] ?? null) || ! is_string($email['name'] ?? '')) {
                return null;
            }
            $separator = strrpos($email['address'], '@');
            if ($separator === false) {
                return null;
            }
            $addresses[] = ['name' => $email['name'] ?? '', 'address' => substr($email['address'], 0, $separator + 1).strtolower(substr($email['address'], $separator + 1))];
        }
        sort($addresses);

        return $addresses;
    }

    private function endpoint(): string
    {
        return 'users/'.rawurlencode($this->context->mailbox).'/messages';
    }

    private static function validId(mixed $id): bool
    {
        return is_string($id) && preg_match('/\A[A-Za-z0-9_+=\/-]{1,2048}\z/', $id) === 1;
    }

    private static function rejected(?int $status): bool
    {
        return in_array($status, [400, 401, 403, 404, 405, 409, 410, 411, 412, 413, 414, 415, 422, 429], true);
    }
}
