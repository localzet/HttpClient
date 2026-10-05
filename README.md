# Localzet HttpClient

[Русская документация](README.ru.md)

Asynchronous HTTP requests and connection pooling for Localzet Server.

## Status and compatibility

The current client class is localzet\HTTP\Client, not AsyncClient. Installation and syntax have been checked; redirects, TLS verification, streaming, cancellation and connection-pool lifecycle need integration tests.

This is a Server 4.x component; Server 7.x compatibility is not established.

## Dependencies

- `php`: `^8.0`
- `ext-curl`: `*`
- `ext-json`: `*`
- `localzet/server`: `^4.2`
- `localzet/psr7`: `^3.0`
- `psr/http-message`: `^2.0`

## Installation

```sh
composer require localzet/http
```

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
