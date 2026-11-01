<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Pyle\Mailbox\DTOs\ConversationRead;
use Pyle\Mailbox\DTOs\EmailAddressDto;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\MessageDto;
use Pyle\Mailbox\DTOs\PreparedFile;
use Pyle\Mailbox\DTOs\PreparedMessage;
use Pyle\Mailbox\DTOs\ReplyForwardContext;
use Pyle\Mailbox\DTOs\SubmissionLimits;
use Pyle\Mailbox\DTOs\SyncIdentity;
use Pyle\Mailbox\DTOs\SyncScope;
use Pyle\Mailbox\Enums\ReplyForwardOperation;
use Pyle\Mailbox\Enums\SubmissionOutcome;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Tests\Support\TestGrantTokenProvider;
use Symfony\Component\Process\Process;

it('submits a small file through the local TLS server using the original trusted Gmail HTTPS target', function (): void {
    submissionWireCase(1, 8192, control: true);
})->group('submission-wire')->skip(fn (): bool => ! getenv('MAILBOX_SUBMISSION_CONTROL_PATH'), 'Run serially with an owned cold-control receipt path.');

it('sends a cold 10 MiB target through real TLS cURL within the payload allocation budget above its cold control', function (): void {
    submissionWireCase(1, 10_485_760);
})->group('submission-wire')->skip(fn (): bool => ! getenv('MAILBOX_SUBMISSION_CONTROL_PATH'), 'Run serially after the isolated cold control.');

it('sends a cold 20 MiB aggregate through real TLS cURL within the payload allocation budget above its cold control', function (): void {
    submissionWireCase(2, 10_485_760);
})->group('submission-wire')->skip(fn (): bool => ! getenv('MAILBOX_SUBMISSION_CONTROL_PATH'), 'Run serially after the isolated cold control.');

it('sends a warm 20 MiB attachment-bearing reply through real TLS within its incremental memory budget', function (): void {
    submissionWireCase(1, 8192, control: true);
    submissionWireCase(2, 10_485_760, reply: true, warm: true);
})->group('submission-wire')->skip(fn (): bool => ! getenv('MAILBOX_SUBMISSION_CONTROL_PATH'), 'Run serially with its own owned warm-control receipt path.');

function submissionWireCase(int $fileCount, int $fileBytes, bool $control = false, bool $reply = false, bool $warm = false): void
{
    $controlPath = getenv('MAILBOX_SUBMISSION_CONTROL_PATH');
    $sourcePaths = [dirname(__DIR__, 2).'/composer.lock'];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src')) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $sourcePaths[] = $file->getPathname();
        }
    }
    sort($sourcePaths);
    $sourceHashes = array_map(static fn (string $path): string => hash_file('sha256', $path), $sourcePaths);
    $runtime = ['php' => PHP_VERSION, 'wordBytes' => PHP_INT_SIZE, 'source' => hash('sha256', implode('', $sourceHashes))];
    $coldControl = null;
    if (! $control) {
        $coldControl = json_decode(file_get_contents($controlPath), true, flags: JSON_THROW_ON_ERROR);
        expect($coldControl['runtime'])->toBe($runtime);
    }
    $directory = sys_get_temp_dir().'/mailbox-wire-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $server = null;
    try {
        file_put_contents($directory.'/openssl.cnf', "[req]\ndistinguished_name=dn\n[dn]\n[v3]\nsubjectAltName=DNS:gmail.googleapis.com\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\nextendedKeyUsage=serverAuth\n");
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $directory.'/openssl.cnf']);
        $csr = openssl_csr_new(['commonName' => 'gmail.googleapis.com'], $key, ['digest_alg' => 'sha256', 'config' => $directory.'/openssl.cnf']);
        $certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256', 'config' => $directory.'/openssl.cnf', 'x509_extensions' => 'v3']);
        openssl_pkey_export_to_file($key, $directory.'/key.pem', null, ['config' => $directory.'/openssl.cnf']);
        openssl_x509_export_to_file($certificate, $directory.'/certificate.pem');
        $files = $expected = [];
        for ($index = 0; $index < $fileCount; $index++) {
            $path = $directory.'/source-'.$index.'.bin';
            $stream = fopen($path, 'wb');
            for ($chunk = 0; $chunk < intdiv($fileBytes, 8192); $chunk++) {
                fwrite($stream, str_repeat(chr(65 + $index), 8192));
            }
            fclose($stream);
            $fingerprint = hash_file('sha256', $path);
            $files[] = new PreparedFile('source-'.$index, 'source-'.$index.'.bin', 'application/octet-stream', $fileBytes,
                static fn () => Utils::streamFor(fopen($path, 'rb')), $fingerprint);
            $expected[] = ['name' => 'source-'.$index.'.bin', 'type' => 'application/octet-stream', 'bytes' => $fileBytes, 'sha256' => $fingerprint];
        }
        $server = new Process([PHP_BINARY, '-d', 'memory_limit=512M', dirname(__DIR__).'/Fixtures/gmail-submission-server.php', $directory.'/certificate.pem', $directory.'/key.pem', $directory.'/receipt.json']);
        $server->setTimeout(45);
        $server->start();
        $serverPid = $server->getPid();
        $server->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "\n"));
        $address = trim($server->getOutput());
        expect($address)->toMatch('/\A127\.0\.0\.1:[0-9]+\z/');
        $port = substr($address, strrpos($address, ':') + 1);
        $body = null;
        $uri = null;
        $handler = HandlerStack::create(new CurlHandler);
        $handler->push(Middleware::mapRequest(static function (RequestInterface $request) use (&$body, &$uri): RequestInterface {
            $body = $request->getBody();
            $uri = (string) $request->getUri();

            return $request;
        }));
        $http = new Client(['handler' => $handler, 'verify' => $directory.'/certificate.pem', 'timeout' => 30, 'proxy' => '',
            'curl' => [CURLOPT_CONNECT_TO => ['gmail.googleapis.com:443:127.0.0.1:'.$port]]]);
        $tokens = new TestGrantTokenProvider(['grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['sender@example.com'], 'token' => 'dummy-local-token']]);
        $grant = new GrantContext('connection', 'grant', '1', 'gmail', 'sender@example.com');
        $mailbox = Mailbox::forGrant($grant, $tokens, $http)
            ->withSubmissionLimits(new SubmissionLimits(maxFileBytes: 10_485_760, maxTotalFileBytes: 20_971_520, maxMemoryBytes: 8_388_608));
        $context = null;
        if ($reply) {
            $source = MessageDto::fromGmail(['id' => 'wire-source', 'threadId' => 'wire-thread', 'payload' => [
                'mimeType' => 'text/plain', 'headers' => [
                    ['name' => 'Subject', 'value' => 'Bounded files'],
                    ['name' => 'Message-ID', 'value' => '<wire-source@example.com>'],
                    ['name' => 'References', 'value' => '<wire-ancestor@example.com>'],
                ], 'body' => ['data' => 'Ym9keQ', 'size' => 4],
            ]]);
            $conversation = new ConversationRead(new SyncIdentity('gmail', $grant, new SyncScope), 'wire-thread', [$source], complete: true);
            $context = new ReplyForwardContext(ReplyForwardOperation::Reply, $conversation, 'wire-source');
        }
        $message = new PreparedMessage(new EmailAddressDto('Sender', 'sender@example.com'), [new EmailAddressDto('To', 'to@example.com')], 'Bounded files', 'wire-operation', textBody: 'Chosen text', files: $files, context: $context);
        memory_reset_peak_usage();
        $baseline = memory_get_usage();

        $result = $mailbox->submit($message, maxRequestBytes: 50_331_648);

        $peak = memory_get_peak_usage() - $baseline;
        expect($result->outcome)->toBe(SubmissionOutcome::Accepted)
            ->and($uri)->toBe('https://gmail.googleapis.com/gmail/v1/users/sender%40example.com/messages/send')
            ->and($body->getSize())->toBeNull();
        if ($warm) {
            expect($peak)->toBeLessThan(8_388_608);
        } elseif ($coldControl !== null) {
            expect(max(0, $peak - $coldControl['peakGrowth']))->toBeLessThan(8_388_608);
        }
        $server->wait();
        expect($server->getExitCode())->toBe(0);
        $receipt = json_decode(file_get_contents($directory.'/receipt.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($receipt['files'])->toBe($expected)
            ->and($receipt['mimeBytes'])->toBeLessThanOrEqual(36_700_160)
            ->and($receipt['wireBytes'])->toBeLessThanOrEqual(50_331_648);
        if ($fileCount === 2) {
            expect($receipt['wireBytes'])->toBeGreaterThan(36_700_160);
        }
        if ($reply) {
            expect($receipt['threadId'])->toBe('wire-thread')
                ->and($receipt['inReplyTo'])->toBe(['wire-source@example.com'])
                ->and($receipt['references'])->toBe(['wire-ancestor@example.com', 'wire-source@example.com'])
                ->and($result->threadId)->toBe('wire-thread');
        }
        $measurement = ['runtime' => $runtime, 'sourceBytes' => $fileCount * $fileBytes, 'mimeBytes' => $receipt['mimeBytes'], 'wireBytes' => $receipt['wireBytes'], 'baseline' => $baseline, 'peakGrowth' => $peak, 'warm' => $warm, 'reply' => $reply, 'payloadGrowthAboveControl' => $coldControl === null || $warm ? null : max(0, $peak - $coldControl['peakGrowth'])];
        if ($control) {
            file_put_contents($controlPath, json_encode($measurement, JSON_THROW_ON_ERROR));
        }
        fwrite(STDERR, json_encode($measurement, JSON_THROW_ON_ERROR)."\n");
    } finally {
        if ($server instanceof Process && $server->isRunning()) {
            expect($server->getPid())->toBe($serverPid);
            $server->stop(0.1);
        }
        foreach (glob($directory.'/*') as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
}
