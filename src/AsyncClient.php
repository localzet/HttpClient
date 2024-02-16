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

use AllowDynamicProperties;
use Exception;
use localzet\HTTP\AsyncClient\ConnectionPool;
use localzet\HTTP\AsyncClient\Request;
use localzet\HTTP\AsyncClient\Response;
use localzet\Server;
use localzet\Server\Events\Linux;
use Throwable;

/**
 * Class HTTP\Client
 * @package localzet\HTTP
 */
#[AllowDynamicProperties]
class AsyncClient
{
    /**
     *
     *[
     *   address=>[
     *        [
     *        'url'=>x,
     *        'address'=>x
     *        'options'=>['method', 'data'=>x, 'success'=>callback, 'error'=>callback, 'headers'=>[..], 'version'=>1.1]
     *        ],
     *        ..
     *   ],
     *   ..
     * ]
     * @var array
     */
    protected $_queue = array();

    /**
     * @var array
     */
    protected $_connectionPool = null;

    /**
     * Client constructor.
     * @param array $options
     */
    public function __construct($options = [])
    {
        $this->_connectionPool = new ConnectionPool($options);
        $this->_connectionPool->on('idle', array($this, 'process'));
    }

    /**
     * Request.
     *
     * @param $url string
     * @param array $options ['method'=>'get', 'data'=>x, 'success'=>callback, 'error'=>callback, 'headers'=>[..], 'version'=>1.1]
     * @return mixed|Response
     * @throws Throwable
     */
    public function request($url, $options = [])
    {
        $address = $this->parseAddress($url, $options);
        $options['url'] = $url;
        $needSuspend = !isset($options['success']) && Server::$globalEvent instanceof Linux;
        if ($needSuspend) {
            $suspension = Server::$globalEvent->getSuspension();
            $options['success'] = function ($response) use ($suspension) {
                $suspension->resume($response);
            };
        }
        $this->queuePush($address, ['url' => $url, 'address' => $address, 'options' => $options]);
        $this->process($address);
        if ($needSuspend) {
            return $suspension->suspend();
        }
    }

    /**
     * Get.
     *
     * @param $url
     * @param null $success_callback
     * @param null $error_callback
     * @return mixed|Response
     * @throws Throwable
     */
    public function get($url, $success_callback = null, $error_callback = null)
    {
        $options = [];
        if ($success_callback) {
            $options['success'] = $success_callback;
        }
        if ($error_callback) {
            $options['error'] = $error_callback;
        }
        return $this->request($url, $options);
    }

    /**
     * Post.
     *
     * @param $url
     * @param array $data
     * @param null $success_callback
     * @param null $error_callback
     * @return mixed|Response
     * @throws Throwable
     */
    public function post($url, $data = [], $success_callback = null, $error_callback = null)
    {
        $options = [];
        if ($data) {
            $options['data'] = $data;
        }
        if ($success_callback) {
            $options['success'] = $success_callback;
        }
        if ($error_callback) {
            $options['error'] = $error_callback;
        }
        $options['method'] = 'POST';
        return $this->request($url, $options);
    }

    /**
     * Process.
     * User should not call this.
     *
     * @param $address
     * @return void
     * @throws Throwable
     */
    public function process($address)
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
                if (!empty($task['options']['error'])) {
                    call_user_func($task['options']['error'], $exception);
                } else {
                    throw $exception;
                }
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
            $address = $this->parseAddress($url, $options);
            $task = [
                'url' => $url,
                'options' => $options,
                'address' => $address
            ];
            $this->queueUnshift($address, $task);
            $this->process($address);
        })->once('error', function ($exception) use ($task, $client, $request) {
            $client->recycleConnectionFromRequest($request);
            if (!empty($task['options']['error'])) {
                call_user_func($task['options']['error'], $exception);
            } else {
                throw $exception;
            }
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

        $request->end('');
    }

    /**
     * Recycle connection from request.
     *
     * @param $request Request
     * @param null $response Response
     * @throws Throwable
     */
    public function recycleConnectionFromRequest($request, $response = null)
    {
        $connection = $request->getConnection();
        if (!$connection) {
            return;
        }
        $connection->onConnect = $connection->onClose = $connection->onMessage = $connection->onError = null;
        $request_header_connection = strtolower($request->getHeaderLine('Connection'));
        $response_header_connection = $response ? strtolower($response->getHeaderLine('Connection')) : '';
        // Close Connection without header Connection: keep-alive
        if ('keep-alive' !== $request_header_connection || 'keep-alive' !== $response_header_connection || $request->getProtocolVersion() !== '1.1') {
            $connection->close();
        }
        $request->detachConnection($connection);
        $this->_connectionPool->recycle($connection);
    }

    /**
     * Parse address from url.
     *
     * @param $url
     * @param $options
     * @return string
     */
    protected function parseAddress($url, $options)
    {
        $info = parse_url($url);
        if (empty($info) || !isset($info['host'])) {
            $e = new Exception("invalid url: $url");
            if (!empty($options['error'])) {
                call_user_func($options['error'], $e);
            }
        }
        $port = $info['port'] ?? (str_starts_with($url, 'https') ? 443 : 80);
        return "tcp://{$info['host']}:{$port}";
    }

    /**
     * Queue push.
     *
     * @param $address
     * @param $task
     */
    protected function queuePush($address, $task)
    {
        if (!isset($this->_queue[$address])) {
            $this->_queue[$address] = [];
        }
        $this->_queue[$address][] = $task;
    }

    /**
     * Queue unshift.
     *
     * @param $address
     * @param $task
     */
    protected function queueUnshift($address, $task)
    {
        if (!isset($this->_queue[$address])) {
            $this->_queue[$address] = [];
        }
        $this->_queue[$address] += [$task];
    }

    /**
     * Queue current item.
     *
     * @param $address
     * @return mixed|null
     */
    protected function queueCurrent($address)
    {
        if (empty($this->_queue[$address])) {
            return null;
        }
        reset($this->_queue[$address]);
        return current($this->_queue[$address]);
    }

    /**
     * Queue pop.
     *
     * @param $address
     */
    protected function queuePop($address)
    {
        unset($this->_queue[$address][key($this->_queue[$address])]);
        if (empty($this->_queue[$address])) {
            unset($this->_queue[$address]);
        }
    }
}
