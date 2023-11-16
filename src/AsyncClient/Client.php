<?php

namespace localzet\HTTP\AsyncClient;

use Exception;
use localzet\Server\Connection\AsyncTcpConnection;
use localzet\Server\Connection\TcpConnection;
use RuntimeException;
use stdClass;
use Throwable;

class Client
{
    /**
     * Встроенные протоколы
     *
     * @var array<string,string>
     */
    public const BUILD_IN_TRANSPORTS = [
        'tcp' => 'tcp',
        'udp' => 'udp',
        'unix' => 'unix',
        'ssl' => 'tcp',
    ];

    public ?AsyncTcpConnection $connection;

    /**
     * @throws Exception
     * @throws Throwable
     */
    public function connect(string $url, array $contextOption = []): void
    {
        $parts = parse_url($url);
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        $host = isset($parts['host']) ? strtolower($parts['host']) : '';
        $port = $parts['port'] ?? (str_starts_with($url, 'https') ? 443 : 80);

        if (!isset(self::BUILD_IN_TRANSPORTS[$scheme]) || self::BUILD_IN_TRANSPORTS[$scheme] !== 'tcp') {
            throw new RuntimeException('Некорректная схема: ' . var_export($scheme, true));
        }

        if ($scheme !== 'https') {
            unset($contextOption['ssl']);
        }

        $connection = new AsyncTcpConnection("tcp://$host:$port", $contextOption);

        if ($scheme === 'https') {
            $connection->transport = 'ssl';
        }

        $this->attachConnection($connection);
        $this->connection->connect();
    }

    /**
     * @param $connection AsyncTcpConnection
     * @return $this
     */
    public function attachConnection(AsyncTcpConnection $connection): static
    {
        $handler = new Handler();
        $connection->handler = $handler;

        $emitter = new Emitter();
        $connection->emitter = $emitter;

        $callbackMap = [
            'onConnect',
            'onWebSocketConnect',
            'onMessage',
            'onClose',
            'onError',
            'onBufferFull',
            'onBufferDrain',
        ];
        foreach ($callbackMap as $name) {
            if (method_exists($handler, $name)) {
                $connection->$name = [$handler, $name];
            }
        }

        $handler->connection = $connection;
        $this->connection = $connection;

        return $this;
    }

    /**
     * @return void
     * @throws Throwable
     * @throws Throwable
     * @throws Throwable
     */
    public function detachConnection(): void
    {
        $connection = $this->connection;

        $callbackMap = [
            'onConnect',
            'onWebSocketConnect',
            'onMessage',
            'onClose',
            'onError',
            'onBufferFull',
            'onBufferDrain',
        ];
        foreach ($callbackMap as $name) {
            $connection->$name = null;
        }

        $this->_connection = null;
        $this->_emitter->removeAllListeners();

        if ($this->_selfConnection) {
            $this->$this->connection->close();
            return;
        }

        $this->_writeable = true;
    }
}