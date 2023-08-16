<?php
/**
 * @package     WebCore HTTP Client
 * @link        https://localzet.gitbook.io
 *
 * @author      localzet <creator@localzet.ru>
 *
 * @copyright   Copyright (c) 2018-2020 Zorin Projects
 * @copyright   Copyright (c) 2020-2022 NONA Team
 *
 * @license     https://www.localzet.ru/license GNU GPLv3 License
 */

namespace localzet\HTTP;

use AllowDynamicProperties;
use Exception;
use InvalidArgumentException;
use localzet\PSR\Http\Message\MessageInterface;
use localzet\PSR7\Uri;
use localzet\PSR7\UriResolver;
use localzet\Server\Connection\AsyncTcpConnection;
use Throwable;
use function localzet\PSR7\_parse_message;
use function localzet\PSR7\rewind_body;
use function localzet\PSR7\str;

/**
 * Class Request
 * @package localzet\HTTP
 */
#[AllowDynamicProperties]
class Request extends \localzet\PSR7\Request
{
    /**
     * @var AsyncTcpConnection
     */
    protected $_connection = null;

    /**
     * @var Emitter
     */
    protected $_emitter = null;

    /**
     * @var Response
     */
    protected $_response = null;

    /**
     * @var string
     */
    protected $_recvBuffer = '';

    /**
     * @var int
     */
    protected $_expectedLength = 0;

    /**
     * @var int
     */
    protected $_chunkedLength = 0;

    /**
     * @var string
     */
    protected $_chunkedData = '';

    /**
     * @var bool
     */
    protected $_writeable = true;

    /**
     * @var bool
     */
    protected $_selfConnection = false;

    /**
     * @var array
     */
    protected $_options = [
        'allow_redirects' => [
            'max' => 5
        ]
    ];

    /**
     * Request constructor.
     * @param string $url
     */
    public function __construct(string $url)
    {
        $this->_emitter = new Emitter();
        $headers = [
            'User-Agent' => 'Localzet HTTP Client',
            'Connection' => 'keep-alive'
        ];
        parent::__construct('GET', $url, $headers, '');
    }

    /**
     * @param $options
     * @return $this
     */
    public function setOptions($options)
    {
        $this->_options = array_merge($this->_options, $options);
        return $this;
    }

    /**
     * @return array
     */
    public function getOptions()
    {
        return $this->_options;
    }

    /**
     * @param $event
     * @param $callback
     * @return $this
     */
    public function on($event, $callback)
    {
        $this->_emitter->once($event, $callback);
        return $this;
    }

    /**
     * @param $event
     */
    public function emit($event)
    {
        $args = func_get_args();
        call_user_func_array(array($this->_emitter, 'emit'), $args);
    }

    /**
     * @param $event
     * @param $listener
     * @return $this
     */
    public function removeListener($event, $listener)
    {
        $this->_emitter->removeListener($event, $listener);
        return $this;
    }

    /**
     * @param null $event
     * @return $this
     */
    public function removeAllListeners($event = null)
    {
        $this->_emitter->removeAllListeners($event);
        return $this;
    }

    /**
     * @param $event
     * @return $this
     */
    public function listeners($event)
    {
        $this->_emitter->listeners($event);
        return $this;
    }

    /**
     * Connect.
     * @throws Throwable
     * @throws Exception
     */
    protected function connect()
    {
        $host = $this->getUri()->getHost();
        $port = $this->getUri()->getPort();
        if (!$port) {
            $port = $this->getDefaultPort();
        }
        $context = array();
        if (!empty($this->_options['context'])) {
            $context = $this->_options['context'];
        }
        $ssl = $this->getUri()->getScheme() === 'https';
        if (!$ssl) {
            unset($context['ssl']);
        }
        $connection = new AsyncTcpConnection("tcp://$host:$port", $context);
        if ($ssl) {
            $connection->transport = 'ssl';
        }
        $this->attachConnection($connection);
        $this->_selfConnection = true;
        $connection->connect();
    }

    /**
     * @param string $data
     * @return $this
     * @throws Throwable
     */
    public function write(string $data = '')
    {
        if (!$this->writeable()) {
            $this->emitError(new Exception('Request pending and can not send request again'));
            return $this;
        }

        if (empty($data) && $data !== '0' && $data !== 0) {
            return $this;
        }

        if (is_array($data)) {
            $data = http_build_query($data, '', '&');
        }

        $this->getBody()->write($data);
        return $this;
    }

    /**
     * @param string $data
     * @throws Throwable
     */
    public function end(string $data = '')
    {
        if (($data || $data === '0' || $data === 0) || $this->getBody()->getSize()) {
            if (isset($this->_options['headers'])) {
                $headers = array_change_key_case($this->_options['headers']);
                if (!isset($headers['content-type'])) {
                    $this->withHeader('Content-Type', 'application/x-www-form-urlencoded');
                }
            } else {
                $this->withHeader('Content-Type', 'application/x-www-form-urlencoded');
            }
        }
        if (isset($this->_options['version'])) {
            $this->withProtocolVersion($this->_options['version']);
        }

        if (isset($this->_options['method'])) {
            $this->withMethod($this->_options['method']);
        }

        if (isset($this->_options['headers'])) {
            $this->withHeaders($this->_options['headers']);
        }

        $query = $this->_options['query'] ?? '';
        if ($query || $query === '0') {
            if (is_array($query)) {
                $query = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            }
            $uri = $this->getUri()->withQuery($query);
            $this->withUri($uri);
        }

        if ($data !== '') {
            $this->write($data);
        }
        if (!$this->_connection) {
            $this->connect();
        } else {
            if ($this->_connection->getStatus(false) === 'CONNECTING') {
                $this->_connection->onConnect = array($this, 'onConnect');
                return;
            }
            $this->doSend();
        }
    }

    /**
     * @return bool
     */
    public function writeable()
    {
        return $this->_writeable;
    }

    /**
     * @throws Throwable
     */
    public function doSend()
    {
        if (!$this->writeable()) {
            $this->emitError(new Exception('Request pending and can not send request again'));
            return;
        }

        $this->_writeable = false;

        $body_size = $this->getBody()->getSize();
        if ($body_size) {
            $this->withHeaders(['Content-Length' => $body_size]);
        }

        $package = str($this);
        $this->_connection->send($package);
    }

    /**
     * @throws Throwable
     */
    public function onConnect(): void
    {
        try {
            $this->doSend();
        } catch (Throwable $e) {
            $this->emitError($e);
        }
    }

    /**
     * @param $connection
     * @param $recv_buffer
     * @throws Throwable
     */
    public function onMessage($connection, $recv_buffer)
    {
        try {
            $this->_recvBuffer .= $recv_buffer;
            if (!strpos($this->_recvBuffer, "\r\n\r\n")) {
                return;
            }

            $response_data = _parse_message($this->_recvBuffer);

            if (!preg_match('/^HTTP\/.* [0-9]{3}( .*|$)/', $response_data['start-line'])) {
                throw new InvalidArgumentException('Invalid response string: ' . $response_data['start-line']);
            }
            $parts = explode(' ', $response_data['start-line'], 3);

            $this->_response = new Response(
                $parts[1],
                $response_data['headers'],
                '',
                explode('/', $parts[0])[1],
                $parts[2] ?? null
            );

            $this->checkComplete($response_data['body']);
        } catch (Exception $e) {
            $this->emitError($e);
        }
    }

    /**
     * @param $body
     * @throws Throwable
     */
    protected function checkComplete($body)
    {
        $status_code = $this->_response->getStatusCode();
        $content_length = $this->_response->getHeaderLine('Content-Length');
        if ($content_length === '0' || ($status_code >= 100 && $status_code < 200)
            || $status_code === 204 || $status_code === 304) {
            $this->emitSuccess();
            return;
        }

        $transfer_encoding = $this->_response->getHeaderLine('Transfer-Encoding');
        // Chunked
        if ($transfer_encoding && !str_contains($transfer_encoding, 'identity')) {
            $this->_connection->onMessage = array($this, 'handleChunkedData');
            $this->handleChunkedData($this->_connection, $body);
        } else {
            $this->_connection->onMessage = array($this, 'handleData');
            $content_length = (int)$this->_response->getHeaderLine('Content-Length');
            if (!$content_length) {
                // Wait close
                $this->_connection->onClose = array($this, 'emitSuccess');
            } else {
                $this->_expectedLength = $content_length;
            }
            $this->handleData($this->_connection, $body);
        }
    }

    /**
     * @param $connection
     * @param $data
     * @throws Throwable
     */
    public function handleData($connection, $data)
    {
        try {
            $body = $this->_response->getBody();
            $body->write($data);
            if ($this->_expectedLength) {
                $recv_length = $body->getSize();
                if ($this->_expectedLength <= $recv_length) {
                    $this->emitSuccess();
                }
            }
        } catch (Exception $e) {
            $this->emitError($e);
        }
    }

    /**
     * @param $connection
     * @param $buffer
     * @throws Throwable
     */
    public function handleChunkedData($connection, $buffer)
    {
        try {
            if ($buffer) {
                $this->_chunkedData .= $buffer;
            }

            $recv_len = strlen($this->_chunkedData);
            if ($recv_len < 2) {
                return;
            }
            // Get chunked length
            if ($this->_chunkedLength === 0) {
                $crlf_position = strpos($this->_chunkedData, "\r\n");
                if ($crlf_position === false && strlen($this->_chunkedData) > 1024) {
                    $this->emitError(new Exception('bad chunked length'));
                    return;
                }

                if ($crlf_position === false) {
                    return;
                }
                $length_chunk = substr($this->_chunkedData, 0, $crlf_position);
                if (str_contains($crlf_position, ';')) {
                    list($length_chunk) = explode(';', $length_chunk, 2);
                }
                $length = hexdec(ltrim(trim($length_chunk), "0"));
                if ($length === 0) {
                    $this->emitSuccess();
                    return;
                }
                $this->_chunkedLength = $length + 2;
                $this->_chunkedData = substr($this->_chunkedData, $crlf_position + 2);
                $this->handleChunkedData($connection, '');
                return;
            }
            // Get chunked data
            if ($recv_len >= $this->_chunkedLength) {
                $this->_response->getBody()->write(substr($this->_chunkedData, 0, $this->_chunkedLength - 2));
                $this->_chunkedData = substr($this->_chunkedData, $this->_chunkedLength);
                $this->_chunkedLength = 0;
                $this->handleChunkedData($connection, '');
            }
        } catch (Exception $e) {
            $this->emitError($e);
        }
    }

    /**
     * onError.
     * @throws Throwable
     */
    public function onError($connection, $code, $msg)
    {
        $this->emitError(new Exception($msg, $code));
    }

    /**
     * emitSuccess.
     */
    public function emitSuccess()
    {
        $this->emit('success', $this->_response);
    }

    /**
     * @throws Throwable
     */
    public function emitError($e)
    {
        try {
            $this->emit('error', $e);
        } finally {
            $this->_connection && $this->_connection->destroy();
        }
    }

    /**
     * @param $request Request
     * @param $response Response
     * @return false|MessageInterface
     * @throws Exception
     * @throws Exception
     * @throws Exception
     */
    public static function redirect(Request $request, Response $response)
    {
        if (!str_starts_with($response->getStatusCode(), '3')
            || !$response->hasHeader('Location')
        ) {
            return false;
        }
        $options = $request->getOptions();
        self::guardMax($options);
        $location = UriResolver::resolve(
            $request->getUri(),
            new Uri($response->getHeaderLine('Location'))
        );
        rewind_body($request);

        return (new Request($location))->setOptions($options)->withBody($request->getBody());
    }

    /**
     * @throws Exception
     */
    private static function guardMax(array &$options)
    {
        $current = $options['__redirect_count'] ?? 0;
        $options['__redirect_count'] = $current + 1;
        $max = $options['allow_redirects']['max'];

        if ($options['__redirect_count'] > $max) {
            throw new Exception("Too many redirects. will not follow more than $max redirects");
        }
    }

    /**
     * onUnexpectClose.
     * @throws Throwable
     */
    public function onUnexpectClose()
    {
        $this->emitError(new Exception('Connection closed'));
    }

    /**
     * @return int
     */
    protected function getDefaultPort()
    {
        return ('https' === $this->getUri()->getScheme()) ? 443 : 80;
    }

    /**
     * detachConnection.
     *
     * @return void
     * @throws Throwable
     * @throws Throwable
     * @throws Throwable
     */
    public function detachConnection()
    {
        $this->cleanConnection();
        // 不是连接池的连接则断开
        if ($this->_selfConnection) {
            $this->_connection->close();
            return;
        }
        $this->_writeable = true;
    }

    /**
     * @return AsyncTcpConnection|null
     */
    public function getConnection()
    {
        return $this->_connection;
    }

    /**
     * attachConnection.
     *
     * @param $connection AsyncTcpConnection
     * @return $this
     */
    public function attachConnection(AsyncTcpConnection $connection): static
    {
        $connection->onConnect = array($this, 'onConnect');
        $connection->onMessage = array($this, 'onMessage');
        $connection->onError = array($this, 'onError');
        $connection->onClose = array($this, 'onUnexpectClose');
        $this->_connection = $connection;

        return $this;
    }

    /**
     * cleanConnection.
     */
    protected function cleanConnection()
    {
        $connection = $this->_connection;
        $connection->onConnect = $connection->onMessage = $connection->onError =
        $connection->onClose = $connection->onBufferFull = $connection->onBufferDrain = null;
        $this->_connection = null;
        $this->_emitter->removeAllListeners();
    }
}