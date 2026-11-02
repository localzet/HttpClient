<?php

/**
 * @package     Localzet HTTP Client tests
 * @link        https://github.com/localzet/HttpClient
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright © 2016-2026 Localzet Group. All rights reserved.
 * @license     https://www.gnu.org/licenses/agpl-3.0 GNU Affero General Public License v3.0
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as published
 *              by the Free Software Foundation, either version 3 of the License, or
 *              (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

$reservation = stream_socket_server('tcp://127.0.0.1:0', $errno, $errorMessage);
if (!is_resource($reservation)) {
    throw new RuntimeException("Cannot reserve a loopback port: {$errorMessage}");
}
$address = stream_socket_get_name($reservation, false);
fclose($reservation);
$port = (int)substr((string)$address, strrpos((string)$address, ':') + 1);

$pipes = [];
$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/http_fixture.php'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    dirname(__DIR__)
);
if (!is_resource($server)) {
    throw new RuntimeException('Cannot start the loopback HTTP fixture.');
}
fclose($pipes[0]);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

try {
    $deadline = microtime(true) + 5;
    $ready = false;
    while (microtime(true) < $deadline) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errorMessage, 0.1);
        if (is_resource($socket)) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(20_000);
    }
    if (!$ready) {
        throw new RuntimeException('Loopback HTTP fixture did not start.');
    }

    $client = proc_open([PHP_BINARY, __DIR__ . '/integration_client.php', (string)$port], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $clientPipes, dirname(__DIR__));
    if (!is_resource($client)) {
        throw new RuntimeException('Cannot start the HTTP client integration check.');
    }
    fclose($clientPipes[0]);
    $stdout = stream_get_contents($clientPipes[1]);
    $stderr = stream_get_contents($clientPipes[2]);
    fclose($clientPipes[1]);
    fclose($clientPipes[2]);
    $exitCode = proc_close($client);
    if ($exitCode !== 0) {
        throw new RuntimeException(trim((string)$stderr . "\n" . (string)$stdout));
    }
    fwrite(STDOUT, $stdout);
} finally {
    proc_terminate($server);
    foreach ([1, 2] as $index) {
        fclose($pipes[$index]);
    }
    proc_close($server);
}
