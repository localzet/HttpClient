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

namespace localzet\HTTP\AsyncClient;

use Exception;
use localzet\Server;
use localzet\Server\Connection\AsyncTcpConnection;
use localzet\Timer;
use Throwable;

/**
 * Class ConnectionPool
 * @package localzet\HTTP
 */
class ConnectionPool extends Emitter
{
    /**
     * @var array
     */
    protected $_idle = [];

    /**
     * @var array
     */
    protected $_using = [];

    /**
     * @var int
     */
    protected $_timer = 0;

    /**
     * @var array
     */
    protected $_options = [
        'max_conn_per_addr' => 128,
        'keepalive_timeout' => 15,
        'connect_timeout' => 30,
        'timeout' => 30,
    ];

    /**
     * ConnectionPool constructor.
     *
     * @param array $option
     */
    public function __construct($option = [])
    {
        $this->_options = array_merge($this->_options, $option);
    }

    /**
     * Fetch an idle connection.
     *
     * @param $address
     * @param bool $ssl
     * @return mixed
     * @throws Throwable
     */
    public function fetch($address, $ssl = false)
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
     * Recycle an connection.
     *
     * @param $connection AsyncTcpConnection
     */
    public function recycle($connection)
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
     * Delete a connection.
     *
     * @param $connection
     */
    public function delete($connection)
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
     * Close timeout connection.
     * @throws Throwable
     */
    public function closeTimeoutConnection()
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
     * Create a connection.
     *
     * @param $address
     * @param bool $ssl
     * @return AsyncTcpConnection
     * @throws Exception
     * @throws Throwable
     */
    protected function create($address, $ssl = false)
    {
        $context = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
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
     * Create Timer.
     */
    protected function tryToCreateConnectionCheckTimer()
    {
        if (!$this->_timer) {
            $this->_timer = Timer::add(1, [$this, 'closeTimeoutConnection']);
        }
    }
}