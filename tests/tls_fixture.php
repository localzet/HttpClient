<?php

/**
 * @package     Localzet HTTP Client tests
 * @link        https://github.com/localzet/HttpClient
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright © 2016-2026 Localzet Group. All rights reserved.
 * @license     https://www.gnu.org/licenses/agpl-3.0 GNU Affero General Public License v3.0
 */

declare(strict_types=1);

$port = (int)($argv[1] ?? 0);
$certificate = $argv[2] ?? '';
$privateKey = $argv[3] ?? '';
$readyPath = $argv[4] ?? '';
$context = stream_context_create([
    'ssl' => [
        'local_cert' => $certificate,
        'local_pk' => $privateKey,
        'crypto_method' => STREAM_CRYPTO_METHOD_TLS_SERVER,
    ],
]);

$server = stream_socket_server(
    "tls://127.0.0.1:{$port}",
    $errno,
    $errorMessage,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context
);
if (!is_resource($server)) {
    fwrite(STDERR, "Cannot start TLS fixture: {$errorMessage}\n");
    exit(1);
}
file_put_contents($readyPath, 'ready');

while (true) {
    $connection = @stream_socket_accept($server, 1);
    if (!is_resource($connection)) {
        continue;
    }

    stream_set_timeout($connection, 3);
    $request = '';
    while (!str_contains($request, "\r\n\r\n") && !feof($connection)) {
        $chunk = fread($connection, 4096);
        if (!is_string($chunk) || $chunk === '') {
            break;
        }
        $request .= $chunk;
    }

    if (str_contains($request, "\r\n\r\n")) {
        $body = 'TLS_OK';
        fwrite(
            $connection,
            "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body)
            . "\r\nConnection: close\r\n\r\n{$body}"
        );
    }

    fclose($connection);
}
