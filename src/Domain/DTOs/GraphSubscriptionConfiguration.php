<?php

declare(strict_types=1);

namespace Pyle\Mailbox\DTOs;

use InvalidArgumentException;
use Pyle\Mailbox\Exceptions\UnsupportedCapabilityException;

final readonly class GraphSubscriptionConfiguration
{
    public function __construct(
        public string $tenantId,
        public string $providerMailboxId,
        public string $notificationUrl,
        public string $lifecycleNotificationUrl,
        #[\SensitiveParameter] private string $clientState,
        public ?string $folderId = null,
        bool $includeResourceData = false,
        #[\SensitiveParameter] ?string $encryptionCertificate = null,
        #[\SensitiveParameter] ?string $encryptionCertificateId = null,
    ) {
        if ($includeResourceData || $encryptionCertificate !== null || $encryptionCertificateId !== null) {
            throw new UnsupportedCapabilityException('Graph subscriptions support basic notifications only; rich mode and certificate/decryption management are unsupported.');
        }
        foreach ([$tenantId, $providerMailboxId] as $identity) {
            if ($identity === '' || preg_match('/[\x00-\x20\x7f\/\\\\?#%@]/', $identity) || in_array($identity, ['.', '..'], true)) {
                throw new InvalidArgumentException('Graph subscriptions require trusted tenant and canonical mailbox identities.');
            }
        }
        foreach ([$notificationUrl, $lifecycleNotificationUrl] as $url) {
            $parts = parse_url($url);
            if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
                throw new InvalidArgumentException('Graph subscription endpoints require trusted HTTPS URLs.');
            }
        }
        if ($clientState === '' || strlen($clientState) > 128 || preg_match('/[\x00-\x1f\x7f]/', $clientState)
            || ($folderId !== null && (trim($folderId) === '' || preg_match('/[\x00-\x1f\x7f]/', $folderId)))) {
            throw new InvalidArgumentException('Graph subscriptions require a client-state secret and a valid optional folder identity.');
        }
    }

    public function clientState(): string
    {
        return $this->clientState;
    }

    public function resource(GrantContext $context): string
    {
        return 'users/'.rawurlencode($context->mailbox)
            .($this->folderId === null ? '' : '/mailFolders/'.rawurlencode($this->folderId)).'/messages';
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['tenantId' => $this->tenantId, 'providerMailboxId' => $this->providerMailboxId, 'folderId' => $this->folderId];
    }
}
