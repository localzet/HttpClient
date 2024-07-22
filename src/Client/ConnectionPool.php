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

namespace localzet\HTTP\Client;

use Exception;
use localzet\Server;
use localzet\Server\Connection\AsyncTcpConnection;
use localzet\Timer;
use Throwable;

/**
 * Класс ConnectionPool представляет собой пул соединений, который используется для управления множеством соединений в асинхронном клиенте HTTP.
 */
class ConnectionPool extends Emitter
{
    /**
     * @var array
     * Массив для хранения свободных соединений.
     */
    protected array $_idle = [];

    /**
     * @var array
     * Массив для хранения используемых соединений.
     */
    protected array $_using = [];

    /**
     * @var int
     * Идентификатор таймера для этого пула соединений.
     */
    protected int $_timer = 0;

    /**
     * @var array
     * Опции для этого пула соединений.
     */
    protected array $_options = [
        'max_conn_per_addr' => 128,
        'keepalive_timeout' => 15,
        'connect_timeout' => 30,
        'timeout' => 30,
    ];

    /**
     * Конструктор пула соединений.
     *
     * @param array $option Опции для пула соединений.
     */
    public function __construct(array $option = [])
    {
        $this->_options = array_merge_recursive($this->_options, $option);
    }

    /**
     * Извлекает свободное соединение из пула.
     *
     * @param string $address Адрес для извлечения соединения.
     * @param bool $ssl Флаг, указывающий, следует ли использовать SSL для соединения.
     * @return mixed|void Возвращает свободное соединение, если оно доступно, иначе ничего не возвращает.
     * @throws Throwable Выбрасывает исключение, если происходит ошибка при извлечении соединения.
     */
    public function fetch(string $address, bool $ssl = false)
    {
        $max_con = $this->_options['max_conn_per_addr'];
        if (!empty($this->_using[$address])) {
            if (count($this->_using[$address]) >= $max_con) {
                return;
            }
        }
        if (empty($this->_idle[$address])) {
            $connection = $this->create($address, $ssl);
            $this->_idle[$address][$connection->id] = $connection;
        }
        $connection = array_pop($this->_idle[$address]);
        if (!isset($this->_using[$address])) {
            $this->_using[$address] = [];
        }
        $this->_using[$address][$connection->id] = $connection;
        $connection->pool['request_time'] = time();
        $this->tryToCreateConnectionCheckTimer();
        return $connection;
    }

    /**
     * Возвращает соединение обратно в пул.
     *
     * @param $connection AsyncTcpConnection Соединение для возврата в пул.
     */
    public function recycle(AsyncTcpConnection $connection): void
    {
        $connection_id = $connection->id;
        $address = $connection->address;
        unset($this->_using[$address][$connection_id]);
        if (empty($this->_using[$address])) {
            unset($this->_using[$address]);
        }
        if ($connection->getStatus(false) === 'ESTABLISHED') {
            $this->_idle[$address][$connection_id] = $connection;
            $connection->pool['idle_time'] = time();
            $connection->onConnect = $connection->onMessage = $connection->onError =
            $connection->onClose = $connection->onBufferFull = $connection->onBufferDrain = null;
        }
        $this->tryToCreateConnectionCheckTimer();
        $this->emit('idle', $address);
    }

    /**
     * Удаляет соединение из пула.
     *
     * @param AsyncTcpConnection $connection Соединение для удаления.
     */
    public function delete(AsyncTcpConnection $connection): void
    {
        $connection_id = $connection->id;
        $address = $connection->address;
        unset($this->_idle[$address][$connection_id]);
        if (empty($this->_idle[$address])) {
            unset($this->_idle[$address]);
        }
        unset($this->_using[$address][$connection_id]);
        if (empty($this->_using[$address])) {
            unset($this->_using[$address]);
        }
    }

    /**
     * Закрывает соединения, которые превышают время ожидания.
     * @throws Throwable Выбрасывает исключение, если происходит ошибка при закрытии соединений.
     */
    public function closeTimeoutConnection(): void
    {
        if (empty($this->_idle) && empty($this->_using)) {
            Timer::del($this->_timer);
            $this->_timer = 0;
            return;
        }
        $time = time();
        $keepalive_timeout = $this->_options['keepalive_timeout'];
        foreach ($this->_idle as $address => $connections) {
            if (empty($connections)) {
                unset($this->_idle[$address]);
                continue;
            }
            foreach ($connections as $connection) {
                if ($time - $connection->pool['idle_time'] >= $keepalive_timeout) {
                    $this->delete($connection);
                    $connection->close();
                }
            }
        }

        $connect_timeout = $this->_options['connect_timeout'];
        $timeout = $this->_options['timeout'];
        foreach ($this->_using as $address => $connections) {
            if (empty($connections)) {
                unset($this->_using[$address]);
                continue;
            }
            foreach ($connections as $connection) {
                $state = $connection->getStatus(false);
                if ($state === 'CONNECTING') {
                    $diff = $time - $connection->pool['connect_time'];
                    if ($diff >= $connect_timeout) {
                        $connection->onClose = null;
                        if ($connection->onError) {
                            try {
                                call_user_func($connection->onError, $connection, 1, 'connect ' . $connection->getRemoteAddress() . ' timeout after ' . $diff . ' seconds');
                            } catch (Throwable $exception) {
                                $this->delete($connection);
                                $connection->close();
                                throw $exception;
                            }
                        }
                        $this->delete($connection);
                        $connection->close();
                    }
                } elseif ($state === 'ESTABLISHED') {
                    $diff = $time - $connection->pool['request_time'];
                    if ($diff >= $timeout) {
                        if ($connection->onError) {
                            try {
                                call_user_func($connection->onError, $connection, 128, 'read ' . $connection->getRemoteAddress() . ' timeout after ' . $diff . ' seconds');
                            } catch (Throwable $exception) {
                                $this->delete($connection);
                                $connection->close();
                                throw $exception;
                            }
                        }
                        $this->delete($connection);
                        $connection->close();
                    }
                }
            }
        }
        gc_collect_cycles();
    }

    /**
     * Создает новое соединение для пула.
     *
     * @param string $address Адрес для создания соединения.
     * @param bool $ssl Флаг, указывающий, следует ли использовать SSL для соединения.
     * @return AsyncTcpConnection Возвращает новое соединение.
     * @throws Exception Выбрасывает исключение, если происходит ошибка при создании соединения.
     * @throws Throwable Выбрасывает исключение, если происходит ошибка при создании соединения.
     */
    protected function create(string $address, bool $ssl = false): AsyncTcpConnection
    {
        $context = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ];
        if (!empty($this->_options['context'])) {
            $context = $this->_options['context'];
        }
        if (!$ssl) {
            unset($context['ssl']);
        }
        if (!class_exists(Server::class) || is_null(Server::$globalEvent)) {
            throw new Exception('Only the localzet environment is supported.');
        }
        $connection = new AsyncTcpConnection($address, $context);
        if ($ssl) {
            $connection->transport = 'ssl';
        }
        $connection->address = $address;
        $connection->connect();
        $connection->pool = ['connect_time' => time()];
        return $connection;
    }

    /**
     * Создает таймер для проверки состояния соединений в пуле.
     */
    protected function tryToCreateConnectionCheckTimer(): void
    {
        if (!$this->_timer) {
            $this->_timer = Timer::add(1, [$this, 'closeTimeoutConnection']);
        }
    }
}