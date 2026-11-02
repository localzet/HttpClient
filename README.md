# Localzet HttpClient

[Русская документация](README.ru.md)

Asynchronous HTTP requests and connection pooling for Localzet Server.

## Status and compatibility

The current client class is localzet\HTTP\Client, not AsyncClient. Installation and syntax have been checked; redirects, TLS verification, streaming, cancellation and connection-pool lifecycle need integration tests.

This release targets Localzet Server 7.x.

## Dependencies

- `php`: `^8.2`
- `ext-curl`: `*`
- `ext-json`: `*`
- `localzet/server`: `^7.0`
- `localzet/psr7`: `^3.0`
- `psr/http-message`: `^2.0`

## Installation

```sh
composer require localzet/http:dev-main
```

The current source branch targets Server 7. The published stable tag `v2.0.2` targets Server 4.2; do not install that tag alongside this source checkout.

## Development checks

```sh
composer validate --strict
composer install
composer dump-autoload --optimize --strict-psr
composer audit
```

Installation, lint and autoload checks do not establish end-to-end behavior or production readiness.

[Historical usage notes](docs/legacy-readme.md) need verification against the current API.

## Author and license

Ivan Zorin (`localzet`), <creator@localzet.com>, https://www.localzet.com.
Source: https://github.com/localzet/HttpClient. AGPL-3.0-or-later; [LICENSE](LICENSE). Original copyright and third-party licenses remain applicable.

[Authors](.github/AUTHORS.md) · [Contributing](.github/CONTRIBUTING.md) · [Security](.github/SECURITY.md)
