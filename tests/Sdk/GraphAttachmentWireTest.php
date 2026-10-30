<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Pyle\Mailbox\DTOs\EmailAddressDto;
use Pyle\Mailbox\DTOs\GrantContext;
use Pyle\Mailbox\DTOs\PreparedFile;
use Pyle\Mailbox\DTOs\PreparedMessage;
use Pyle\Mailbox\DTOs\SubmissionLimits;
use Pyle\Mailbox\Enums\PreparationOutcome;
use Pyle\Mailbox\Facades\Mailbox;
use Pyle\Mailbox\Tests\Support\TestGrantTokenProvider;
use Symfony\Component\Process\Process;

it('records a source bound cold Outlook upload control through real trusted HTTPS and cURL', function (): void {
    graphAttachmentWireCase(1, 3_000_001, control: true);
})->group('graph-upload-wire')->skip(fn (): bool => ! getenv('MAILBOX_GRAPH_UPLOAD_CONTROL_PATH'), 'Run serially with an owned source-bound cold-control receipt.');

it('uploads a cold 10 MiB Graph file with exact bytes through real Outlook TLS within its payload allocation budget', function (): void {
    graphAttachmentWireCase(1, 10_485_760);
})->group('graph-upload-wire')->skip(fn (): bool => ! getenv('MAILBOX_GRAPH_UPLOAD_CONTROL_PATH'), 'Run serially after the source-bound cold control.');

it('uploads a cold 20 MiB aggregate with two exact files through real Outlook TLS within its payload allocation budget', function (): void {
    graphAttachmentWireCase(2, 10_485_760);
})->group('graph-upload-wire')->skip(fn (): bool => ! getenv('MAILBOX_GRAPH_UPLOAD_CONTROL_PATH'), 'Run serially after the source-bound cold control.');

it('bounds the direct peak for a warm 20 MiB aggregate through the same real Outlook TLS path', function (): void {
    graphAttachmentWireCase(2, 10_485_760, warm: true);
})->group('graph-upload-wire')->skip(fn (): bool => ! getenv('MAILBOX_GRAPH_UPLOAD_CONTROL_PATH'), 'Run serially with the same source-bound runtime and fixture.');

function graphAttachmentWireCase(int $fileCount, int $fileBytes, bool $control = false, bool $warm = false): void
{
    $controlPath = getenv('MAILBOX_GRAPH_UPLOAD_CONTROL_PATH');
    $sourcePaths = [dirname(__DIR__, 2).'/composer.lock'];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src')) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $sourcePaths[] = $file->getPathname();
        }
    }
    sort($sourcePaths);
    $runtime = ['php' => PHP_VERSION, 'wordBytes' => PHP_INT_SIZE,
        'source' => hash('sha256', implode('', array_map(static fn (string $path): string => hash_file('sha256', $path), $sourcePaths)))];
    $coldControl = $control ? null : json_decode(file_get_contents($controlPath), true, flags: JSON_THROW_ON_ERROR);
    if ($coldControl !== null) {
        expect($coldControl['runtime'])->toBe($runtime);
    }
    $directory = sys_get_temp_dir().'/mailbox-graph-wire-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $server = null;
    try {
        file_put_contents($directory.'/openssl.cnf', "[req]\ndistinguished_name=dn\n[dn]\n[v3]\nsubjectAltName=DNS:outlook.office.com\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\nextendedKeyUsage=serverAuth\n");
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $directory.'/openssl.cnf']);
        $csr = openssl_csr_new(['commonName' => 'outlook.office.com'], $key, ['digest_alg' => 'sha256', 'config' => $directory.'/openssl.cnf']);
        $certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256', 'config' => $directory.'/openssl.cnf', 'x509_extensions' => 'v3']);
        openssl_pkey_export_to_file($key, $directory.'/key.pem', null, ['config' => $directory.'/openssl.cnf']);
        openssl_x509_export_to_file($certificate, $directory.'/certificate.pem');
        $files = $expected = $opened = [];
        for ($index = 0; $index < $fileCount; $index++) {
            $path = $directory.'/source-'.$index.'.bin';
            $stream = fopen($path, 'wb');
            $remaining = $fileBytes;
            while ($remaining > 0) {
                $length = min(8192, $remaining);
                fwrite($stream, str_repeat(chr(65 + $index), $length));
                $remaining -= $length;
            }
            fclose($stream);
            $fingerprint = hash_file('sha256', $path);
            $files[] = new PreparedFile('source-'.$index, 'source-'.$index.'.bin', 'application/octet-stream', $fileBytes,
                static function () use ($path, &$opened) {
                    $source = Utils::streamFor(fopen($path, 'rb'));
                    $opened[] = $source;

                    return $source;
                }, $fingerprint);
            $expected[] = ['bytes' => $fileBytes, 'sha256' => $fingerprint];
        }
        file_put_contents($directory.'/manifest.json', json_encode($expected, JSON_THROW_ON_ERROR));
        $expires = (new DateTimeImmutable('+1 hour'))->format(DATE_ATOM);
        $server = new Process([PHP_BINARY, dirname(__DIR__).'/Fixtures/graph-upload-server.php', $directory.'/certificate.pem',
            $directory.'/key.pem', $directory.'/receipt.json', $directory.'/manifest.json', $expires]);
        $server->setTimeout(45);
        $server->start();
        $serverPid = $server->getPid();
        $server->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "\n"));
        $address = trim($server->getOutput());
        expect($address)->toMatch('/\A127\.0\.0\.1:[0-9]+\z/');
        $port = substr($address, strrpos($address, ':') + 1);
        $curl = new CurlHandler;
        $sessionCount = 0;
        $metadata = [];
        $handler = HandlerStack::create(static function (RequestInterface $request, array $options) use ($curl, $expires, &$sessionCount, &$metadata) {
            if ($request->getUri()->getHost() === 'outlook.office.com') {
                return $curl($request, $options);
            }
            if ($request->getUri()->getHost() !== 'graph.microsoft.com') {
                throw new RuntimeException('The local Graph fixture rejected an unexpected network target.');
            }
            if (str_ends_with($request->getUri()->getPath(), '/attachments/createUploadSession')) {
                $metadata[] = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR)['AttachmentItem'];
                $index = $sessionCount++;

                return Create::promiseFor(new Response(201, [], json_encode(['uploadUrl' => "https://outlook.office.com/api/gv1.0/users('provider-user')/messages('provider-draft')/AttachmentSessions('session-{$index}')?authtoken=dummy-private-session-{$index}",
                    'expirationDateTime' => $expires, 'nextExpectedRanges' => ['0-']], JSON_THROW_ON_ERROR)));
            }

            return Create::promiseFor(new Response(201, [], '{"id":"immutable-draft="}'));
        });
        $http = new Client(['handler' => $handler, 'verify' => $directory.'/certificate.pem', 'timeout' => 30, 'proxy' => '',
            'curl' => [CURLOPT_CONNECT_TO => ['outlook.office.com:443:127.0.0.1:'.$port], CURLOPT_USERPWD => 'dummy-default-user:dummy-default-secret',
                CURLOPT_URL => 'https://outlook.office.com/forbidden-target', CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => 'unselected-bytes', CURLOPT_READFUNCTION => static fn (): string => 'unselected-bytes']]);
        $tokens = new TestGrantTokenProvider(['grant' => ['connection' => 'connection', 'generation' => '1', 'mailboxes' => ['sender@example.com'], 'token' => 'dummy-local-token']]);
        $mailbox = Mailbox::forGrant(new GrantContext('connection', 'grant', '1', 'ms-graph', 'sender@example.com'), $tokens, $http);
        $message = new PreparedMessage(new EmailAddressDto('Sender', 'sender@example.com'), [new EmailAddressDto('To', 'to@example.com')],
            'Bounded files', 'graph-wire-operation', textBody: 'Chosen text', files: $files);
        $limits = new SubmissionLimits(maxFileBytes: 10_485_760, maxTotalFileBytes: 20_971_520, maxMemoryBytes: 8_388_608);
        if ($warm) {
            $mailbox->prepareSubmission(new PreparedMessage($message->sender, $message->to,
                'Local fixture initialization', 'warm-control', textBody: 'Local fixture only'));
        }
        memory_reset_peak_usage();
        $baseline = memory_get_usage();

        $preparation = $mailbox->prepareSubmission($message);
        for ($step = 0; $preparation->outcome === PreparationOutcome::Preparing && $step < 16; $step++) {
            $preparation = $mailbox->prepareAttachments(unserialize(serialize($preparation)), $message, $limits);
        }

        $peak = memory_get_peak_usage() - $baseline;
        expect($preparation->outcome)->toBe(PreparationOutcome::Prepared)
            ->and($preparation->attachments)->toHaveCount($fileCount)
            ->and($tokens->tokenReads)->toBe(1 + $fileCount + ($warm ? 1 : 0));
        if ($warm) {
            expect($peak)->toBeLessThan(8_388_608);
        } elseif ($coldControl !== null) {
            expect(max(0, $peak - $coldControl['peakGrowth']))->toBeLessThan(8_388_608);
        }
        $server->wait();
        expect($server->getExitCode())->toBe(0);
        $receipt = json_decode(file_get_contents($directory.'/receipt.json'), true, flags: JSON_THROW_ON_ERROR);
        expect($receipt['files'])->toBe($expected);
        foreach ($files as $index => $file) {
            expect($metadata[$index])->toBe(['attachmentType' => 'file', 'name' => $file->name, 'size' => $fileBytes,
                'contentType' => 'application/octet-stream', 'isInline' => false, 'contentId' => null])
                ->and($preparation->attachments[$index]->attachmentId)->toBe('wire-file-'.$index.'=')
                ->and($preparation->attachments[$index]->acknowledgedBytes)->toBe($fileBytes)
                ->and(is_file($directory.'/source-'.$index.'.bin'))->toBeTrue();
        }
        foreach ($opened as $source) {
            expect($source->getSize())->toBeNull();
        }
        $measurement = ['runtime' => $runtime, 'warm' => $warm, 'sourceBytes' => $fileCount * $fileBytes, 'ranges' => $receipt['ranges'],
            'baseline' => $baseline, 'peakGrowth' => $peak, 'payloadGrowthAboveControl' => $coldControl === null ? null : max(0, $peak - $coldControl['peakGrowth'])];
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
