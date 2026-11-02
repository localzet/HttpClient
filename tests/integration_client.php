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

require dirname(__DIR__) . '/vendor/autoload.php';

use localzet\HTTP\Client;
use localzet\Server;

$port = (int)($argv[1] ?? 0);
$loop = Server::getEventLoop();
$client = new Client(['connect_timeout' => 2, 'timeout' => 3]);
$response = null;
$error = null;

$client->get(
    "http://127.0.0.1:{$port}/async-client?case=queued-send",
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
    $error ??= new RuntimeException('HTTP client integration timed out.');
    $loop->stop();
});
$loop->run();

if ($error !== null) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
if ($response === null || $response->getStatusCode() !== 200 || (string)$response->getBody() !== '/async-client?case=queued-send') {
    fwrite(STDERR, "Unexpected HTTP client response.\n");
    exit(1);
}

echo "HTTP_CLIENT_LOOPBACK_QUEUED_CONNECT=ok\n";
