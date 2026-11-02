# Localzet HttpClient

[English documentation](README.md)

Асинхронные HTTP-запросы и пул соединений для Localzet Server.

## Состояние и совместимость

Текущий класс клиента — localzet\HTTP\Client, а не AsyncClient. Проверены установка и синтаксис; редиректы, проверка TLS, потоковая передача, отмена запросов и жизненный цикл пула требуют интеграционных тестов.

Эта версия рассчитана на Localzet Server 7.x.

## Зависимости

- `php`: `^8.2`
- `ext-curl`: `*`
- `ext-json`: `*`
- `localzet/server`: `^7.0`
- `localzet/psr7`: `^3.0`
- `psr/http-message`: `^2.0`

## Установка

```sh
composer require localzet/http:dev-main
```

Следующий выпуск 3.x рассчитан на Server 7 и PSR-7 4. Опубликованный стабильный тег `v2.0.2` рассчитан на Server 4.2 и PSR-7 3; обновляйте Server и PSR-7 одновременно.

## Проверки разработки

```sh
composer validate --strict
composer install
composer dump-autoload --optimize --strict-psr
composer audit
```

Установка, lint и автозагрузка не подтверждают сквозное поведение или готовность к эксплуатации.

[Исторические примеры](docs/legacy-readme.md) нужно сверять с текущим API.

## Автор и лицензия

Ivan Zorin (`localzet`), <creator@localzet.com>, https://www.localzet.com.
Source: https://github.com/localzet/HttpClient. AGPL-3.0-or-later; [LICENSE](LICENSE). Сохраняются исходные уведомления авторов и лицензии сторонних компонентов.

[Authors](.github/AUTHORS.md) · [Contributing](.github/CONTRIBUTING.md) · [Security](.github/SECURITY.md)
