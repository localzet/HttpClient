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