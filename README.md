<p align="center"><a href="https://www.localzet.com" target="_blank">
  <img src="https://static.zorin.space/media/logos/ZorinProjectsSP.svg" width="400">
</a></p>

<p align="center">
  <a href="https://packagist.org/packages/localzet/http">
  <img src="https://img.shields.io/packagist/dt/localzet/http?label=%D0%A1%D0%BA%D0%B0%D1%87%D0%B8%D0%B2%D0%B0%D0%BD%D0%B8%D1%8F" alt="Скачивания">
</a>
  <a href="https://github.com/localzet/HttpClient">
  <img src="https://img.shields.io/github/commit-activity/t/localzet/HttpClient?label=%D0%9A%D0%BE%D0%BC%D0%BC%D0%B8%D1%82%D1%8B" alt="Коммиты">
</a>
  <a href="https://packagist.org/packages/localzet/http">
  <img src="https://img.shields.io/packagist/v/localzet/http?label=%D0%92%D0%B5%D1%80%D1%81%D0%B8%D1%8F" alt="Версия">
</a>
  <a href="https://packagist.org/packages/localzet/http">
  <img src="https://img.shields.io/packagist/dependency-v/localzet/http/php?label=PHP" alt="Версия PHP">
</a>
  <a href="https://github.com/localzet/HttpClient">
  <img src="https://img.shields.io/github/license/localzet/HttpClient?label=%D0%9B%D0%B8%D1%86%D0%B5%D0%BD%D0%B7%D0%B8%D1%8F" alt="Лицензия">
</a>
</p>

# Установка
`composer require localzet/http`

# Примеры
**example.php**
```php
<?php

use localzet\HTTP\AsyncClient;

require __DIR__ . '/vendor/autoload.php';

$http = new AsyncClient();

/**
 * $http->get() принимает 3 аргумента:
 * 1. URL (параметры указываются в самом URL)
 * 2. Callback при удачном запросе
 * 3. Callback при ошибке
 */
$http->get(
    'https://example.com/',
    function ($response) {
        var_dump($response->getStatusCode());
        echo $response->getBody();
    },
    function ($exception) {
        echo $exception;
    }
);

/**
 * $http->post() принимает 4 аргумента:
 * 1. URL
 * 2. Параметры
 * 3. Callback при удачном запросе
 * 4. Callback при ошибке
 */
$http->post(
    'https://example.com/',
    ['key1' => 'value1', 'key2' => 'value2'],
    function ($response) {
        var_dump($response->getStatusCode());
        echo $response->getBody();
    },
    function ($exception) {
        echo $exception;
    }
);


/**
 * $http->request() принимает 2 аргумента:
 * 1. URL
 * 2. Опции [
 *      method,     Метод
 *      version,    Версия HTTP
 *      headers,    Массив заголовков
 *      data,       Параметры (вне зависимости от метода, работает http_build_query())
 *      success,    Callback при удачном запросе
 *      error       Callback при ошибке
 *  ]
 */
$http->request(
    'https://example.com/',
    [
        'method' => 'POST',
        'version' => '1.1',
        'headers' => ['Connection' => 'keep-alive'],
        'data' => ['key1' => 'value1', 'key2' => 'value2'],
        'success' => function ($response) {
            echo $response->getBody();
        },
        'error' => function ($exception) {
            echo $exception;
        }
    ]
);
```

# Калибровка клиента

```php
<?php

use localzet\HTTP\Client;

require __DIR__ . '/vendor/autoload.php';

$options = [
    'max_conn_per_addr' => 128,     // Максимум одновременных запросов к одному URL
    'keepalive_timeout' => 15,      // Время жизни соединения
    'connect_timeout' => 30,        // Ожидание между соединениями
    'timeout' => 30,                // Ожидание между запросами
];

$http = new Client($options);

/**
 * $http->get() принимает 3 аргумента:
 * 1. URL (параметры указываются в самом URL)
 * 2. Callback при удачном запросе
 * 3. Callback при ошибке
 */
$http->get(
    'https://example.com/',
    function ($response) {
        var_dump($response->getStatusCode());
        echo $response->getBody();
    },
    function ($exception) {
        echo $exception;
    }
);
```