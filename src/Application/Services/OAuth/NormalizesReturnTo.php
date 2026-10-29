<?php

declare(strict_types=1);

namespace Pyle\Mailbox\Services\OAuth;

trait NormalizesReturnTo
{
    private function normalizeReturnTo(?string $value): ?string
    {
        if (! is_string($value) || $value === '' || str_starts_with($value, '//')
            || str_contains($value, '\\') || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            return null;
        }

        if (str_starts_with($value, '/')) {
            return $value;
        }

        $parts = parse_url($value);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower((string) $parts['host']);

        if ($host === '') {
            return null;
        }

        return in_array($host, $this->allowedReturnHosts(), true) ? $value : null;
    }

    /** @return array<int, string> */
    private function allowedReturnHosts(): array
    {
        $configured = config('mailbox.oauth.allowed_return_hosts', []);

        if (! is_array($configured)) {
            return [];
        }

        $hosts = array_values(array_filter(array_map(function (mixed $host): string {
            if (! is_string($host)) {
                return '';
            }

            $normalized = strtolower(trim($host));

            if ($normalized === '') {
                return '';
            }

            if (str_contains($normalized, '://')) {
                $parsedHost = parse_url($normalized, PHP_URL_HOST);

                return is_string($parsedHost) ? strtolower($parsedHost) : '';
            }

            return $normalized;
        }, $configured), static fn (string $host): bool => $host !== ''));

        return array_values(array_unique($hosts));
    }
}
