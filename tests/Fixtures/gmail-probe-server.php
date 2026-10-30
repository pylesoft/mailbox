<?php

declare(strict_types=1);

$server = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
if ($server === false) {
    throw new RuntimeException('The local Gmail probe fixture could not listen.');
}
echo stream_socket_get_name($server, false)."\n";
flush();

foreach (['POST /token ', 'GET /gmail/v1/users/probe%40example.com/labels/INBOX '] as $expected) {
    $connection = stream_socket_accept($server, 10);
    if ($connection === false) {
        throw new RuntimeException('The local Gmail probe fixture received no request.');
    }
    stream_set_timeout($connection, 5);
    $headers = '';
    while (! str_ends_with($headers, "\r\n\r\n")) {
        $byte = fread($connection, 1);
        if ($byte === false || $byte === '' || strlen($headers) > 16384) {
            throw new RuntimeException('The local probe headers were incomplete.');
        }
        $headers .= $byte;
    }
    if (! str_starts_with($headers, $expected)) {
        throw new RuntimeException('The local probe request targeted an unexpected resource.');
    }
    $remaining = preg_match('/\r\nContent-Length: ([0-9]+)\r\n/i', $headers, $match) === 1 ? (int) $match[1] : 0;
    if ($remaining > 16384) {
        throw new RuntimeException('The local probe request exceeded its fixture limit.');
    }
    if (stripos($headers, "\r\nExpect: 100-Continue\r\n") !== false) {
        fwrite($connection, "HTTP/1.1 100 Continue\r\n\r\n");
    }
    while ($remaining > 0) {
        $chunk = fread($connection, min(8192, $remaining));
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('The local probe body was incomplete.');
        }
        $remaining -= strlen($chunk);
    }
    $body = str_starts_with($expected, 'POST')
        ? '{"access_token":"synthetic-probe-token","expires_in":3600}'
        : '{"id":"INBOX","name":"INBOX"}';
    if (str_starts_with($expected, 'GET') && stripos($headers, "\r\nAuthorization: Bearer synthetic-probe-token\r\n") === false) {
        throw new RuntimeException('The configured Gmail probe did not authenticate.');
    }
    fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body);
    fclose($connection);
}
fclose($server);
