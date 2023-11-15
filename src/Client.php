<?php

/**
 * @package     FrameX (FX) HttpClient Plugin
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


/**
 * FrameX default Http client
 */
class Client
{
    protected $curlOptions = [
        CURLOPT_AUTOREFERER => true, // Автоматически устанавливает поле Referer при переходе по ссылкам
        CURLOPT_COOKIESESSION => true, // Инициализирует новую сессию cookie
        CURLOPT_CERTINFO => true, // Включает вывод информации о сертификате в массиве информации cURL
        CURLOPT_CONNECT_ONLY => true, // Позволяет приложению использовать сокет для дальнейших отправок
        CURLOPT_CRLF => true, // Включает преобразование Unix-новых строк в CRLF-новые строки на Windows
        CURLOPT_DISALLOW_USERNAME_IN_URL => true, // Отключает передачу имени пользователя и пароля в URL
        CURLOPT_DNS_SHUFFLE_ADDRESSES => true, // Включает перемешивание IP-адресов DNS
        CURLOPT_HAPROXYPROTOCOL => true, // Включает поддержку протокола HAProxy PROXY
        CURLOPT_SSH_COMPRESSION => true, // Включает сжатие SSH
        CURLOPT_DNS_USE_GLOBAL_CACHE => true, // Включает глобальный кэш DNS
        CURLOPT_FAILONERROR => true, // Включает неудачное завершение при HTTP-коде >= 400
        CURLOPT_SSL_FALSESTART => true, // Включает False Start в TLS-подключениях
        CURLOPT_FILETIME => true, // Включает получение времени модификации удаленного документа

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

    public function request($uri, $method = 'GET', $parameters = [], $headers = [], $multipart = false)
    {
        $this->requestHeader = array_replace($this->requestHeader, (array)$headers);

        $this->requestArguments = [
            'uri' => $uri,
            'method' => $method,
            'parameters' => $parameters,
            'headers' => $this->requestHeader,
        ];

        $curl = curl_init();

        $curlOptions = $this->curlOptions;
        $curlOptions[CURLOPT_URL] = $uri;
        $curlOptions[CURLOPT_HTTPHEADER] = $this->prepareRequestHeaders();
        $curlOptions[CURLOPT_HEADERFUNCTION] = [$this, 'fetchResponseHeader'];

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
                $body_content = $multipart ? $parameters : http_build_query($parameters);
                if (
                    isset($this->requestHeader['Content-Type'])
                    && $this->requestHeader['Content-Type'] == 'application/json'
                ) {
                    $body_content = json_encode($parameters);
                }

                if ($method === 'POST') {
                    $curlOptions[CURLOPT_POST] = true;
                } else {
                    $curlOptions[CURLOPT_CUSTOMREQUEST] = $method;
                }
                $curlOptions[CURLOPT_POSTFIELDS] = $body_content;
                break;
        }

        curl_setopt_array($curl, $curlOptions);

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
        $curlOptions = $this->curlOptions;
        $curlOptions[CURLOPT_HEADERFUNCTION] = '*omitted';

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
                'opts' => $curlOptions,
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
