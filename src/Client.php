<?php

/**
 * @package     HTTP Client
 * @link        https://github.com/localzet/HttpClient
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2018-2024 Zorin Projects S.P.
 * @license     https://www.gnu.org/licenses/agpl-3.0 GNU Affero General Public License v3.0
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as published
 *              by the Free Software Foundation, either version 3 of the License, or
 *              (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 *              For any questions, please contact <creator@localzet.com>
 */

namespace localzet\HTTP;

use Exception;
use localzet\HTTP\Client\ConnectionPool;
use localzet\HTTP\Client\Request;
use localzet\Server;
use localzet\Timer;
use RuntimeException;
use Throwable;

/**
 * Класс AsyncClient представляет собой асинхронного клиента HTTP, который использует пул соединений для управления множественными запросами.
 */
#[\AllowDynamicProperties]
class Client
{
    /**
     *  Очередь запросов, организованная по адресам.
     *  Каждый адрес содержит массив запросов, каждый из которых включает URL, адрес и опции запроса.
     * [
     *  address => [
     *      [
     *          'url' => x,
     *          'address' => x
     *          'options' => [
     *              'method',
     *              'data' => x,
     *              'success' => callback,
     *              'error' => callback,
     *              'headers' => [..],
     *              'version' => 1.1
     *          ]
     *      ],
     *      ..
     *  ],
     *  ..
     * ]
     * @var array
     */
    protected array $_queue = [];

    /**
     * Пул соединений для управления активными соединениями.
     * @var ConnectionPool|array|null
     */
    protected ConnectionPool|array|null $_connectionPool = null;

    /**
     * Конструктор клиента.
     * Создает новый пул соединений и устанавливает обработчик событий 'idle' для обработки запросов, когда соединение свободно.
     * @param array $options Опции для пула соединений.
     */
    public function __construct(array $options = [])
    {
        $this->_connectionPool = new ConnectionPool($options);
        $this->_connectionPool->on('idle', [$this, 'process']);
    }

    /**
     * Отправляет HTTP-запрос.
     *
     * @param $url string URL-адрес для запроса.
     * @param string $method Метод запроса.
     * @param array $data Данные для отправки в теле запроса.
     * @param array $headers Заголовки для запроса.
     * @param array $options Опции запроса, включая метод, данные, обратные вызовы успеха и ошибки, заголовки и версию.
     * @return mixed|void Выполняет ответ на запрос или приостанавливает выполнение, если определен обратный вызов успеха и текущая среда поддерживает Unix.
     * @throws Throwable
     */
    public function request(string $url, string $method = 'GET', array $data = [], array $headers = [], array $options = [])
    {
        $options['url'] = $url;
        $options['method'] = $method;
        $options['data'] = $data;
        $options['headers'] = $headers;
        $needSuspend = !isset($options['success']) && is_unix();

        try {
            $address = $this->parseAddress($url);
            $this->queuePush($address, ['url' => $url, 'address' => $address, 'options' => &$options]);
            $this->process($address);
        } catch (Throwable $exception) {
            $this->deferError($options, $exception);
            return;
        }

        if ($needSuspend) {
            $suspension = Server::$globalEvent->getSuspension();
            $options['success'] = function ($response) use ($suspension) {
                $suspension->resume($response);
            };
            $options['error'] = function ($response) use ($suspension) {
                $suspension->throw($response);
            };

            return $suspension->suspend();
        }
    }

    /**
     * Отправляет HTTP GET-запрос.
     *
     * @param string $url URL-адрес для запроса.
     * @param array $headers Заголовки для запроса.
     * @param null $success_callback Обратный вызов, который будет вызван при успешном завершении запроса.
     * @param null $error_callback Обратный вызов, который будет вызван при ошибке запроса.
     * @return mixed Возвращает ответ на запрос.
     * @throws Throwable Бросает исключение, если происходит ошибка при обработке запроса.
     */
    public function get(string $url, array $headers = [], $success_callback = null, $error_callback = null): mixed
    {
        $options = [];
        $options['method'] = 'GET';
        if ($headers) {
            $options['headers'] = $headers;
        }
        if ($success_callback) {
            $options['success'] = $success_callback;
        }
        if ($error_callback) {
            $options['error'] = $error_callback;
        }

        return $this->request($url, 'GET', [], $headers, $options);
    }

    /**
     * Отправляет HTTP POST-запрос.
     *
     * @param string $url URL-адрес для запроса.
     * @param array $data Данные для отправки в теле запроса.
     * @param array $headers Заголовки для запроса.
     * @param null $success_callback Обратный вызов, который будет вызван при успешном завершении запроса.
     * @param null $error_callback Обратный вызов, который будет вызван при ошибке запроса.
     * @return mixed Возвращает ответ на запрос.
     * @throws Throwable Бросает исключение, если происходит ошибка при обработке запроса.
     */
    public function post(string $url, array $data = [], array $headers = [], $success_callback = null, $error_callback = null): mixed
    {
        $options = [];
        $options['method'] = 'POST';
        if ($data) {
            $options['data'] = $data;
        }
        if ($headers) {
            $options['headers'] = $headers;
        }
        if ($success_callback) {
            $options['success'] = $success_callback;
        }
        if ($error_callback) {
            $options['error'] = $error_callback;
        }

        return $this->request($url, 'POST', $data, $headers, $options);
    }

    /**
     * Обрабатывает очередь запросов для данного адреса.
     * Этот метод не предназначен для вызова пользователем.
     *
     * @param string $address Адрес для обработки.
     * @return void
     * @throws Throwable Бросает исключение, если происходит ошибка при обработке запроса.
     */
    public function process(string $address): void
    {
        $task = $this->queueCurrent($address);
        if (!$task) {
            return;
        }

        $url = $task['url'];
        $address = $task['address'];
        $connection = $this->_connectionPool->fetch($address, str_starts_with($url, 'https'));

        // No connection is in idle state then wait.
        if (!$connection) {
            return;
        }

        $connection->errorHandler = function (Throwable $exception) use ($task) {
            $this->deferError($task['options'], $exception);
        };

        $this->queuePop($address);
        $options = $task['options'];
        $request = new Request($url);
        $data = $options['data'] ?? '';
        if ($data || $data === '0' || $data === 0) {
            $method = isset($options['method']) ? strtoupper($options['method']) : null;
            if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
                $request->write($options['data']);
            } else {
                $options['query'] = $data;
            }
        }
        $request->setOptions($options)->attachConnection($connection);

        $client = $this;
        $request->once('success', function ($response) use ($task, $client, $request) {
            $client->recycleConnectionFromRequest($request, $response);
            try {
                $new_request = Request::redirect($request, $response);
            } catch (Exception $exception) {
                $this->deferError($task['options'], $exception);
                return;
            }

            // No redirect.
            if (!$new_request) {
                if (!empty($task['options']['success'])) {
                    call_user_func($task['options']['success'], $response);
                }
                return;
            }

            // Redirect.
            $uri = $new_request->getUri();
            $url = (string)$uri;
            $options = $new_request->getOptions();
            $address = $this->parseAddress($url);
            $task = [
                'url' => $url,
                'options' => $options,
                'address' => $address
            ];
            $this->queueUnshift($address, $task);
            $this->process($address);
        })->once('error', function ($exception) use ($task, $client, $request) {
            $client->recycleConnectionFromRequest($request);
            $this->deferError($task['options'], $exception);
        });

        if (isset($options['progress'])) {
            $request->on('progress', $options['progress']);
        }

        $state = $connection->getStatus(false);
        if ($state === 'CLOSING' || $state === 'CLOSED') {
            $connection->reconnect();
        }

        $state = $connection->getStatus(false);
        if ($state === 'CLOSED' || $state === 'CLOSING') {
            return;
        }

        $request->end();
    }

    /**
     * Возвращает соединение в пул после завершения запроса.
     *
     * @param Request $request Запрос, для которого требуется вернуть соединение.
     * @param null $response Response Ответ на запрос.
     * @throws Throwable Бросает исключение, если происходит ошибка при возвращении соединения.
     */
    public function recycleConnectionFromRequest(Request $request, $response = null): void
    {
        $connection = $request->getConnection();
        if (!$connection) {
            return;
        }
        $connection->onConnect = $connection->onClose = $connection->onMessage = $connection->onError = null;
        $requestHeaderConnection = strtolower($request->getHeaderLine('Connection'));
        $responseHeaderConnection = $response ? strtolower($response->getHeaderLine('Connection')) : '';

        // Закрыть соединение без заголовка. Connection: keep-alive
        if (
            'keep-alive' !== $requestHeaderConnection ||
            'keep-alive' !== $responseHeaderConnection ||
            $request->getProtocolVersion() !== '1.1'
        ) {
            $connection->close();
        }
        $request->detachConnection();
        $this->_connectionPool->recycle($connection);
    }

    /**
     * Разбирает адрес из URL.
     *
     * @param string $url URL для разбора.
     * @return string Возвращает строку адреса в формате "tcp://host:port".
     * @throws RuntimeException Выбрасывает исключение, если URL недействителен.
     */
    protected function parseAddress(string $url): string
    {
        $info = parse_url($url);
        if (!isset($info['host'])) {
            throw new RuntimeException("invalid url: $url");
        }
        $port = $info['port'] ?? (str_starts_with($url, 'https') ? 443 : 80);
        return "tcp://{$info['host']}:$port";
    }

    /**
     * Добавляет задачу в очередь для данного адреса.
     *
     * @param string $address Адрес для добавления задачи.
     * @param mixed $task Задача для добавления в очередь.
     */
    protected function queuePush(string $address, mixed $task): void
    {
        if (!isset($this->_queue[$address])) {
            $this->_queue[$address] = [];
        }
        $this->_queue[$address][] = $task;
    }

    /**
     * Добавляет задачу в начало очереди для данного адреса.
     *
     * @param string $address Адрес для добавления задачи.
     * @param mixed $task Задача для добавления в очередь.
     */
    protected function queueUnshift(string $address, mixed $task): void
    {
        if (!isset($this->_queue[$address])) {
            $this->_queue[$address] = [];
        }
        $this->_queue[$address] += [$task];
    }

    /**
     * Получает текущую задачу из очереди для данного адреса.
     *
     * @param string $address Адрес для получения текущей задачи.
     * @return mixed|null Возвращает текущую задачу или null, если очередь пуста.
     */
    protected function queueCurrent(string $address): mixed
    {
        if (empty($this->_queue[$address])) {
            return null;
        }
        reset($this->_queue[$address]);
        return current($this->_queue[$address]);
    }

    /**
     * Удаляет текущую задачу из очереди для данного адреса.
     *
     * @param string $address Адрес для удаления текущей задачи.
     */
    protected function queuePop(string $address): void
    {
        unset($this->_queue[$address][key($this->_queue[$address])]);
        if (empty($this->_queue[$address])) {
            unset($this->_queue[$address]);
        }
    }

    /**
     * Откладывает обработку ошибки, вызывая обратный вызов ошибки или выбрасывая исключение.
     *
     * @param array $options Опции запроса, включающие обратные вызовы успеха и ошибки.
     * @param Throwable $exception Исключение для обработки.
     * @return void
     * @throws Throwable Выбрасывает исключение, если обратный вызов ошибки не определен и текущая среда поддерживает Unix.
     */
    protected function deferError(array $options, Throwable $exception): void
    {
        if (isset($options['error'])) {
            Timer::add(0.000001, $options['error'], [$exception], false);
            return;
        }
        $needSuspend = !isset($options['success']) && is_unix();
        if ($needSuspend) {
            throw $exception;
        }
    }
}
