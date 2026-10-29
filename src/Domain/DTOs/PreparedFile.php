<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use Closure;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use Pyle\Mailbox\Exceptions\MailboxException;
use Throwable;

final readonly class PreparedFile
{
    /** @param Closure(): StreamInterface $source A fresh readable stream, owned by the SDK after opening. */
    public function __construct(
        public string $identity,
        public string $name,
        public string $mediaType,
        public int $byteLength,
        #[\SensitiveParameter] private Closure $source,
        #[\SensitiveParameter] private string $sha256,
        public bool $inline = false,
        public ?string $contentId = null,
    ) {
        foreach ([$identity, $name] as $value) {
            if (trim($value) === '' || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                throw new InvalidArgumentException('Prepared file identities and names require nonempty safe UTF-8 text.');
            }
        }
        if ($byteLength < 0 || preg_match('/\A[A-Za-z0-9!#$&^_.+-]+\/[A-Za-z0-9!#$&^_.+-]+\z/', $mediaType) !== 1
            || preg_match('/\A[0-9a-fA-F]{64}\z/', $sha256) !== 1) {
            throw new InvalidArgumentException('Prepared files require a byte length, media type and expected SHA-256 fingerprint.');
        }
        if ($contentId !== null && (strlen($contentId) > 254
            || preg_match('/\A[A-Za-z0-9.!#$%&\x27*+\/=?^_`{|}~-]+@[A-Za-z0-9.-]+\z/', $contentId) !== 1)) {
            throw new InvalidArgumentException('Prepared file Content-IDs must be safe identifiers.');
        }
    }

    public function fingerprint(): string
    {
        return strtolower($this->sha256);
    }

    public function openVerified(): StreamInterface
    {
        $source = null;
        $snapshot = null;
        try {
            $source = ($this->source)();
            if (! $source instanceof StreamInterface || ! $source->isReadable()) {
                throw new MailboxException('The prepared file source is unreadable.');
            }
            if ($source->isSeekable()) {
                $source->rewind();
            }
            $snapshot = tmpfile();
            if ($snapshot === false) {
                throw new MailboxException('The prepared file snapshot could not be created.');
            }
            $hash = hash_init('sha256');
            $length = 0;
            while (! $source->eof()) {
                $chunk = $source->read(min(8192, $this->byteLength - $length + 1));
                if (($chunk === '' && ! $source->eof()) || strlen($chunk) > $this->byteLength - $length) {
                    throw new MailboxException('The prepared file source has an invalid byte length or interrupted read.');
                }
                $length += strlen($chunk);
                hash_update($hash, $chunk);
                if ($chunk !== '' && fwrite($snapshot, $chunk) !== strlen($chunk)) {
                    throw new MailboxException('The prepared file snapshot could not be written.');
                }
            }
            if ($length !== $this->byteLength || ! hash_equals($this->fingerprint(), hash_final($hash))) {
                throw new MailboxException('The prepared file bytes do not match their immutable binding.');
            }
            rewind($snapshot);

            return Utils::streamFor($snapshot);
        } catch (Throwable) {
            if (is_resource($snapshot)) {
                fclose($snapshot);
            }
            throw new MailboxException('The prepared file could not be read and verified.');
        } finally {
            if ($source instanceof StreamInterface) {
                try {
                    $source->close();
                } catch (Throwable) {
                    // A source cleanup error cannot disclose private storage details.
                }
            }
        }
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['identity' => $this->identity, 'name' => $this->name, 'mediaType' => $this->mediaType,
            'byteLength' => $this->byteLength, 'inline' => $this->inline, 'contentId' => $this->contentId];
    }
}
