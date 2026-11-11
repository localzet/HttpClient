# Localzet HttpClient

[English documentation](README.md)

Асинхронные HTTP-запросы и пул соединений для Localzet Server.

## Состояние и совместимость

Текущий класс клиента — `localzet\HTTP\Client`. Unit-тесты проверяют объединение параметров, а loopback integration test — асинхронное соединение с отложенной отправкой и получение ответа. Текущий набор интеграционных тестов не проверяет редиректы, TLS, потоковую передачу, отмену запросов и жизненный цикл пула.

Тег `v3.0.0` рассчитан на Localzet Server 7 и PSR-7 4. Более старый тег `v2.0.2` рассчитан на Server 4.2 и PSR-7 3.

## Зависимости

- `php`: `^8.2`
- `ext-curl`: `*`
- `ext-json`: `*`
- `localzet/server`: `^7.0`
- `localzet/psr7`: `^4.0`
- `psr/http-message`: `^2.0`

## Установка

```sh
composer require localzet/http:^3.0
```

Выбирайте версии под установленный Server: `localzet/http:^3.0` вместе с Server 7 и PSR-7 4 либо `localzet/http:^2.0` вместе с Server 4.2 и PSR-7 3. Обновляйте эти пакеты согласованно.

## Проверки разработки

```sh
composer validate --strict
composer install
composer dump-autoload --optimize --strict-psr
composer audit
composer run test
composer run test:integration
```

Integration-команда запускает локальную HTTP-заглушку; она не подтверждает работу TLS или готовность к эксплуатации.

[Исторические примеры](docs/legacy-readme.md) нужно сверять с текущим API.

## Автор и лицензия

Ivan Zorin (`localzet`), <creator@localzet.com>, https://www.localzet.com.
Source: https://github.com/localzet/HttpClient. AGPL-3.0-or-later; [LICENSE](LICENSE). Сохраняются исходные уведомления авторов и лицензии сторонних компонентов.

[Authors](.github/AUTHORS.md) · [Contributing](.github/CONTRIBUTING.md) · [Security](.github/SECURITY.md)
