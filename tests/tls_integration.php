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

if (!extension_loaded('openssl')) {
    throw new RuntimeException('The TLS integration test requires ext-openssl.');
}

$directory = sys_get_temp_dir() . '/localzet-http-tls-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('Cannot create a temporary TLS test directory.');
}

$configurationPath = $directory . '/openssl.cnf';
$certificatePath = $directory . '/certificate.pem';
$privateKeyPath = $directory . '/private-key.pem';
$configuration = <<<'CONFIG'
[req]
prompt = no
distinguished_name = subject
x509_extensions = extensions
default_md = sha256

[subject]
CN = localhost

[extensions]
basicConstraints = critical, CA:TRUE
keyUsage = critical, digitalSignature, keyEncipherment, keyCertSign
subjectAltName = @names

[names]
DNS.1 = localhost
CONFIG;

file_put_contents($configurationPath, $configuration);

try {
    $certificateProcess = proc_open(
        [
            'openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
            '-keyout', $privateKeyPath, '-out', $certificatePath, '-config', $configurationPath,
        ],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $certificatePipes
    );
    if (!is_resource($certificateProcess)) {
        throw new RuntimeException('Cannot start the OpenSSL test certificate generator.');
    }
    fclose($certificatePipes[0]);
    $certificateOutput = stream_get_contents($certificatePipes[1]);
    $certificateError = stream_get_contents($certificatePipes[2]);
    fclose($certificatePipes[1]);
    fclose($certificatePipes[2]);
    $certificateExitCode = proc_close($certificateProcess);
    if ($certificateExitCode !== 0) {
        throw new RuntimeException(trim((string)$certificateError . "\n" . (string)$certificateOutput));
    }

    $reservation = stream_socket_server('tcp://127.0.0.1:0', $errno, $errorMessage);
    if (!is_resource($reservation)) {
        throw new RuntimeException("Cannot reserve a TLS loopback port: {$errorMessage}");
    }
    $address = stream_socket_get_name($reservation, false);
    fclose($reservation);
    $port = (int)substr((string)$address, strrpos((string)$address, ':') + 1);

    $server = proc_open(
        [PHP_BINARY, __DIR__ . '/tls_fixture.php', (string)$port, $certificatePath, $privateKeyPath],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $serverPipes,
        dirname(__DIR__)
    );
    if (!is_resource($server)) {
        throw new RuntimeException('Cannot start the loopback TLS fixture.');
    }
    fclose($serverPipes[0]);
    stream_set_blocking($serverPipes[1], false);
    stream_set_blocking($serverPipes[2], false);

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
            $fixtureError = stream_get_contents($serverPipes[2]);
            throw new RuntimeException('Loopback TLS fixture did not start: ' . trim((string)$fixtureError));
        }

        foreach (['reject-untrusted', 'accept-trusted', 'reject-hostname'] as $mode) {
            $client = proc_open(
                [PHP_BINARY, __DIR__ . '/tls_integration_client.php', (string)$port, $mode, $certificatePath],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $clientPipes,
                dirname(__DIR__)
            );
            if (!is_resource($client)) {
                throw new RuntimeException("Cannot start the TLS integration client for {$mode}.");
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
        }
    } finally {
        proc_terminate($server);
        foreach ([1, 2] as $index) {
            fclose($serverPipes[$index]);
        }
        proc_close($server);
    }
} finally {
    foreach ([$configurationPath, $certificatePath, $privateKeyPath] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($directory);
}
