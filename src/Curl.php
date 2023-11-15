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
class Curl
{
    /**
     * Default curl options
     *
     * These defaults options can be overwritten when sending requests.
     *
     * See setCurlOptions()
     *
     * @var array
     */
    protected $curlOptions = [
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLINFO_HEADER_OUT => true,
        CURLOPT_ENCODING => 'identity',
        // phpcs:ignore
        CURLOPT_USERAGENT => 'Localzet HTTP Client',
    ];

    /**
     * Method request() arguments
     *
     * This is used for debugging.
     *
     * @var array
     */
    protected $requestArguments = [];

    /**
     * Default request headers
     *
     * @var array
     */
    protected $requestHeader = [
        'Accept' => '*/*',
        'Cache-Control' => 'max-age=0',
        'Connection' => 'keep-alive',
        'Expect' => '',
        'Pragma' => '',
    ];

    /**
     * Raw response returned by server
     *
     * @var string
     */
    protected $responseBody = '';

    /**
     * Headers returned in the response
     *
     * @var array
     */
    protected $responseHeader = [];

    /**
     * Response HTTP status code
     *
     * @var int
     */
    protected $responseHttpCode = 0;

    /**
     * Last curl error number
     *
     * @var mixed
     */
    protected $responseClientError = null;

    /**
     * Information about the last transfer
     *
     * @var mixed
     */
    protected $responseClientInfo = [];

    /**
     * {@inheritdoc}
     */
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

    /**
     * Get response details
     *
     * @return array Map structure of details
     */
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

    /**
     * Reset curl options
     *
     * @param array $curlOptions
     */
    public function setCurlOptions($curlOptions)
    {
        foreach ($curlOptions as $opt => $value) {
            $this->curlOptions[$opt] = $value;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getResponseBody()
    {
        return $this->responseBody;
    }

    /**
     * {@inheritdoc}
     */
    public function getResponseHeader()
    {
        return $this->responseHeader;
    }

    /**
     * {@inheritdoc}
     */
    public function getResponseHttpCode()
    {
        return $this->responseHttpCode;
    }

    /**
     * {@inheritdoc}
     */
    public function getResponseClientError()
    {
        return $this->responseClientError;
    }

    /**
     * @return array
     */
    protected function getResponseClientInfo()
    {
        return $this->responseClientInfo;
    }

    /**
     * Returns method request() arguments
     *
     * This is used for debugging.
     *
     * @return array
     */
    protected function getRequestArguments()
    {
        return $this->requestArguments;
    }

    /**
     * Fetch server response headers
     *
     * @param mixed $curl
     * @param string $header
     *
     * @return int
     */
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

    /**
     * Convert request headers to the expect curl format
     *
     * @return array
     */
    protected function prepareRequestHeaders()
    {
        $headers = [];

        foreach ($this->requestHeader as $header => $value) {
            $headers[] = trim($header) . ': ' . trim($value);
        }

        return $headers;
    }
}
