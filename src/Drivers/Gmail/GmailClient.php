<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\Gmail;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Pyle\Mailbox\Drivers\Concerns\PerformsApiRequests;
use Pyle\Mailbox\Drivers\MsGraph\RateLimiter;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\PreparedMessage;
use Pyle\Mailbox\DTOs\SubmissionEvidence;
use Pyle\Mailbox\DTOs\SubmissionLimits;
use Pyle\Mailbox\DTOs\SubmissionResult;
use Pyle\Mailbox\Enums\ReplyForwardOperation;
use Pyle\Mailbox\Enums\SubmissionEvidenceOutcome;
use Pyle\Mailbox\Enums\SubmissionOutcome;
use Pyle\Mailbox\Events\AccessDenied;
use Pyle\Mailbox\Events\ApiError;
use Pyle\Mailbox\Events\RateLimitHit;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\AuthenticationException;
use Pyle\Mailbox\Exceptions\MailboxAccessDeniedException;
use Pyle\Mailbox\Exceptions\MailboxException;
use Pyle\Mailbox\Exceptions\ProviderServerException;
use Pyle\Mailbox\Exceptions\RateLimitException;
use Pyle\Mailbox\Exceptions\ResourceNotFoundException;
use Pyle\Mailbox\Exceptions\ResponseLimitExceededException;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;
use Pyle\Mailbox\Support\GrantAuthorization;
use Pyle\Mailbox\Support\PreparedMessageMime;
use Pyle\Mailbox\Support\ReceivedHeaders;
use Pyle\Mailbox\Support\ResponseBudget;

class GmailClient
{
    use PerformsApiRequests {
        resolveTarget as private resolveApiTarget;
    }

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly GmailTokenManager $tokenManager,
        private readonly RateLimiter $rateLimiter,
        ?Client $client = null,
        private readonly ?GrantAuthorization $grantAuthorization = null,
    ) {
        $this->bootApiClient(
            $this->config,
            $client,
            (string) ($this->config['api_base_uri'] ?? 'https://gmail.googleapis.com/gmail/v1/'),
        );
    }

    protected function driverKey(): string
    {
        return 'gmail';
    }

    public function submitMessage(PreparedMessage $message, string $mailbox, int $maxRequestBytes = 1_048_576, ?SubmissionLimits $limits = null, ?GrantContext $context = null): SubmissionResult
    {
        if ($context !== null) {
            ($this->grantAuthorization ?? throw new UnsupportedCapabilityException('Gmail receipt binding requires captured grant authorization.'))->assertActive($context);
        }
        $limits ??= new SubmissionLimits;
        $budget = new ResponseBudget($maxRequestBytes, $limits->maxMemoryBytes);
        try {
            $message = (new GmailSubmissionContext($context, $budget))->apply($message);
            $replyThread = $message->context?->operation === ReplyForwardOperation::Reply ? $message->context->conversation->conversationId : null;
            $payload = PreparedMessageMime::gmailPayload($message, $maxRequestBytes, $limits, $replyThread, $budget);
        } catch (ResponseLimitExceededException) {
            throw new UnsupportedCapabilityException('Gmail context exceeds its memory allocation budget.');
        }
        try {
            $response = $this->requestOnce('POST', 'users/'.rawurlencode($mailbox).'/messages/send', [
                'body' => $payload, 'headers' => ['Content-Type' => 'application/json'],
            ], $mailbox);
        } finally {
            $payload->close();
        }
        $data = json_decode($response->body() ?? '', true);
        $messageId = is_array($data) && is_string($data['id'] ?? null) && preg_match('/\A[A-Za-z0-9_-]{1,512}\z/', $data['id']) === 1 ? $data['id'] : null;
        $threadId = is_array($data) && is_string($data['threadId'] ?? null) && preg_match('/\A[A-Za-z0-9_-]{1,512}\z/', $data['threadId']) === 1 ? $data['threadId'] : null;
        $outcome = $response->status === 200 && $messageId !== null && (! array_key_exists('threadId', $data) || $threadId !== null)
            ? SubmissionOutcome::Accepted
            : SubmissionOutcome::Uncertain;
        if (in_array($response->status, [400, 401, 403, 404, 405, 409, 410, 411, 412, 413, 414, 415, 422, 429], true)) {
            $outcome = SubmissionOutcome::Rejected;
        }
        if ($outcome === SubmissionOutcome::Accepted && $message->context !== null
            && ($threadId === null || ($message->context->operation === ReplyForwardOperation::Reply
                ? $threadId !== $replyThread
                : $threadId === $message->context->conversation->conversationId))) {
            $outcome = SubmissionOutcome::Uncertain;
        }

        return new SubmissionResult('gmail', $outcome, $message->correlationId, $response->status, $response->headers, $messageId, $threadId, $context);
    }

    public function inspectSubmission(SubmissionResult $submission, GrantContext $context): SubmissionEvidence
    {
        if ($submission->provider !== 'gmail' || $submission->context === null || ! $submission->context->matches($context)
            || trim($submission->correlationId) === '' || strlen($submission->correlationId) > 512
            || ! mb_check_encoding($submission->correlationId, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $submission->correlationId) === 1
            || ($submission->messageId !== null && preg_match('/\A[A-Za-z0-9_-]{1,512}\z/', $submission->messageId) !== 1)
            || ($submission->threadId !== null && preg_match('/\A[A-Za-z0-9_-]{1,512}\z/', $submission->threadId) !== 1)) {
            throw new InvalidArgumentException('The Gmail receipt does not match the selected grant, generation, mailbox or original correlation.');
        }
        ($this->grantAuthorization ?? throw new UnsupportedCapabilityException('Gmail inspection requires captured grant authorization.'))->assertActive($context);
        if ($submission->messageId === null) {
            return new SubmissionEvidence('gmail', $submission->context, $submission->correlationId, null,
                SubmissionEvidenceOutcome::Inconclusive, null, conversationId: $submission->threadId);
        }
        $response = $this->requestOnce('GET', 'users/'.rawurlencode($context->mailbox).'/messages/'.rawurlencode($submission->messageId), [
            'query' => ['format' => 'metadata'],
        ], $context->mailbox);
        $copy = json_decode($response->body() ?? '');
        $observed = $response->status === 200 && $copy instanceof \stdClass
            && ($copy->id ?? null) === $submission->messageId
            && is_string($copy->threadId ?? null) && preg_match('/\A[A-Za-z0-9_-]{1,512}\z/', $copy->threadId) === 1
            && ($submission->threadId === null || $copy->threadId === $submission->threadId)
            && is_array($copy->labelIds ?? null) && array_is_list($copy->labelIds) && array_filter($copy->labelIds, 'is_string') === $copy->labelIds
            && in_array('SENT', $copy->labelIds, true) && ! in_array('DRAFT', $copy->labelIds, true)
            && $this->hasCorrelation($copy, $submission->correlationId);

        return new SubmissionEvidence('gmail', $submission->context, $submission->correlationId, $submission->messageId,
            $observed ? SubmissionEvidenceOutcome::SentCopyObserved : SubmissionEvidenceOutcome::Inconclusive,
            $response->status, $response->headers, $submission->threadId ?? ($observed ? $copy->threadId : null));
    }

    private function hasCorrelation(\stdClass $message, string $correlationId): bool
    {
        if (! ($message->payload ?? null) instanceof \stdClass || ! is_array($message->payload->headers ?? null) || ! array_is_list($message->payload->headers)) {
            return false;
        }
        $found = [];
        foreach ($message->payload->headers as $header) {
            if (! $header instanceof \stdClass || ! is_string($header->name ?? null) || ! is_string($header->value ?? null)) {
                return false;
            }
            if (strcasecmp($header->name, 'X-Mailbox-Correlation-Id') === 0) {
                $found[] = $header->value;
            }
        }

        if (count($found) !== 1) {
            return false;
        }
        try {
            return ReceivedHeaders::text($found[0]) === $correlationId;
        } catch (MailboxException) {
            return false;
        }
    }

    protected function providerLabel(): string
    {
        return 'Gmail';
    }

    private function resolveTarget(#[\SensitiveParameter] string $endpoint, ?string $mailbox = null): string
    {
        $target = $this->resolveApiTarget($endpoint, $mailbox);
        $this->assertMailboxTarget($target, $mailbox, allowMe: true);

        return $target;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function buildRequestOptions(array $options, string $mailboxKey): array
    {
        return array_merge($options, [
            'headers' => array_merge($this->defaultHeaders($mailboxKey), (array) ($options['headers'] ?? [])),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function defaultHeaders(string $mailboxKey): array
    {
        return [
            'Authorization' => 'Bearer '.($this->grantAuthorization?->accessToken($mailboxKey) ?? $this->tokenManager->getToken($mailboxKey)),
            'Accept' => 'application/json',
        ];
    }

    protected function mailboxKey(?string $mailbox): string
    {
        return is_string($mailbox) && trim($mailbox) !== ''
            ? strtolower(trim($mailbox))
            : 'global';
    }

    protected function shouldRetryRequest(
        RequestException $e,
        string $method,
        string $endpoint,
        ?string $mailbox,
        string $mailboxKey,
        int $attempt,
        int $durationMs,
        bool &$reauthAttempted,
    ): bool {
        $status = $e->getResponse()?->getStatusCode();

        if ($status === 401 && $this->retryUnauthorizedRequest($method, $endpoint, $mailboxKey, $attempt, $durationMs, $reauthAttempted)) {
            return true;
        }

        if ($status === 429 && $attempt <= $this->maxRetries) {
            return $this->retryRateLimitedRequest($e, $method, $endpoint, $mailboxKey, $attempt, $durationMs);
        }

        if ($status !== null && $status >= 500 && $attempt <= $this->maxRetries) {
            return $this->retryServerErrorRequest($status, $method, $endpoint, $mailboxKey, $attempt, $durationMs);
        }

        $this->logRequestFailureWithoutRetry($method, $endpoint, $mailboxKey, $attempt, $status, $durationMs);

        return false;
    }

    private function retryUnauthorizedRequest(
        string $method,
        string $endpoint,
        string $mailboxKey,
        int $attempt,
        int $durationMs,
        bool &$reauthAttempted,
    ): bool {
        if ($reauthAttempted || $this->grantAuthorization !== null) {
            return false;
        }

        $this->tokenManager->invalidateToken($mailboxKey);
        $reauthAttempted = true;

        $this->logInfo('Gmail request unauthorized; invalidating token and retrying once', [
            'method' => $method,
            'endpoint' => $endpoint,
            'mailbox' => $mailboxKey,
            'attempt' => $attempt,
            'duration_ms' => $durationMs,
        ]);

        return true;
    }

    private function retryRateLimitedRequest(
        RequestException $e,
        string $method,
        string $endpoint,
        string $mailboxKey,
        int $attempt,
        int $durationMs,
    ): bool {
        $retryAfter = $this->retryAfterSeconds($e);

        Event::dispatch(new RateLimitHit(
            driver: 'gmail',
            mailbox: $mailboxKey,
            retryAfter: $retryAfter,
            endpoint: $endpoint,
        ));

        $this->logInfo('Gmail rate limit hit; scheduling retry', [
            'method' => $method,
            'endpoint' => $endpoint,
            'mailbox' => $mailboxKey,
            'attempt' => $attempt,
            'retry_after_seconds' => $retryAfter,
            'duration_ms' => $durationMs,
        ]);

        if ($this->handleQueueRetry($retryAfter, '429 rate limit')) {
            throw new RateLimitException(
                retryAfter: $retryAfter,
                mailbox: $mailboxKey,
                message: sprintf("Rate limited for mailbox '%s'. Queue job released for retry.", $mailboxKey),
            );
        }

        sleep($retryAfter);

        return true;
    }

    private function retryServerErrorRequest(
        int $status,
        string $method,
        string $endpoint,
        string $mailboxKey,
        int $attempt,
        int $durationMs,
    ): bool {
        $backoff = $this->backoffSeconds($attempt);

        $this->logDebug('Gmail server error; applying retry backoff', [
            'method' => $method,
            'endpoint' => $endpoint,
            'mailbox' => $mailboxKey,
            'attempt' => $attempt,
            'status' => $status,
            'backoff_seconds' => $backoff,
            'duration_ms' => $durationMs,
        ]);

        if ($this->handleQueueRetry($backoff, sprintf('%d server error', $status))) {
            throw new ProviderServerException(
                statusCode: $status,
                attemptsExhausted: $attempt,
                message: sprintf('Gmail API returned %d. Queue job released for retry in %d seconds.', $status, $backoff),
            );
        }

        sleep($backoff);

        return true;
    }

    private function logRequestFailureWithoutRetry(
        string $method,
        string $endpoint,
        string $mailboxKey,
        int $attempt,
        ?int $status,
        int $durationMs,
    ): void {
        $this->logInfo('Gmail request failed without retry', [
            'method' => $method,
            'endpoint' => $endpoint,
            'mailbox' => $mailboxKey,
            'attempt' => $attempt,
            'status' => $status,
            'duration_ms' => $durationMs,
        ]);
    }

    protected function wrapUnexpectedThrowable(
        \Throwable $e,
        string $method,
        string $endpoint,
        ?string $mailbox,
        string $mailboxKey,
        int $attempt,
    ): ApiRequestException {
        $this->logInfo('Gmail request failed with unexpected throwable', [
            'method' => $method,
            'endpoint' => $endpoint,
            'mailbox' => $mailboxKey,
            'attempt' => $attempt,
            'error' => $e->getMessage(),
        ]);

        return new ApiRequestException(
            message: sprintf('Gmail request failed for endpoint %s: %s', $endpoint, $e->getMessage()),
            endpoint: $endpoint,
            previous: $e,
        );
    }

    protected function throwMappedException(
        RequestException $e,
        ?string $mailbox,
        string $mailboxKey,
        string $endpoint,
        int $attempt,
    ): never {
        $status = $e->getResponse()?->getStatusCode();

        if ($status === 403) {
            $this->throwAccessDenied($e, $mailboxKey, $endpoint);
        }

        if ($status === 404) {
            $this->throwResourceNotFound($e, $endpoint);
        }

        if ($status === 401) {
            $this->throwAuthenticationFailed($e);
        }

        if ($status === 429) {
            $this->throwRateLimitExceeded($e, $mailboxKey);
        }

        if ($status !== null && $status >= 500) {
            $this->throwServerError($e, $status, $attempt);
        }

        Event::dispatch(new ApiError(
            driver: 'gmail',
            mailbox: $mailboxKey,
            status: $status ?? 0,
            error: $e->getMessage(),
            endpoint: $endpoint,
        ));

        throw new ApiRequestException(
            message: $e->getMessage(),
            status: $status,
            endpoint: $endpoint,
            previous: $e,
        );
    }

    private function throwAccessDenied(RequestException $e, string $mailboxKey, string $endpoint): never
    {
        Event::dispatch(new AccessDenied(driver: 'gmail', mailbox: $mailboxKey, endpoint: $endpoint));

        throw new MailboxAccessDeniedException(
            mailbox: $mailboxKey,
            message: sprintf("Access denied to mailbox '%s'. Ensure domain-wide delegation is configured and mailbox impersonation is allowed.", $mailboxKey),
            guidance: 'Verify Google Workspace Admin domain-wide delegation and Gmail API scopes.',
            previous: $e,
        );
    }

    private function throwResourceNotFound(RequestException $e, string $endpoint): never
    {
        throw new ResourceNotFoundException(
            resourceType: 'resource',
            resourceId: $endpoint,
            message: sprintf("Resource '%s' was not found.", $endpoint),
            previous: $e,
        );
    }

    private function throwAuthenticationFailed(RequestException $e): never
    {
        throw new AuthenticationException(
            'Authentication with Gmail failed after token refresh attempt.',
            'Check service account credentials, delegated scopes, and mailbox subject.',
            previous: $e,
        );
    }

    private function throwRateLimitExceeded(RequestException $e, string $mailboxKey): never
    {
        $retryAfter = $this->retryAfterSeconds($e);

        throw new RateLimitException(
            retryAfter: $retryAfter,
            mailbox: $mailboxKey,
            message: sprintf("Rate limit exceeded for mailbox '%s'. Retry after %d seconds.", $mailboxKey, $retryAfter),
            previous: $e,
        );
    }

    private function throwServerError(RequestException $e, int $status, int $attempt): never
    {
        throw new ProviderServerException(
            statusCode: $status,
            attemptsExhausted: $attempt,
            message: sprintf('Gmail API returned %d after %d attempts.', $status, $attempt),
            previous: $e,
        );
    }
}
