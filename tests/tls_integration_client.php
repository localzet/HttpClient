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

require dirname(__DIR__) . '/vendor/autoload.php';

use localzet\HTTP\Client;
use localzet\Server;

$port = (int)($argv[1] ?? 0);
$mode = $argv[2] ?? '';
$certificate = $argv[3] ?? '';
$host = $mode === 'reject-hostname' ? '127.0.0.1' : 'localhost';
$options = ['connect_timeout' => 2, 'timeout' => 3];

if ($mode !== 'reject-untrusted') {
    $options['context'] = ['ssl' => ['cafile' => $certificate]];
}

$loop = Server::getEventLoop();
$client = new Client($options);
$response = null;
$error = null;
$client->get(
    "https://{$host}:{$port}/tls-check",
    [],
    static function ($result) use (&$response, $loop): void {
        $response = $result;
        $loop->stop();
    },
    static function (Throwable $exception) use (&$error, $loop): void {
        $error = $exception;
        $loop->stop();
    }
);
$loop->delay(5, static function () use (&$error, $loop): void {
    $error ??= new RuntimeException('TLS integration request timed out.');
    $loop->stop();
});
$loop->run();

if ($mode === 'accept-trusted') {
    if ($error !== null || $response === null || $response->getStatusCode() !== 200 || (string)$response->getBody() !== 'TLS_OK') {
        fwrite(STDERR, 'Trusted TLS certificate was rejected: ' . ($error?->getMessage() ?? 'unexpected response') . PHP_EOL);
        exit(1);
    }
} elseif ($error === null || !preg_match('/certificate|peer name|hostname|tls|ssl|crypto/i', $error->getMessage())) {
    fwrite(STDERR, 'TLS validation did not reject the expected certificate: ' . ($error?->getMessage() ?? 'request succeeded') . PHP_EOL);
    exit(1);
}

echo strtoupper(str_replace('-', '_', $mode)) . "=ok\n";
