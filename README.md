# Localzet HttpClient

[Русская документация](README.ru.md)

Asynchronous HTTP requests and connection pooling for Localzet Server.

## Status and compatibility

The current client class is `localzet\HTTP\Client`. Unit tests cover option merging, and a loopback integration test covers a queued asynchronous connection and response. Redirect handling, TLS verification, streaming, cancellation and connection-pool lifecycle are not covered by the current integration suite.

The `v3.0.0` tag targets Localzet Server 7 and PSR-7 4. The older `v2.0.2` tag targets Server 4.2 and PSR-7 3.

## Dependencies

- `php`: `^8.2`
- `ext-curl`: `*`
- `ext-json`: `*`
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
```

The integration command runs a local HTTP fixture; it does not establish TLS behavior or production readiness.

[Historical usage notes](docs/legacy-readme.md) need verification against the current API.

## Author and license

Ivan Zorin (`localzet`), <creator@localzet.com>, https://www.localzet.com.
Source: https://github.com/localzet/HttpClient. AGPL-3.0-or-later; [LICENSE](LICENSE). Original copyright and third-party licenses remain applicable.

[Authors](.github/AUTHORS.md) · [Contributing](.github/CONTRIBUTING.md) · [Security](.github/SECURITY.md)
