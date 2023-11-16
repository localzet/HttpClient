<?php

namespace localzet\HTTP\AsyncClient;

use Exception;
use localzet\Server\Connection\AsyncTcpConnection;
use Throwable;
use function localzet\PSR7\_parse_message;

class Handler
{
    public ?AsyncTcpConnection $connection;
    public ?Emitter $emitter;

    /**
     * @throws Throwable
     */
    public function onConnect(): void
    {
        try {
            $this->connection->doSend();
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
            $this->connection->_recvBuffer .= $recv_buffer;
            if (!strpos($this->connection->_recvBuffer, "\r\n\r\n")) {
                return;
            }

            $response_data = _parse_message($this->connection->_recvBuffer);

            if (!preg_match('/^HTTP\/.* [0-9]{3}( .*|$)/', $response_data['start-line'])) {
                throw new InvalidArgumentException('Invalid response string: ' . $response_data['start-line']);
            }
            $parts = explode(' ', $response_data['start-line'], 3);

            $this->connection->_response = new Response(
                $parts[1],
                $response_data['headers'],
                '',
                explode('/', $parts[0])[1],
                $parts[2] ?? null
            );

            $this->connection->checkComplete($response_data['body']);
        } catch (Exception $e) {
            $this->connection->emitError($e);
        }
    }

    /**
     * onError.
     * @throws Throwable
     */
    public function onError($connection, $code, $msg): void
    {
        $this->emitError(new Exception($msg, $code));
    }

    /**
     * onUnexpectClose.
     * @throws Throwable
     */
    public function onClose(): void
    {
        $this->emitError(new Exception('Connection closed'));
    }

    /**
     */
    private function emitSuccess()
    {
        $this->emitter->emit('success', $this->response);
    }

    /**
     * @throws Throwable
     */
    private function emitError(Throwable $exception)
    {
        try {
            $this->emitter->emit('error', $exception);
        } finally {
            $this->connection && $this->connection->destroy();
        }
    }
}