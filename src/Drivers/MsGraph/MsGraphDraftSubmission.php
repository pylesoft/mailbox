<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Drivers\MsGraph;

use GuzzleHttp\Psr7\LimitStream;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use Pyle\Mailbox\DTOs\AttachmentPreparation;
use Pyle\Mailbox\DTOs\EmailAddressDto;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\GraphUploadSession;
use Pyle\Mailbox\DTOs\PreparedFile;
use Pyle\Mailbox\DTOs\PreparedMessage;
use Pyle\Mailbox\DTOs\SubmissionEvidence;
use Pyle\Mailbox\DTOs\SubmissionLimits;
use Pyle\Mailbox\DTOs\SubmissionPreparation;
use Pyle\Mailbox\DTOs\SubmissionResult;
use Pyle\Mailbox\Enums\PreparationOutcome;
use Pyle\Mailbox\Enums\ReplyForwardOperation;
use Pyle\Mailbox\Enums\SubmissionEvidenceOutcome;
use Pyle\Mailbox\Enums\SubmissionOutcome;
use Pyle\Mailbox\Exceptions\ApiRequestException;
use Pyle\Mailbox\Exceptions\GrantAccessDeniedException;
use Pyle\Mailbox\Exceptions\GrantRefreshException;
use Pyle\Mailbox\Exceptions\MailboxException;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;
use Pyle\Mailbox\Support\PreparedMessageMime;
use Pyle\Mailbox\Support\ProviderWriteResponse;
use Pyle\Mailbox\Support\ResponseBudget;
use Pyle\Mailbox\Support\SubmissionBody;

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
        $outcome = $response->status === 201 && $draftId !== null
            ? ($message->files === [] ? PreparationOutcome::Prepared : PreparationOutcome::Preparing) : PreparationOutcome::Uncertain;
        if (self::rejected($response->status)) {
            $outcome = PreparationOutcome::Rejected;
        }

        return new SubmissionPreparation(
            $this->context, $outcome, $message->correlationId,
            in_array($outcome, [PreparationOutcome::Prepared, PreparationOutcome::Preparing], true) ? $draftId : null,
            $response->status, $response->headers, $this->fingerprint($message, $payload),
        );
    }

    public function prepareAttachments(SubmissionPreparation $preparation, PreparedMessage $message, SubmissionLimits $limits, int $maxRequestBytes): SubmissionPreparation
    {
        (new ResponseBudget($maxRequestBytes, $limits->maxMemoryBytes))->assertMemoryAvailable(65_536);
        $draftId = $this->assertIntent($preparation, $message);
        if ($preparation->outcome !== PreparationOutcome::Preparing) {
            throw new InvalidArgumentException('Attachment preparation requires an acknowledged incomplete draft.');
        }
        $remaining = $limits->maxTotalFileBytes;
        foreach ($message->files as $file) {
            if ($file->byteLength > min(150_000_000, $limits->maxFileBytes, $remaining)) {
                throw new UnsupportedCapabilityException('The prepared files exceed the caller file byte limits.');
            }
            $remaining -= $file->byteLength;
        }
        $index = $this->attachmentIndex($preparation, $message);
        $file = $message->files[$index] ?? null;
        if ($file === null) {
            throw new InvalidArgumentException('Attachment preparation requires an unfinished selected file.');
        }
        $progress = $preparation->attachments[$index] ?? null;
        if ($file->byteLength >= 3_000_000) {
            $next = $this->largeFileStep($draftId, $file, $progress, $limits, $maxRequestBytes);

            return $this->withAttachment($preparation, $message, $index, $next);
        }
        $body = $this->smallFilePayload($file, $limits, $maxRequestBytes);
        try {
            $response = $this->client->requestOnce('POST', $this->endpoint().'/'.rawurlencode($draftId).'/attachments', [
                'body' => $body, 'headers' => ['Content-Type' => 'application/json', 'Prefer' => 'IdType="ImmutableId"'],
            ], $this->context->mailbox);
        } finally {
            $body->close();
        }
        $data = json_decode($response->body() ?? '');
        $attachmentId = $response->status === 201 && $data instanceof \stdClass && self::validId($data->id ?? null) ? $data->id : null;
        $outcome = $attachmentId !== null ? PreparationOutcome::Prepared
            : (self::rejected($response->status) ? PreparationOutcome::Rejected : PreparationOutcome::Uncertain);

        return $this->withAttachment($preparation, $message, $index, new AttachmentPreparation($file->identity, $outcome,
            $attachmentId === null ? 0 : $file->byteLength, $attachmentId, $response->status, $response->headers));
    }

    private function withAttachment(SubmissionPreparation $preparation, PreparedMessage $message, int $index, AttachmentPreparation $next): SubmissionPreparation
    {
        $attachments = $preparation->attachments;
        $attachments[$index] = $next;

        return new SubmissionPreparation($preparation->context,
            $next->outcome === PreparationOutcome::Prepared && count($attachments) < count($message->files) ? PreparationOutcome::Preparing : $next->outcome,
            $preparation->correlationId, $preparation->draftId, $next->status, $next->headers,
            $preparation->intentFingerprint(), $preparation->conversationId, array_values($attachments));
    }

    private function attachmentIndex(SubmissionPreparation $preparation, PreparedMessage $message): int
    {
        $attachments = $this->attachmentProgress($preparation->attachments);
        if (count($attachments) > count($message->files)) {
            throw new InvalidArgumentException('The attachment progress has an invalid file manifest.');
        }
        $ids = [];
        foreach ($attachments as $index => $progress) {
            $file = $message->files[$index];
            if ($progress->fileIdentity !== $file->identity
                || $progress->acknowledgedBytes < 0 || $progress->acknowledgedBytes > $file->byteLength
                || ($progress->session() !== null && ! $progress->session()->matches($this->context, $preparation->draftId ?? '', $file))) {
                throw new InvalidArgumentException('The attachment progress does not match its original immutable binding.');
            }
            if ($progress->outcome !== PreparationOutcome::Prepared) {
                if ($index !== count($attachments) - 1 || $progress->attachmentId !== null) {
                    throw new InvalidArgumentException('Unfinished attachment work cannot precede another attachment.');
                }

                return $index;
            }
            if ($progress->acknowledgedBytes !== $file->byteLength || ! self::validId($progress->attachmentId)
                || in_array($progress->attachmentId, $ids, true)) {
                throw new InvalidArgumentException('Completed attachment progress requires a unique acknowledged identity and exact byte length.');
            }
            $ids[] = $progress->attachmentId;
        }

        return count($attachments);
    }

    /** @return list<AttachmentPreparation> */
    private function attachmentProgress(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('The attachment progress requires a retained list.');
        }
        $attachments = [];
        foreach ($value as $progress) {
            if (! $progress instanceof AttachmentPreparation) {
                throw new InvalidArgumentException('The attachment progress requires complete retained file state.');
            }
            $attachments[] = $progress;
        }

        return $attachments;
    }

    public function inspectAttachments(SubmissionPreparation $preparation, PreparedMessage $message, SubmissionLimits $limits): SubmissionPreparation
    {
        (new ResponseBudget(4_194_304, $limits->maxMemoryBytes))->assertMemoryAvailable(65_536);
        $draftId = $this->assertIntent($preparation, $message);
        $index = $this->attachmentIndex($preparation, $message);
        $progress = $preparation->attachments[$index] ?? null;
        if (! in_array($preparation->outcome, [PreparationOutcome::Uncertain, PreparationOutcome::Expired], true)
            || $progress === null || ! in_array($progress->outcome, [PreparationOutcome::Uncertain, PreparationOutcome::Expired], true)) {
            throw new InvalidArgumentException('Inspection requires retained uncertain attachment work on the original draft.');
        }
        $file = $message->files[$index];
        $source = $file->openVerified();
        $source->close();
        $response = $this->observe($draftId, $message);
        $draft = json_decode($response->body() ?? '');
        if ($response->status !== 200 || ! $draft instanceof \stdClass || ! $this->matchesDraft(get_object_vars($draft), $message, $draftId)
            || ($preparation->conversationId !== null && ($draft->conversationId ?? null) !== $preparation->conversationId)) {
            return $preparation;
        }
        $knownIds = array_column($preparation->attachments, 'attachmentId');
        $candidates = array_values(array_filter($draft->attachments, fn (mixed $attachment): bool => $attachment instanceof \stdClass
            && self::validId($attachment->id ?? null) && ! in_array($attachment->id, $knownIds, true)
            && $this->attachmentMetadataMatches($attachment, $file)));
        if (count($candidates) !== 1 || ! $this->attachmentBytesMatch($draftId, $candidates[0]->id, $file, $limits)) {
            return $preparation;
        }

        return $this->withAttachment($preparation, $message, $index,
            new AttachmentPreparation($file->identity, PreparationOutcome::Prepared, $file->byteLength,
                $candidates[0]->id, $response->status, $response->headers, $progress->session()));
    }

    private function largeFileStep(string $draftId, PreparedFile $file, ?AttachmentPreparation $progress, SubmissionLimits $limits, int $maxRequestBytes): AttachmentPreparation
    {
        $source = $file->openVerified();
        try {
            $session = $progress?->session();
            if ($progress === null) {
                $payload = json_encode(['AttachmentItem' => ['attachmentType' => 'file', 'name' => $file->name,
                    'size' => $file->byteLength, 'contentType' => $file->mediaType, 'isInline' => $file->inline,
                    'contentId' => $file->contentId]], JSON_THROW_ON_ERROR);
                if (strlen($payload) > $maxRequestBytes || strlen($payload) >= 4_000_000) {
                    throw new UnsupportedCapabilityException('The upload session request exceeds its write byte limit.');
                }
                $response = $this->client->requestOnce('POST', $this->endpoint().'/'.rawurlencode($draftId).'/attachments/createUploadSession', [
                    'body' => $payload, 'headers' => ['Content-Type' => 'application/json', 'Prefer' => 'IdType="ImmutableId"'],
                ], $this->context->mailbox);
                $data = json_decode($response->body() ?? '');
                if ($response->status === 201 && $data instanceof \stdClass && is_string($data->uploadUrl ?? null)
                    && is_string($data->expirationDateTime ?? null) && in_array($data->nextExpectedRanges ?? null, [['0'], ['0-']], true)) {
                    try {
                        $expiry = $this->sessionExpiry($data->expirationDateTime);
                        if ($expiry !== null) {
                            $session = new GraphUploadSession($this->context, $draftId, $file->identity,
                                $file->byteLength, $file->fingerprint(), $data->uploadUrl, $expiry);
                        }
                    } catch (InvalidArgumentException) {
                        $session = null;
                    }
                }

                return new AttachmentPreparation($file->identity, $session === null
                    ? (self::rejected($response->status) ? PreparationOutcome::Rejected : PreparationOutcome::Uncertain)
                    : ($session->expiresAt <= new \DateTimeImmutable ? PreparationOutcome::Expired : PreparationOutcome::Preparing),
                    0, null, $response->status, $response->headers, $session);
            }
            if ($session === null || ! $session->matches($this->context, $draftId, $file)
                || $progress->acknowledgedBytes < 0 || $progress->acknowledgedBytes >= $file->byteLength) {
                throw new InvalidArgumentException('The file upload progress is missing its original immutable binding.');
            }
            if ($session->expiresAt <= new \DateTimeImmutable) {
                return new AttachmentPreparation($file->identity, PreparationOutcome::Expired,
                    $progress->acknowledgedBytes, null, $progress->status, $progress->headers, $session);
            }
            $length = min(3_000_000, $file->byteLength - $progress->acknowledgedBytes, $maxRequestBytes);
            $budget = new ResponseBudget($length, $limits->maxMemoryBytes);
            $budget->assertMemoryAvailable(65_536);
            $range = new SubmissionBody(new LimitStream($source, $length, $progress->acknowledgedBytes), $budget);
            $response = $this->client->uploadFileRange($session, $progress->acknowledgedBytes, $range, $length);
            $end = $progress->acknowledgedBytes + $length;
            $data = json_decode($response->body() ?? '');
            $attachmentId = $response->status === 201 && $response->body() === '' && $end === $file->byteLength
                ? $session->attachmentId($response->location()) : null;
            $acknowledged = $attachmentId !== null || ($end < $file->byteLength && $response->status === 200 && $data instanceof \stdClass
                && ($data->nextExpectedRanges ?? null) === [(string) $end]
                && $this->sameSessionExpiry($data, $session));
            $outcome = $acknowledged ? ($attachmentId === null ? PreparationOutcome::Preparing : PreparationOutcome::Prepared)
                : (in_array($response->status, [401, 403, 404, 410], true) ? PreparationOutcome::Expired
                    : (self::rejected($response->status) ? PreparationOutcome::Rejected : PreparationOutcome::Uncertain));

            return new AttachmentPreparation($file->identity, $outcome, $acknowledged ? $end : $progress->acknowledgedBytes,
                $attachmentId, $response->status, $response->headers, $session);
        } finally {
            $source->close();
        }
    }

    private function sessionExpiry(string $value): ?\DateTimeImmutable
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|\+00:00)\z/', $value) !== 1
            || date_parse($value)['warning_count'] !== 0 || date_parse($value)['error_count'] !== 0) {
            return null;
        }

        return new \DateTimeImmutable($value);
    }

    private function sameSessionExpiry(\stdClass $data, GraphUploadSession $session): bool
    {
        $value = $data->expirationDateTime ?? $data->ExpirationDateTime ?? null;

        return is_string($value) && $this->sessionExpiry($value) == $session->expiresAt;
    }

    private function smallFilePayload(PreparedFile $file, SubmissionLimits $limits, int $maxRequestBytes): StreamInterface
    {
        $budget = new ResponseBudget(min($maxRequestBytes, 3_999_999), $limits->maxMemoryBytes);
        $budget->assertMemoryAvailable(65_536);
        $source = $file->openVerified();
        $body = null;
        try {
            $temporary = tmpfile();
            if ($temporary === false) {
                throw new MailboxException('The private attachment spool could not be created.');
            }
            $body = Utils::streamFor($temporary);
            $metadata = json_encode(['@odata.type' => '#microsoft.graph.fileAttachment', 'name' => $file->name,
                'contentType' => $file->mediaType, 'isInline' => $file->inline, 'contentId' => $file->contentId], JSON_THROW_ON_ERROR);
            $this->write($body, substr($metadata, 0, -1).',"contentBytes":"', $budget);
            $tail = '';
            while (! $source->eof()) {
                $budget->assertMemoryAvailable(65_536);
                $chunk = $tail.$source->read(8190);
                $length = strlen($chunk) - strlen($chunk) % 3;
                $tail = substr($chunk, $length);
                $this->write($body, base64_encode(substr($chunk, 0, $length)), $budget);
            }
            $this->write($body, base64_encode($tail).'"}', $budget);
            $body->rewind();

            return new SubmissionBody($body, $budget);
        } catch (\Throwable $exception) {
            $body?->close();
            throw $exception;
        } finally {
            $source->close();
        }
    }

    private function write(StreamInterface $stream, string $bytes, ResponseBudget $budget): void
    {
        $budget->consume(strlen($bytes));
        if ($stream->write($bytes) !== strlen($bytes)) {
            throw new MailboxException('The private attachment spool could not be written.');
        }
    }

    public function submit(SubmissionPreparation $preparation, PreparedMessage $message): SubmissionResult
    {
        $draftId = $this->assertIntent($preparation, $message);
        if ($preparation->outcome !== PreparationOutcome::Prepared) {
            throw new InvalidArgumentException('Submission requires a completed preparation.');
        }
        if ($this->attachmentIndex($preparation, $message) !== count($message->files)) {
            throw new InvalidArgumentException('Submission requires every selected file to have complete retained progress.');
        }
        foreach ($message->files as $file) {
            $source = $file->openVerified();
            $source->close();
        }
        $endpoint = $this->endpoint().'/'.rawurlencode($draftId);
        $verification = $this->observe($draftId, $message);
        $draft = json_decode($verification->body() ?? '');
        if ($verification->status !== 200 || ! $draft instanceof \stdClass || ! $this->matchesDraft(get_object_vars($draft), $message, $draftId)
            || ($preparation->conversationId !== null && ($draft->conversationId ?? null) !== $preparation->conversationId)
            || ! $this->filesMatch($draft, $preparation, $message)) {
            throw new ApiRequestException('The Graph draft could not be verified against the prepared intent; no send was attempted.', status: $verification->status, headers: $verification->headers);
        }
        $response = $this->client->requestOnce('POST', $endpoint.'/send', [
            'headers' => ['Prefer' => 'IdType="ImmutableId"', 'Content-Length' => '0'],
        ], $this->context->mailbox);
        $outcome = $response->status === 202 && $response->body() === '' ? SubmissionOutcome::Accepted : SubmissionOutcome::Uncertain;
        if (self::rejected($response->status)) {
            $outcome = SubmissionOutcome::Rejected;
        }

        return new SubmissionResult('ms-graph', $outcome, $preparation->correlationId, $response->status, $response->headers, context: $preparation->context);
    }

    public function inspect(SubmissionPreparation $preparation, PreparedMessage $message): SubmissionEvidence
    {
        $draftId = $this->assertIntent($preparation, $message);
        $response = $this->observe($draftId, $message);
        $copy = json_decode($response->body() ?? '');
        $observed = $response->status === 200 && $copy instanceof \stdClass
            && $this->matchesDraft(get_object_vars($copy), $message, $draftId, false)
            && $this->filesMatch($copy, $preparation, $message)
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
                '$expand' => 'attachments($select=id,name,contentType,size,isInline,microsoft.graph.fileAttachment/contentId)',
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

        return new SubmissionPreparation($this->context, $complete ? ($message->files === [] ? PreparationOutcome::Prepared : PreparationOutcome::Preparing) : PreparationOutcome::Uncertain,
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
        if ($message->files !== []) {
            $payload .= json_encode(array_map(static fn (PreparedFile $file): array => [$file->identity, $file->name,
                $file->mediaType, $file->byteLength, $file->fingerprint(), $file->inline, $file->contentId], $message->files), JSON_THROW_ON_ERROR);
        }
        $context = $message->context;
        if ($context === null) {
            return hash('sha256', $payload);
        }

        $identity = $context->conversation->identity;

        return hash('sha256', $payload.serialize([$context->operation->value, $context->sourceMessageId,
            $context->conversation->conversationId, $identity->provider, get_object_vars($identity->context), $identity->formatVersion,
            $identity->scope->folderId, $identity->scope->importSince?->format('U.u')]));
    }

    private function payload(PreparedMessage $message, int $maxRequestBytes): string
    {
        if ($maxRequestBytes < 1) {
            throw new InvalidArgumentException('The submission byte budget must be positive.');
        }
        if ($message->textBody !== null && $message->htmlBody !== null) {
            throw new UnsupportedCapabilityException('Graph draft submission supports one text or HTML body.');
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
            || ! is_array($draft['attachments'] ?? null) || ! array_is_list($draft['attachments']) || count($draft['attachments']) > 1000
            || ($message->files === [] && (($draft['hasAttachments'] ?? null) !== false || $draft['attachments'] !== []))
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

    private function filesMatch(\stdClass $draft, SubmissionPreparation $preparation, PreparedMessage $message): bool
    {
        if ($message->files === []) {
            return true;
        }
        if ($this->attachmentIndex($preparation, $message) !== count($message->files)
            || count($draft->attachments) !== count($message->files)
            || ($draft->hasAttachments ?? null) !== (count(array_filter($message->files, static fn (PreparedFile $file): bool => ! $file->inline)) > 0)) {
            return false;
        }
        $attachments = [];
        foreach ($draft->attachments as $attachment) {
            if (! $attachment instanceof \stdClass || ! self::validId($attachment->id ?? null) || isset($attachments[$attachment->id])) {
                return false;
            }
            $attachments[$attachment->id] = $attachment;
        }
        foreach ($message->files as $index => $file) {
            $id = $preparation->attachments[$index]->attachmentId;
            if (! is_string($id) || ! isset($attachments[$id]) || ! $this->attachmentMetadataMatches($attachments[$id], $file)
                || ! $this->attachmentBytesMatch($preparation->draftId ?? '', $id, $file, new SubmissionLimits)) {
                return false;
            }
        }

        return true;
    }

    private function attachmentMetadataMatches(\stdClass $attachment, PreparedFile $file): bool
    {
        return ($attachment->{'@odata.type'} ?? null) === '#microsoft.graph.fileAttachment'
            && ($attachment->name ?? null) === $file->name && ($attachment->size ?? null) === $file->byteLength
            && is_string($attachment->contentType ?? null) && strtolower($attachment->contentType) === strtolower($file->mediaType)
            && ($attachment->isInline ?? null) === $file->inline && ($attachment->contentId ?? null) === $file->contentId;
    }

    private function attachmentBytesMatch(string $draftId, string $attachmentId, PreparedFile $file, SubmissionLimits $limits): bool
    {
        try {
            $source = $this->client->streamBounded($this->endpoint().'/'.rawurlencode($draftId).'/attachments/'.rawurlencode($attachmentId).'/$value',
                new ResponseBudget(max(1, $file->byteLength), $limits->maxMemoryBytes), max(1, $file->byteLength), $this->context->mailbox);
            try {
                $hash = hash_init('sha256');
                while (! $source->eof()) {
                    hash_update($hash, $source->read(8192));
                }

                return $source->getSize() === $file->byteLength && hash_equals($file->fingerprint(), hash_final($hash));
            } finally {
                $source->close();
            }
        } catch (GrantAccessDeniedException|GrantRefreshException $exception) {
            throw $exception;
        } catch (MailboxException) {
            return false;
        }
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
