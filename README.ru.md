# Localzet HttpClient

[English documentation](README.md)

Асинхронные HTTP-запросы и пул соединений для Localzet Server.

## Состояние и совместимость

Текущий класс клиента — `localzet\HTTP\Client`. Регрессионные тесты проверяют объединение параметров и передачу учётных заголовков при редиректах. Интеграционные тесты на loopback проверяют отложенную отправку асинхронного запроса, доверенные и недоверенные HTTPS-сертификаты, а также имя узла. Потоковая передача, отмена запросов и полный жизненный цикл пула пока покрыты не полностью.

Тег `v3.0.0` рассчитан на Localzet Server 7 и PSR-7 4. Более старый тег `v2.0.2` рассчитан на Server 4.2 и PSR-7 3.

## Зависимости

- `php`: `^8.2`
- `ext-curl`: `*`
- `ext-json`: `*`
- `ext-openssl`: нужен только для HTTPS-запросов; для HTTP не обязателен
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
composer run test:tls
```

Интеграционные команды запускают локальные HTTP- и HTTPS-заглушки с временным тестовым сертификатом. Они проверяют отказ для недоверенного сертификата и успешное соединение с явно доверенным сертификатом; эти проверки не подтверждают готовность к эксплуатации.

## HTTPS и редиректы

По умолчанию клиент проверяет цепочку сертификатов и имя узла. Чтобы доверять частному центру сертификации, передайте его PEM-файл через stream context:

```php
$client = new \localzet\HTTP\Client([
    'context' => [
        'ssl' => [
            'cafile' => '/path/to/private-ca.pem',
        ],
    ],
]);
```

Не отключайте проверку сертификата или имени узла. При редиректе на другой origin (схема, узел или эффективный порт) клиент удаляет `Authorization`, `Cookie` и `Proxy-Authorization`; при редиректе в пределах того же origin они сохраняются. Произвольные заголовки автоматически не считаются учётными данными: не помещайте секреты в собственные заголовки, если разрешены редиректы.

[Исторические примеры](docs/legacy-readme.md) нужно сверять с текущим API.

## Автор и лицензия

Ivan Zorin (`localzet`), <creator@localzet.com>, https://www.localzet.com.
Source: https://github.com/localzet/HttpClient. AGPL-3.0-or-later; [LICENSE](LICENSE). Сохраняются исходные уведомления авторов и лицензии сторонних компонентов.

[Authors](.github/AUTHORS.md) · [Contributing](.github/CONTRIBUTING.md) · [Security](.github/SECURITY.md)
