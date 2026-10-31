<?php

declare(strict_types=1);

[$script, $certificate, $privateKey, $receipt, $manifest, $expires] = $argv;
$expected = json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
$context = stream_context_create(['ssl' => ['local_cert' => $certificate, 'local_pk' => $privateKey, 'verify_peer' => false]]);
$server = stream_socket_server('tls://127.0.0.1:0', $error, $message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    throw new RuntimeException('The local Outlook TLS fixture could not listen.');
}
echo stream_socket_get_name($server, false)."\n";
flush();
$files = [];
$ranges = [];
$posts = [];
$index = $offset = 0;
$hash = hash_init('sha256');
while ($index < count($expected)) {
    $connection = stream_socket_accept($server, 30);
    if ($connection === false) {
        throw new RuntimeException('The local Outlook TLS fixture received no range.');
    }
    stream_set_timeout($connection, 30);
    $headers = '';
    while (! str_ends_with($headers, "\r\n\r\n")) {
        $byte = fread($connection, 1);
        if ($byte === '' || $byte === false || strlen($headers) >= 16_384) {
            throw new RuntimeException('The local Outlook range headers were incomplete.');
        }
        $headers .= $byte;
    }
    if ($expected[$index]['bytes'] === 2_999_999) {
        if (! str_starts_with($headers, 'POST /v1.0/users/sender%40example.com/messages/immutable-draft%3D/attachments HTTP/')
            || preg_match('/\r\nHost: graph\.microsoft\.com\r\n/i', $headers) !== 1
            || preg_match('/\r\nAuthorization: Bearer dummy-local-token\r\n/i', $headers) !== 1
            || preg_match('/\r\nCookie:/i', $headers) === 1
            || preg_match('/\r\nContent-Length: ([0-9]+)\r\n/i', $headers, $lengthMatch) !== 1) {
            throw new RuntimeException('The local Graph fixture received an invalid direct attachment target or authority.');
        }
        $length = (int) $lengthMatch[1];
        if ($length <= 4_000_000 || $length > 4_194_304) {
            throw new RuntimeException('The local Graph direct attachment exceeded its fixture wire policy.');
        }
        if (stripos($headers, "\r\nExpect: 100-Continue\r\n") !== false) {
            fwrite($connection, "HTTP/1.1 100 Continue\r\n\r\n");
        }
        $body = '';
        while (strlen($body) < $length) {
            $chunk = fread($connection, min(8192, $length - strlen($body)));
            if ($chunk === '' || $chunk === false) {
                throw new RuntimeException('The local Graph direct attachment body was incomplete.');
            }
            $body .= $chunk;
        }
        $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        if (($payload['@odata.type'] ?? null) !== '#microsoft.graph.fileAttachment'
            || ($payload['name'] ?? null) !== 'source-0.bin'
            || ($payload['contentType'] ?? null) !== 'application/octet-stream'
            || ($payload['isInline'] ?? null) !== false || ! array_key_exists('contentId', $payload) || $payload['contentId'] !== null
            || ! is_string($payload['contentBytes'] ?? null)) {
            throw new RuntimeException('The local Graph direct attachment has different selected metadata.');
        }
        $bytes = base64_decode($payload['contentBytes'], true);
        if (! is_string($bytes) || strlen($bytes) !== $expected[$index]['bytes']
            || ! hash_equals($expected[$index]['sha256'], hash('sha256', $bytes))) {
            throw new RuntimeException('The local Graph direct attachment has different raw bytes.');
        }
        $files[] = ['bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        // This size is one observed provider fixture value, not a raw-length conversion.
        $response = json_encode(['id' => 'wire-file-0=', ...$payload, 'size' => 3_000_181], JSON_THROW_ON_ERROR);
        fwrite($connection, "HTTP/1.1 201 Created\r\nContent-Type: application/json\r\nContent-Length: ".strlen($response)."\r\nConnection: close\r\n\r\n");
        $written = 0;
        while ($written < strlen($response)) {
            $count = fwrite($connection, substr($response, $written, 8192));
            if ($count === false || $count === 0) {
                throw new RuntimeException('The local Graph attachment acknowledgement could not be written completely.');
            }
            $written += $count;
        }
        $posts[] = ['requestBytes' => $length, 'responseBytes' => strlen($response)];
        $index++;
        fclose($connection);

        continue;
    }

    $target = "/api/gv1.0/users('provider-user')/messages('provider-draft')/AttachmentSessions('session-{$index}')?authtoken=dummy-private-session-{$index}";
    if (! str_starts_with($headers, 'PUT '.$target.' HTTP/')
        || preg_match('/\r\nHost: outlook\.office\.com\r\n/i', $headers) !== 1
        || preg_match('/\r\n(?:Authorization|Cookie):/i', $headers) === 1
        || preg_match('/\r\nContent-Length: ([0-9]+)\r\n/i', $headers, $lengthMatch) !== 1
        || preg_match('/\r\nContent-Range: bytes ([0-9]+)-([0-9]+)\/([0-9]+)\r\n/i', $headers, $rangeMatch) !== 1) {
        throw new RuntimeException('The local Outlook fixture received an invalid target, authority or range.');
    }
    $length = (int) $lengthMatch[1];
    if ($length < 1 || $length > 3_000_000 || (int) $rangeMatch[1] !== $offset
        || (int) $rangeMatch[2] !== $offset + $length - 1 || (int) $rangeMatch[3] !== $expected[$index]['bytes']) {
        throw new RuntimeException('The local Outlook fixture received a nonsequential or unbounded range.');
    }
    if (stripos($headers, "\r\nExpect: 100-Continue\r\n") !== false) {
        fwrite($connection, "HTTP/1.1 100 Continue\r\n\r\n");
    }
    $remaining = $length;
    while ($remaining > 0) {
        $chunk = fread($connection, min(8192, $remaining));
        if ($chunk === '' || $chunk === false) {
            throw new RuntimeException('The local Outlook range body was incomplete.');
        }
        hash_update($hash, $chunk);
        $remaining -= strlen($chunk);
    }
    $ranges[] = ['file' => $index, 'start' => $offset, 'bytes' => $length];
    $offset += $length;
    if ($offset === $expected[$index]['bytes']) {
        $fingerprint = hash_final($hash);
        if (! hash_equals($expected[$index]['sha256'], $fingerprint)) {
            throw new RuntimeException('The local Outlook fixture received changed file bytes.');
        }
        $files[] = ['bytes' => $offset, 'sha256' => $fingerprint];
        $location = "https://outlook.office.com/api/gv1.0/users('provider-user')/messages('provider-draft')/Attachments('wire-file-{$index}=')";
        fwrite($connection, "HTTP/1.1 201 Created\r\nLocation: {$location}\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
        $index++;
        $offset = 0;
        $hash = hash_init('sha256');
    } else {
        $body = json_encode(['expirationDateTime' => $expires, 'nextExpectedRanges' => [(string) $offset]], JSON_THROW_ON_ERROR);
        fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body);
    }
    fclose($connection);
}
file_put_contents($receipt, json_encode(['files' => $files, 'ranges' => $ranges, 'posts' => $posts], JSON_THROW_ON_ERROR));
fclose($server);
