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


/**
 * FrameX default Http client
 */
class Client
{
    protected $curlOptions = [
//        CURLOPT_AUTOREFERER => true, // Автоматически устанавливает поле Referer при переходе по ссылкам
//        CURLOPT_COOKIESESSION => true, // Инициализирует новую сессию cookie
//        CURLOPT_CERTINFO => true, // Включает вывод информации о сертификате в массиве информации cURL
//        CURLOPT_CONNECT_ONLY => true, // Позволяет приложению использовать сокет для дальнейших отправок
//        CURLOPT_CRLF => true, // Включает преобразование Unix-новых строк в CRLF-новые строки на Windows
//        CURLOPT_DISALLOW_USERNAME_IN_URL => true, // Отключает передачу имени пользователя и пароля в URL
//        CURLOPT_DNS_SHUFFLE_ADDRESSES => true, // Включает перемешивание IP-адресов DNS
//        CURLOPT_HAPROXYPROTOCOL => true, // Включает поддержку протокола HAProxy PROXY
//        CURLOPT_SSH_COMPRESSION => true, // Включает сжатие SSH
//        CURLOPT_DNS_USE_GLOBAL_CACHE => true, // Включает глобальный кэш DNS
//        CURLOPT_FAILONERROR => true, // Включает неудачное завершение при HTTP-коде >= 400
//        CURLOPT_SSL_FALSESTART => true, // Включает False Start в TLS-подключениях
//        CURLOPT_FILETIME => true, // Включает получение времени модификации удаленного документа

        CURLOPT_TIMEOUT => 30, // Устанавливает максимальное время ожидания выполнения функций cURL
        CURLOPT_CONNECTTIMEOUT => 30, // Устанавливает количество секунд, которое cURL должен ждать при попытке подключения
        CURLOPT_SSL_VERIFYPEER => false, // Отключает проверку SSL сертификата
        CURLOPT_SSL_VERIFYHOST => false, // Отключает проверку имени хоста в сертификате SSL
        CURLOPT_RETURNTRANSFER => true, // Возвращает результат передачи в виде строки из curl_exec() вместо вывода его непосредственно
        CURLOPT_FOLLOWLOCATION => true, // Следует за любым заголовком "Location: ", отправленным сервером в своем ответе

        CURLOPT_MAXREDIRS => 5, // Определяет максимальное количество редиректов, которые cURL должен следовать
        CURLINFO_HEADER_OUT => true, // Включает вывод заголовка в данные информации
        CURLOPT_ENCODING => 'identity', // Устанавливает заголовок "Accept-Encoding: "
        CURLOPT_USERAGENT => 'Localzet HTTP Client', // Содержимое заголовка "User-Agent: ", используемого в HTTP-запросе
    ];


    protected $requestArguments = [];
    protected $requestHeader = [
        'Accept' => '*/*',
        'Cache-Control' => 'max-age=0',
        'Connection' => 'keep-alive',
        'Expect' => '',
        'Pragma' => '',
    ];

    protected $responseBody = '';
    protected $responseHeader = [];
    protected $responseHttpCode = 0;
    protected $responseClientError = null;
    protected $responseClientInfo = [];

    public function request($uri, $method = 'GET', $parameters = [], $headers = [], $multipart = false, $curlOptions = [])
    {
        $this->requestHeader = array_replace($this->requestHeader, (array)$headers);

        $this->requestArguments = [
            'uri' => $uri,
            'method' => $method,
            'parameters' => $parameters,
            'headers' => $this->requestHeader,
        ];

        $curl = curl_init();

        // Объедините опции cURL по умолчанию с любыми пользовательскими опциями
        $curlOptions = $curlOptions + $this->curlOptions;

        switch ($method) {
            case 'GET':
            case 'DELETE':
                $uri .= (strpos($uri, '?') ? '&' : '?') . http_build_query($parameters);
                if ($method === 'DELETE') {
                    $curlOptions[CURLOPT_CUSTOMREQUEST] = 'DELETE';
                }
                break;
            case 'PUT':
            case 'POST':
            case 'PATCH':
            default:

                if ($method === 'POST') {
                    $curlOptions[CURLOPT_POST] = true;
                } else {
                    $curlOptions[CURLOPT_CUSTOMREQUEST] = $method;
                }

                if ($parameters) {
                    $body_content = $multipart ? $parameters : http_build_query($parameters);
                    if (
                        isset($this->requestHeader['Content-Type'])
                        && $this->requestHeader['Content-Type'] == 'application/json'
                    ) {
                        $body_content = json_encode($parameters);
                    }
                    $curlOptions[CURLOPT_POSTFIELDS] = $body_content;
                }

                break;
        }

        $curlOptions[CURLOPT_URL] = $uri;
        $curlOptions[CURLOPT_HTTPHEADER] = $this->prepareRequestHeaders();
        $curlOptions[CURLOPT_HEADERFUNCTION] = [$this, 'fetchResponseHeader'];

//        curl_setopt_array($curl, $curlOptions);

        foreach ($curlOptions as $key => $value) {
            curl_setopt($curl, $key, $value);
        }

        $response = curl_exec($curl);

        $this->responseBody = $response;
        $this->responseHttpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $this->responseClientError = curl_error($curl);
        $this->responseClientInfo = curl_getinfo($curl);

        curl_close($curl);

        return $this->responseBody;
    }

    public function getResponse()
    {
        return [
            'request' => $this->getRequestArguments(),
            'response' => [
                'code' => $this->getResponseHttpCode(),
                'headers' => $this->getResponseHeader(),
                'body' => $this->getResponseBody(),
            ],
            'client' => [
                'error' => $this->getResponseClientError(),
                'info' => $this->getResponseClientInfo(),
            ],
        ];
    }

    public function getResponseBody()
    {
        return $this->responseBody;
    }

    public function getResponseHeader()
    {
        return $this->responseHeader;
    }

    public function getResponseHttpCode()
    {
        return $this->responseHttpCode;
    }

    public function getResponseClientError()
    {
        return $this->responseClientError;
    }

    protected function getResponseClientInfo()
    {
        return $this->responseClientInfo;
    }

    protected function getRequestArguments()
    {
        return $this->requestArguments;
    }

    protected function fetchResponseHeader($curl, $header)
    {
        $pos = strpos($header, ':');

        if (!empty($pos)) {
            $key = str_replace('-', '_', strtolower(substr($header, 0, $pos)));

            $value = trim(substr($header, $pos + 2));

            $this->responseHeader[$key] = $value;
        }

        return strlen($header);
    }

    protected function prepareRequestHeaders()
    {
        $headers = [];

        foreach ($this->requestHeader as $header => $value) {
            $headers[] = trim($header) . ': ' . trim($value);
        }

        return $headers;
    }
}
