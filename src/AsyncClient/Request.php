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
use InvalidArgumentException;
use localzet\PSR\Http\Message\MessageInterface;
use localzet\PSR7\MultipartStream;
use localzet\PSR7\Uri;
use localzet\PSR7\UriResolver;
use localzet\Server\Connection\AsyncTcpConnection;
use Throwable;
use function localzet\PSR7\_parse_message;
use function localzet\PSR7\rewind_body;
use function localzet\PSR7\str;

/**
 * Класс Request представляет собой запрос, который может быть отправлен асинхронным клиентом HTTP. Он наследует от базового класса Request в PSR7.
 */
#[\AllowDynamicProperties]
class Request extends \localzet\PSR7\Request
{
    /**
     * @var AsyncTcpConnection|null
     * Соединение, используемое для отправки этого запроса.
     */
    protected ?AsyncTcpConnection $_connection = null;

    /**
     * @var Emitter|null
     * Эмиттер событий для этого запроса.
     */
    protected ?Emitter $_emitter = null;

    /**
     * @var Response|null
     * Ответ на этот запрос.
     */
    protected ?Response $_response = null;

    /**
     * @var string
     * Буфер для приема данных ответа.
     */
    protected string $_recvBuffer = '';

    /**
     * @var int
     * Ожидаемая длина ответа.
     */
    protected int $_expectedLength = 0;

    /**
     * @var int
     * Длина чанка в ответе.
     */
    protected int $_chunkedLength = 0;

    /**
     * @var string
     * Данные чанка в ответе.
     */
    protected string $_chunkedData = '';

    /**
     * @var bool
     * Флаг, указывающий, можно ли записывать в этот запрос.
     */
    protected bool $_writeable = true;

    /**
     * @var bool
     * Флаг, указывающий, является ли это соединение самостоятельным.
     */
    protected bool $_selfConnection = false;

    /**
     * @var array
     * Опции для этого запроса.
     */
    protected array $_options = [
        'headers' => [
            'Accept' => '*/*',
            'Cache-Control' => 'max-age=0',
            'Connection' => 'keep-alive',
            'Expect' => '',
            'Pragma' => '',
        ],
        'allow_redirects' => [
            'max' => 5
        ]
    ];

    /**
     * Конструктор запроса.
     * @param string $url URL-адрес для запроса.
     */
    public function __construct(string $url)
    {
        $this->_emitter = new Emitter();
        $headers = [
            'User-Agent' => 'localzet/http',
            'Connection' => 'keep-alive'
        ];
        parent::__construct('GET', $url, $headers, '');
    }

    /**
     * Устанавливает опции для этого запроса.
     * @param array $options Опции для установки.
     * @return $this Возвращает текущий объект запроса.
     */
    public function setOptions(array $options): static
    {
        $this->_options = array_merge($this->_options, $options);
        return $this;
    }

    /**
     * Получает опции этого запроса.
     * @return array Возвращает массив опций.
     */
    public function getOptions(): array
    {
        return $this->_options;
    }

    /**
     * Добавляет обработчик событий для данного события.
     * @param string $event Событие для добавления обработчика.
     * @param callable $callback Обработчик для добавления.
     * @return $this Возвращает текущий объект запроса.
     */
    public function on(string $event, callable $callback): static
    {
        $this->_emitter->on($event, $callback);
        return $this;
    }

    /**
     * Добавляет одноразовый обработчик событий для данного события.
     * @param string $event Событие для добавления обработчика.
     * @param callable $callback Обработчик для добавления.
     * @return $this Возвращает текущий объект запроса.
     */
    public function once(string $event, callable $callback): static
    {
        $this->_emitter->once($event, $callback);
        return $this;
    }

    /**
     * Вызывает все обработчики для данного события.
     * @param string $event Событие для вызова обработчиков.
     */
    public function emit(string $event): void
    {
        $args = func_get_args();
        call_user_func_array([$this->_emitter, 'emit'], $args);
    }

    /**
     * Удаляет обработчик событий для данного события.
     * @param string $event Событие для удаления обработчика.
     * @param callable $listener Обработчик для удаления.
     * @return $this Возвращает текущий объект запроса.
     */
    public function removeListener(string $event, callable $listener): static
    {
        $this->_emitter->removeListener($event, $listener);
        return $this;
    }

    /**
     * Удаляет все обработчики событий для данного события.
     * @param string|null $event Событие для удаления обработчиков. Если не указано, удаляются все обработчики.
     * @return $this Возвращает текущий объект запроса.
     */
    public function removeAllListeners(?string $event = null): static
    {
        $this->_emitter->removeAllListeners($event);
        return $this;
    }

    /**
     * Получает обработчики событий для данного события.
     * @param string $event Событие для получения обработчиков.
     * @return $this Возвращает текущий объект запроса.
     */
    public function listeners(string $event): static
    {
        $this->_emitter->listeners($event);
        return $this;
    }

    /**
     * Подключается к серверу для отправки этого запроса.
     * @throws Throwable Выбрасывает исключение, если происходит ошибка при подключении.
     * @throws Exception Выбрасывает исключение, если происходит ошибка при подключении.
     */
    protected function connect(): void
    {
        $host = $this->getUri()->getHost();
        $port = $this->getUri()->getPort();
        if (!$port) {
            $port = $this->getDefaultPort();
        }
        $context = [];
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
     * Записывает данные в тело этого запроса.
     * @param string $data Данные для записи.
     * @return $this Возвращает текущий объект запроса.
     * @throws Throwable Выбрасывает исключение, если происходит ошибка при записи данных.
     */
    public function write(string $data = ''): static
    {
        if (!$this->writeable()) {
            $this->emitError(new Exception('Request pending and can not send request again'));
            return $this;
        }

        if (empty($data) && $data !== '0') {
            return $this;
        }

        if (is_array($data)) {
            if (isset($data['multipart'])) {
                $multipart = new MultipartStream($data['multipart']);
                $this->withHeader('Content-Type', 'multipart/form-data; boundary=' . $multipart->getBoundary());
                $data = $multipart;
            } else {
                $data = http_build_query($data, '', '&');
            }
        }

        $this->getBody()->write($data);
        return $this;
    }

    /**
     * Записывает данные в ответ на этот запрос.
     * @param string $buffer Буфер данных для записи.
     */
    public function writeToResponse(string $buffer): void
    {
        $this->emit('progress', $buffer);
        $this->_response->getBody()->write($buffer);
    }

    /**
     * Завершает этот запрос, отправляя его и все оставшиеся данные.
     * @param string $data Данные для отправки.
     * @throws Throwable Выбрасывает исключение, если происходит ошибка при отправке запроса.
     */
    public function end(string $data = ''): void
    {
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

        if ((($data || $data === '0') || $this->getBody()->getSize()) && !$this->hasHeader('Content-Type')) {
            $this->withHeader('Content-Type', 'application/x-www-form-urlencoded');
        }

        if (!$this->_connection) {
            $this->connect();
        } else {
            if ($this->_connection->getStatus(false) === 'CONNECTING') {
                $this->_connection->onConnect = [$this, 'onConnect'];
                return;
            }
            $this->doSend();
        }
    }

    /**
     * Проверяет, можно ли записывать в этот запрос.
     * @return bool Возвращает true, если можно записывать, иначе false.
     */
    public function writeable(): bool
    {
        return $this->_writeable;
    }

    /**
     * Отправляет этот запрос.
     * @throws Throwable Выбрасывает исключение, если происходит ошибка при отправке запроса.
     */
    public function doSend(): void
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

    public function onConnect(): void
    {
        try {
            $this->doSend();
        } catch (Throwable $e) {
            $this->emitError($e);
        }
    }

    /**
     * Обрабатывает сообщение от сервера в ответ на этот запрос.
     * @param AsyncTcpConnection $connection Соединение, через которое было получено сообщение.
     * @param string $recv_buffer Буфер данных, полученных от сервера.
     * @throws Throwable Выбрасывает исключение, если происходит ошибка при обработке сообщения.
     */
    public function onMessage(AsyncTcpConnection $connection, string $recv_buffer): void
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
     * Проверяет, завершен ли ответ на этот запрос.
     * @param string $body Тело ответа для проверки.
     * @throws Throwable Выбрасывает исключение, если происходит ошибка при проверке завершенности.
     */
    protected function checkComplete(string $body): void
    {
        $status_code = $this->_response->getStatusCode();
        $content_length = $this->_response->getHeaderLine('Content-Length');
        if (
            $content_length === '0' || ($status_code >= 100 && $status_code < 200)
            || $status_code === 204 || $status_code === 304
        ) {
            $this->emitSuccess();
            return;
        }

        $transfer_encoding = $this->_response->getHeaderLine('Transfer-Encoding');
        // Chunked
        if ($transfer_encoding && !str_contains($transfer_encoding, 'identity')) {
            $this->_connection->onMessage = [$this, 'handleChunkedData'];
            $this->handleChunkedData($this->_connection, $body);
        } else {
            $this->_connection->onMessage = [$this, 'handleData'];
            $content_length = (int)$this->_response->getHeaderLine('Content-Length');
            if (!$content_length) {
                // Wait close
                $this->_connection->onClose = [$this, 'emitSuccess'];
            } else {
                $this->_expectedLength = $content_length;
            }
            $this->handleData($this->_connection, $body);
        }
    }

    /**
     * Обрабатывает данные, полученные в ответ на этот запрос.
     * @param AsyncTcpConnection $connection Соединение, через которое были получены данные.
     * @param string $data Данные для обработки.
     * @throws Throwable Выбрасывает исключение, если происходит ошибка при обработке данных.
     */
    public function handleData(AsyncTcpConnection $connection, string $data): void
    {
        try {
            $body = $this->_response->getBody();
            $this->writeToResponse($data);
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
     * Обрабатывает чанковые данные, полученные в ответ на этот запрос.
     * @param AsyncTcpConnection $connection Соединение, через которое были получены данные.
     * @param string $buffer Буфер данных для обработки.
     * @throws Throwable Выбрасывает исключение, если происходит ошибка при обработке данных.
     */
    public function handleChunkedData(AsyncTcpConnection $connection, string $buffer): void
    {
        try {
            if ($buffer !== '') {
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
                $this->writeToResponse(substr($this->_chunkedData, 0, $this->_chunkedLength - 2));
                $this->_chunkedData = substr($this->_chunkedData, $this->_chunkedLength);
                $this->_chunkedLength = 0;
                $this->handleChunkedData($connection, '');
            }
        } catch (Exception $e) {
            $this->emitError($e);
        }
    }

    /**
     * Обрабатывает ошибку, произошедшую во время обработки этого запроса.
     * @param AsyncTcpConnection $connection Соединение, в котором произошла ошибка.
     * @param int $code Код ошибки.
     * @param string $msg Сообщение об ошибке.
     * @throws Throwable Выбрасывает исключение, если происходит ошибка при обработке ошибки.
     */
    public function onError(AsyncTcpConnection $connection, int $code, string $msg): void
    {
        $this->emitError(new Exception($msg, $code));
    }

    /**
     * Вызывает событие 'success' для этого запроса.
     */
    public function emitSuccess(): void
    {
        $this->emit('success', $this->_response);
    }

    /**
     * Вызывает событие 'error' для этого запроса.
     * @param Exception $e Исключение, которое вызвало событие 'error'.
     * @throws Throwable Выбрасывает исключение, если происходит ошибка при вызове события 'error'.
     */
    public function emitError(Exception $e): void
    {
        try {
            $this->emit('error', $e);
        } finally {
            $this->_connection && $this->_connection->destroy();
        }
    }

    /**
     * Перенаправляет этот запрос на новый URL, если ответ на этот запрос содержит заголовок 'Location'.
     * @param Request $request Запрос для перенаправления.
     * @param Response $response Ответ на запрос.
     * @return false|MessageInterface Возвращает новый запрос, если перенаправление возможно, иначе false.
     * @throws Exception Выбрасывает исключение, если происходит ошибка при перенаправлении.
     */
    public static function redirect(Request $request, Response $response): bool|MessageInterface
    {
        if (
            !str_starts_with($response->getStatusCode(), '3')
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
     * Проверяет, не превышено ли максимальное количество перенаправлений для этого запроса.
     * @throws Exception Выбрасывает исключение, если превышено максимальное количество перенаправлений.
     */
    private static function guardMax(array &$options): void
    {
        $current = $options['__redirect_count'] ?? 0;
        $options['__redirect_count'] = $current + 1;
        $max = $options['allow_redirects']['max'];

        if ($options['__redirect_count'] > $max) {
            throw new Exception("Too many redirects. will not follow more than $max redirects");
        }
    }

    /**
     * Обрабатывает неожиданное закрытие соединения во время обработки этого запроса.
     * @throws Throwable Выбрасывает исключение, если происходит ошибка при обработке неожиданного закрытия.
     */
    public function onUnexpectClose(): void
    {
        $this->emitError(new Exception('The connection to ' . $this->_connection->getRemoteIp() . ' has been closed.'));
    }

    /**
     * Возвращает порт по умолчанию для этого запроса, в зависимости от схемы URI.
     * @return int Возвращает 443, если схема URI - 'https', иначе 80.
     */
    protected function getDefaultPort(): int
    {
        return ('https' === $this->getUri()->getScheme()) ? 443 : 80;
    }

    /**
     * Отсоединяет соединение от этого запроса.
     * @return void
     * @throws Throwable Выбрасывает исключение, если происходит ошибка при отсоединении соединения.
     */
    public function detachConnection(): void
    {
        $this->cleanConnection();
        // Соединения, которых нет в пуле соединений, отключаются.
        if ($this->_selfConnection) {
            $this->_connection->close();
            return;
        }
        $this->_writeable = true;
    }

    /**
     * Возвращает соединение, используемое для этого запроса.
     * @return AsyncTcpConnection|null Возвращает соединение или null, если соединение не установлено.
     */
    public function getConnection(): ?AsyncTcpConnection
    {
        return $this->_connection;
    }

    /**
     * Прикрепляет соединение к этому запросу.
     * @param $connection AsyncTcpConnection Соединение для прикрепления.
     * @return $this Возвращает текущий объект запроса.
     */
    public function attachConnection(AsyncTcpConnection $connection): static
    {
        $connection->onConnect = [$this, 'onConnect'];
        $connection->onMessage = [$this, 'onMessage'];
        $connection->onError = [$this, 'onError'];
        $connection->onClose = [$this, 'onUnexpectClose'];
        $this->_connection = $connection;

        return $this;
    }

    /**
     * Очищает соединение от этого запроса.
     */
    protected function cleanConnection(): void
    {
        $connection = $this->_connection;
        $connection->onConnect = $connection->onMessage = $connection->onError =
        $connection->onClose = $connection->onBufferFull = $connection->onBufferDrain = null;
        $this->_connection = null;
        $this->_emitter->removeAllListeners();
    }
}
