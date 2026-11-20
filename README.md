# Localzet HttpClient

[Русская документация](README.ru.md)

Asynchronous HTTP requests and connection pooling for Localzet Server.

## Status and compatibility

The current client class is `localzet\HTTP\Client`. Regression tests cover option merging and credential handling on redirects. Loopback integration tests cover queued asynchronous requests, trusted and untrusted HTTPS certificates, and hostname verification. Streaming, cancellation and the full connection-pool lifecycle are not covered by the current suite.

The `v3.0.0` tag targets Localzet Server 7 and PSR-7 4. The older `v2.0.2` tag targets Server 4.2 and PSR-7 3.

## Dependencies

- `php`: `^8.2`
- `ext-curl`: `*`
- `ext-json`: `*`
- `ext-openssl`: required only for HTTPS requests; optional for HTTP-only use
- `localzet/server`: `^7.0`
- `localzet/psr7`: `^4.0`
- `psr/http-message`: `^2.0`

## Installation

```sh
composer require localzet/http:^3.0
```

Use the package versions that match your Server installation: `localzet/http:^3.0` with Server 7 and PSR-7 4, or `localzet/http:^2.0` with Server 4.2 and PSR-7 3. Upgrade these packages together.

## Development checks

```sh
composer validate --strict
composer install
composer dump-autoload --optimize --strict-psr
composer audit
composer run test
composer run test:integration
composer run test:tls
```

The integration commands run local HTTP and HTTPS fixtures with a temporary test certificate. They verify TLS rejection and trust behavior; they do not establish production readiness.

## HTTPS and redirects

HTTPS certificate-chain and hostname verification are enabled by default. To trust a private certificate authority, pass its PEM file through the stream context:

```php
$client = new \localzet\HTTP\Client([
    'context' => [
        'ssl' => [
            'cafile' => '/path/to/private-ca.pem',
        ],
    ],
]);
```

Avoid disabling peer or hostname verification. Redirects to a different origin (scheme, host, or effective port) remove `Authorization`, `Cookie`, and `Proxy-Authorization`; same-origin redirects retain them. Custom headers are not treated as credentials automatically, so do not put secrets in custom headers when redirects are enabled.

[Historical usage notes](docs/legacy-readme.md) need verification against the current API.

## Author and license

Ivan Zorin (`localzet`), <creator@localzet.com>, https://www.localzet.com.
Source: https://github.com/localzet/HttpClient. AGPL-3.0-or-later; [LICENSE](LICENSE). Original copyright and third-party licenses remain applicable.

[Authors](.github/AUTHORS.md) · [Contributing](.github/CONTRIBUTING.md) · [Security](.github/SECURITY.md)
