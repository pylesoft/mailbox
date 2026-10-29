<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use InvalidArgumentException;
use Pyle\Mailbox\DTOs\EmailAddressDto;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\PreparedMessage;
use Pyle\Mailbox\DTOs\SubmissionEvidence;
use Pyle\Mailbox\DTOs\SubmissionPreparation;
use Pyle\Mailbox\DTOs\SubmissionResult;
use Pyle\Mailbox\Enums\PreparationOutcome;
use Pyle\Mailbox\Enums\ReplyForwardOperation;
use Pyle\Mailbox\Enums\SubmissionOutcome;
use Pyle\Mailbox\Enums\SubmissionEvidenceOutcome;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;
use Pyle\Mailbox\Support\PreparedMessageMime;
use Pyle\Mailbox\Support\ProviderWriteResponse;

final readonly class MsGraphDraftSubmission
{
    public function __construct(private GraphClient $client, private GrantContext $context) {}

    public function prepare(PreparedMessage $message, int $maxRequestBytes): SubmissionPreparation
    {
        $payload = $this->payload($message, $maxRequestBytes);
        if ($message->context !== null) {
            return $this->prepareNative($message, $payload, $maxRequestBytes);
        }
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
            $response->status, $response->headers, $this->fingerprint($message, $payload),
        );
    }

    public function submit(SubmissionPreparation $preparation, PreparedMessage $message): SubmissionResult
    {
        $draftId = $this->assertIntent($preparation, $message);
        if ($preparation->outcome !== PreparationOutcome::Prepared) {
            throw new InvalidArgumentException('Submission requires a completed preparation.');
        }
        $endpoint = $this->endpoint().'/'.rawurlencode($draftId);
        $verification = $this->observe($draftId, $message);
        $draft = json_decode($verification->body() ?? '');
        if ($verification->status !== 200 || ! $draft instanceof \stdClass || ! $this->matchesDraft(get_object_vars($draft), $message, $draftId)
            || ($preparation->conversationId !== null && ($draft->conversationId ?? null) !== $preparation->conversationId)) {
            throw new ApiRequestException('The Graph draft could not be verified against the prepared intent; no send was attempted.', status: $verification->status, headers: $verification->headers);
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

    public function inspect(SubmissionPreparation $preparation, PreparedMessage $message): SubmissionEvidence
    {
        $draftId = $this->assertIntent($preparation, $message);
        $response = $this->observe($draftId, $message);
        $copy = json_decode($response->body() ?? '');
        $observed = $response->status === 200 && $copy instanceof \stdClass
            && $this->matchesDraft(get_object_vars($copy), $message, $draftId, false)
            && is_string($copy->conversationId ?? null) && trim($copy->conversationId) !== ''
            && ($preparation->conversationId === null || $copy->conversationId === $preparation->conversationId)
            && is_string($copy->sentDateTime ?? null) && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z\z/', $copy->sentDateTime) === 1
            && date_parse($copy->sentDateTime)['warning_count'] === 0 && date_parse($copy->sentDateTime)['error_count'] === 0;

        return new SubmissionEvidence('ms-graph', $preparation->context, $preparation->correlationId, $draftId,
            $observed ? SubmissionEvidenceOutcome::SentCopyObserved : SubmissionEvidenceOutcome::Inconclusive,
            $response->status, $response->headers, $preparation->conversationId ?? ($observed ? $copy->conversationId : null));
    }

    private function assertIntent(SubmissionPreparation $preparation, PreparedMessage $message): string
    {
        $payload = $this->payload($message, 4_000_000);
        if ($message->context !== null) {
            $this->sourceEndpoint($message);
        }
        $draftId = $preparation->draftId;
        if (! $preparation->context->matches($this->context) || ! is_string($draftId) || ! self::validId($draftId)
            || ($message->context !== null && ($preparation->conversationId === null || trim($preparation->conversationId) === ''))
            || $preparation->correlationId !== $message->correlationId
            || ! hash_equals($preparation->intentFingerprint(), $this->fingerprint($message, $payload))) {
            throw new InvalidArgumentException('Submission preparation does not match the selected grant, generation, mailbox or original message intent.');
        }

        return $draftId;
    }

    private function observe(string $draftId, PreparedMessage $message): ProviderWriteResponse
    {
        return $this->client->requestOnce('GET', $this->endpoint().'/'.rawurlencode($draftId), [
            'query' => [
                '$select' => 'id,isDraft,from,toRecipients,ccRecipients,bccRecipients,subject,body,internetMessageHeaders,hasAttachments,conversationId,sentDateTime',
                '$expand' => 'attachments($select=id)',
            ],
            'headers' => ['Prefer' => 'IdType="ImmutableId", outlook.body-content-type="'.($message->htmlBody === null ? 'text' : 'html').'"'],
        ], $this->context->mailbox, maxResponseBytes: 4_194_304);
    }

    private function prepareNative(PreparedMessage $message, string $payload, int $maxRequestBytes): SubmissionPreparation
    {
        $source = $this->sourceEndpoint($message);
        $nativePayload = '{"message":'.$payload.'}';
        if (strlen($nativePayload) > $maxRequestBytes || strlen($nativePayload) >= 4_000_000) {
            throw new UnsupportedCapabilityException('The native Graph request exceeds the caller budget or the 4 MB write limit.');
        }
        $headers = ['Prefer' => 'IdType="ImmutableId"', 'Content-Type' => 'application/json'];
        $verification = $this->client->requestOnce('GET', $source, [
            'query' => ['$select' => 'id,conversationId,parentFolderId,isDraft'], 'headers' => $headers,
        ], $this->context->mailbox);
        $data = json_decode($verification->body() ?? '');
        $context = $message->context;
        if (! $data instanceof \stdClass || $verification->status !== 200
            || ($data->id ?? null) !== $context?->sourceMessageId
            || ($data->conversationId ?? null) !== $context?->conversation->conversationId
            || ($data->isDraft ?? null) !== false) {
            throw new ApiRequestException('The Graph source is unavailable in the selected conversation scope; no draft was created.', status: $verification->status, headers: $verification->headers);
        }
        $operation = $context?->operation === ReplyForwardOperation::Reply ? 'createReply' : 'createForward';
        $response = $this->client->requestOnce('POST', $source.'/'.$operation, ['body' => $nativePayload, 'headers' => $headers], $this->context->mailbox, maxResponseBytes: 4_194_304);
        $draft = json_decode($response->body() ?? '');
        $validDraft = $response->status === 201 && $draft instanceof \stdClass
            && self::validId($draft->id ?? null) && ! in_array($draft->id, array_column($context?->conversation->messages ?? [], 'id'), true)
            && ($draft->isDraft ?? null) === true && is_string($draft->conversationId ?? null) && trim($draft->conversationId) !== ''
            && ($context?->operation !== ReplyForwardOperation::Reply || $draft->conversationId === $context->conversation->conversationId);
        if (! $validDraft) {
            return new SubmissionPreparation($this->context, self::rejected($response->status) ? PreparationOutcome::Rejected : PreparationOutcome::Uncertain,
                $message->correlationId, null, $response->status, $response->headers, $this->fingerprint($message, $payload));
        }
        $draftId = $draft->id;
        $endpoint = $this->endpoint().'/'.rawurlencode($draftId);
        $files = $this->client->requestOnce('GET', $endpoint.'/attachments', [
            'query' => ['$select' => 'id', '$top' => 1000], 'headers' => $headers,
        ], $this->context->mailbox, maxResponseBytes: 4_194_304);
        $attachments = json_decode($files->body() ?? '');
        $complete = $files->status === 200 && $attachments instanceof \stdClass
            && is_array($attachments->value ?? null) && array_is_list($attachments->value) && count($attachments->value) <= 1000 && ! property_exists($attachments, '@odata.nextLink');
        if ($complete) {
            $seen = [];
            foreach ($attachments->value as $file) {
                if (! $file instanceof \stdClass || ! self::validId($file->id ?? null) || in_array($file->id, $seen, true)) {
                    $complete = false;
                    break;
                }
                $seen[] = $file->id;
            }
        }
        $last = $files;
        if ($complete) {
            foreach ($attachments->value as $file) {
                $last = $this->client->requestOnce('DELETE', $endpoint.'/attachments/'.rawurlencode($file->id), ['headers' => $headers], $this->context->mailbox);
                if ($last->status !== 204 || $last->body() !== '') {
                    $complete = false;
                    break;
                }
            }
        }
        if ($complete) {
            $normalized = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            unset($normalized['internetMessageHeaders']);
            $last = $this->client->requestOnce('PATCH', $endpoint, ['json' => $normalized, 'headers' => $headers], $this->context->mailbox, maxResponseBytes: 4_194_304);
            $updated = json_decode($last->body() ?? '');
            $complete = $last->status === 200 && $updated instanceof \stdClass && ($updated->id ?? null) === $draftId && ($updated->isDraft ?? null) === true;
        }

        return new SubmissionPreparation($this->context, $complete ? PreparationOutcome::Prepared : PreparationOutcome::Uncertain,
            $message->correlationId, $draftId, $last->status, $last->headers, $this->fingerprint($message, $payload), $draft->conversationId);
    }

    private function sourceEndpoint(PreparedMessage $message): string
    {
        $context = $message->context;
        if ($context === null || ! $context->conversation->complete || $context->conversation->continuation !== null
            || $context->conversation->identity->provider !== 'ms-graph' || $context->conversation->identity->formatVersion !== 1
            || ! $context->conversation->identity->context->matches($this->context) || ! self::validId($context->sourceMessageId)
            || trim($context->conversation->conversationId) === '') {
            throw new UnsupportedCapabilityException('Native Graph preparation requires complete conversation content bound to the selected grant and source scope.');
        }
        foreach ($context->conversation->messages as $source) {
            if ($source->id === $context->sourceMessageId && $source->conversationId === $context->conversation->conversationId
                && $source->body !== null && ! $source->isDraft) {
                return 'users/'.rawurlencode($this->context->mailbox)
                    .($context->conversation->identity->scope->folderId === null ? '' : '/mailFolders/'.rawurlencode($context->conversation->identity->scope->folderId))
                    .'/messages/'.rawurlencode($context->sourceMessageId);
            }
        }

        throw new UnsupportedCapabilityException('The selected source is absent from the complete scoped conversation.');
    }

    private function fingerprint(PreparedMessage $message, string $payload): string
    {
        $context = $message->context;
        if ($context === null) {
            return hash('sha256', $payload);
        }

        return hash('sha256', $payload.serialize([$context->operation->value, $context->sourceMessageId,
            $context->conversation->conversationId, $context->conversation->identity]));
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
    private function matchesDraft(array $draft, PreparedMessage $message, string $draftId, bool $isDraft = true): bool
    {
        if (($draft['id'] ?? null) !== $draftId || ($draft['isDraft'] ?? null) !== $isDraft
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
        if (! $body instanceof \stdClass || ! is_string($body->contentType ?? null)
            || strtolower($body->contentType) !== ($message->htmlBody === null ? 'text' : 'html')
            || ! is_string($body->content ?? null)
            || str_replace("\r\n", "\n", $body->content) !== str_replace("\r\n", "\n", $message->htmlBody ?? $message->textBody ?? '')) {
            return false;
        }
        if (! is_array($draft['internetMessageHeaders'] ?? null) || ! array_is_list($draft['internetMessageHeaders'])) {
            return false;
        }
        $headers = [];
        foreach ($draft['internetMessageHeaders'] as $header) {
            if (! $header instanceof \stdClass || ! is_string($header->name ?? null) || ! is_string($header->value ?? null)) {
                return false;
            }
            $headers[strtolower($header->name)][] = $header->value;
        }
        foreach ([...$message->headers, 'X-Mailbox-Correlation-Id' => $message->correlationId] as $name => $value) {
            if (($headers[strtolower($name)] ?? null) !== [$value]) {
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
            $recipient = $recipient instanceof \stdClass ? get_object_vars($recipient) : $recipient;
            $email = is_array($recipient) ? ($recipient['emailAddress'] ?? null) : null;
            $email = $email instanceof \stdClass ? get_object_vars($email) : $email;
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
