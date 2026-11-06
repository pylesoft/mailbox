<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use ZBateson\MailMimeParser\MailMimeParser;

[$script, $certificate, $privateKey, $receipt] = $argv;
$context = stream_context_create(['ssl' => ['local_cert' => $certificate, 'local_pk' => $privateKey, 'verify_peer' => false]]);
$server = stream_socket_server('tls://127.0.0.1:0', $error, $message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    throw new RuntimeException('The local TLS fixture could not listen.');
}
echo stream_socket_get_name($server, false)."\n";
flush();
$connection = stream_socket_accept($server, 30);
if ($connection === false) {
    throw new RuntimeException('The local TLS fixture received no connection.');
}
stream_set_timeout($connection, 30);
$headers = '';
while (! str_ends_with($headers, "\r\n\r\n")) {
    $byte = fread($connection, 1);
    if ($byte === '' || $byte === false || strlen($headers) >= 16_384) {
        throw new RuntimeException('The local request headers were incomplete.');
    }
    $headers .= $byte;
}
if (! str_starts_with($headers, 'POST /gmail/v1/users/sender%40example.com/messages/send HTTP/')
    || preg_match('/\r\nContent-Length: ([0-9]+)\r\n/i', $headers, $match) !== 1
    || ($length = (int) $match[1]) > 67_108_864) {
    throw new RuntimeException('The local fixture received an unexpected request.');
}
if (stripos($headers, "\r\nExpect: 100-Continue\r\n") !== false) {
    fwrite($connection, "HTTP/1.1 100 Continue\r\n\r\n");
}
$wire = tmpfile();
$remaining = $length;
while ($remaining > 0) {
    $chunk = fread($connection, min(65_536, $remaining));
    if ($chunk === '' || $chunk === false || fwrite($wire, $chunk) !== strlen($chunk)) {
        throw new RuntimeException('The local request body was incomplete.');
    }
    $remaining -= strlen($chunk);
}
rewind($wire);
// Server-side inspection is deliberately outside the sender's measured heap.
$payload = json_decode(stream_get_contents($wire), true, flags: JSON_THROW_ON_ERROR);
$mime = base64_decode(strtr($payload['raw'], '-_', '+/'), true);
if ($mime === false) {
    throw new RuntimeException('The local fixture received invalid base64url.');
}
$parsed = (new MailMimeParser)->parse($mime, false);
$files = [];
foreach ($parsed->getAllAttachmentParts() as $part) {
    $hash = hash_init('sha256');
    $bytes = 0;
    $content = $part->getContentStream();
    while (! $content->eof()) {
        $chunk = $content->read(8192);
        $bytes += strlen($chunk);
        hash_update($hash, $chunk);
    }
    $content->close();
    $files[] = ['name' => $part->getFilename(), 'type' => $part->getContentType(), 'bytes' => $bytes, 'sha256' => hash_final($hash)];
}
file_put_contents($receipt, json_encode(['wireBytes' => $length, 'mimeBytes' => strlen($mime), 'files' => $files,
    'threadId' => $payload['threadId'] ?? null,
    'inReplyTo' => $parsed->getHeader('In-Reply-To')?->getIds(),
    'references' => $parsed->getHeader('References')?->getIds(),
], JSON_THROW_ON_ERROR));
$acknowledgement = ['id' => 'local-wire-accepted'];
if (isset($payload['threadId'])) {
    $acknowledgement['threadId'] = $payload['threadId'];
}
$response = json_encode($acknowledgement, JSON_THROW_ON_ERROR);
fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($response)."\r\nConnection: close\r\n\r\n".$response);
fclose($connection);
fclose($server);
fclose($wire);
