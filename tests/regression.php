<?php

/**
 * @package     Localzet HTTP Client
 * @link        https://github.com/localzet/HttpClient
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright © 2016-2026 Localzet Group. All rights reserved.
 * @license     https://www.gnu.org/licenses/agpl-3.0 GNU Affero General Public License v3.0
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

function expectSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': ' . var_export($actual, true));
    }
}

$pool = new \localzet\HTTP\Client\ConnectionPool([
    'max_conn_per_addr' => 16,
    'connect_timeout' => 5,
    'timeout' => 10,
]);
$poolOptions = (new ReflectionClass($pool))->getProperty('_options')->getValue($pool);
expectSame(16, $poolOptions['max_conn_per_addr'], 'Pool limit override must remain scalar');
expectSame(5, $poolOptions['connect_timeout'], 'Connect timeout override must replace its default');
expectSame(10, $poolOptions['timeout'], 'Request timeout override must replace its default');
expectSame(15, $poolOptions['keepalive_timeout'], 'Unspecified pool defaults must remain available');

$request = (new \localzet\HTTP\Client\Request('https://example.test'))
    ->setOptions([
        'allow_redirects' => ['max' => 2],
        'headers' => ['Accept' => 'application/json'],
    ]);
$requestOptions = $request->getOptions();
expectSame(2, $requestOptions['allow_redirects']['max'], 'Redirect limit must replace its default');
expectSame('application/json', $requestOptions['headers']['Accept'], 'Header override must replace its default');
expectSame('max-age=0', $requestOptions['headers']['Cache-Control'], 'Unspecified default headers must be preserved');

echo "HTTP client option merge regression passed.\n";
