<?php

declare(strict_types=1);

use Pyle\Mailbox\Drivers\Gmail\GmailDriver;
use Symfony\Component\Process\Process;

it('keeps synthetic inline service account credentials out of successful public Gmail probes', function (): void {
    $server = new Process([PHP_BINARY, dirname(__DIR__).'/Fixtures/gmail-probe-server.php']);
    $server->setOptions(['create_new_console' => false]);
    $server->setTimeout(20);
    $server->start();
    $pid = $server->getPid();

    try {
        $server->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "\n"));
        $address = trim($server->getOutput());
        expect($address)->toMatch('/\A127\.0\.0\.1:[0-9]+\z/');
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        expect($key)->not->toBeFalse();
        openssl_pkey_export($key, $privateKey);
        $json = json_encode(['client_email' => 'synthetic-service@example.com', 'private_key' => $privateKey,
            'token_uri' => 'http://'.$address.'/token', 'credential_path' => '/synthetic/private-key.json'], JSON_THROW_ON_ERROR);
        $driver = new GmailDriver(['service_account_json' => $json, 'api_base_uri' => 'http://'.$address.'/gmail/v1/', 'timeout' => 5]);

        $result = $driver->testConnection('probe@example.com');

        expect($result->success)->toBeTrue()->and($result->accessibleMailboxes)->toBe(['probe@example.com']);
        foreach ([json_encode($result->toArray(), JSON_THROW_ON_ERROR), json_encode($result, JSON_THROW_ON_ERROR), print_r($result, true)] as $output) {
            foreach (['private_key', explode("\n", $privateKey)[1], $json, 'synthetic-probe-token', '/synthetic/private-key.json'] as $secret) {
                expect($output)->not->toContain($secret);
            }
        }
        expect($result->authenticatedAs)->toBe('probe@example.com');
        $server->wait();
        expect($server->getExitCode())->toBe(0);
    } finally {
        if ($server->isRunning()) {
            expect($server->getPid())->toBe($pid);
            $server->stop(0.1);
        }
    }
});
