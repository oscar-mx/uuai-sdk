<?php

namespace UUAI\Sdk\Library;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;
use UUAI\Sdk\Sdk;

class CorpApiClient
{
    protected string $base_api = 'https://api-test.uuptai.com';

    protected string $client_id;
    protected string $corp_code;
    protected string $secret;
    protected Client $client;
    protected ?CacheInterface $cache = null;
    //默认缓存驱动配置
    protected array $default_cache_config = [
        'driver' => 'file',
        'setting' => [
            'dir' => __DIR__ . '/tmp/'
        ]
    ];

    public function __construct($corp_code = null, $secret = null, $client_id = null)
    {
        $this->corp_code = $corp_code;
        $this->secret = $secret;
        $this->client_id = $client_id;
    }

    public function setCache(?CacheInterface $config): CorpApiClient
    {
        try {
            $this->cache = $config;
            return $this;
        } catch (\Exception $e) {
            throw new \Exception('获取缓存实例失败', 500);
        }
    }

    /**
     * 获取http实例
     * @return Client
     */
    public function getClient(): Client
    {
        return new Client([
            'verify' => false,
            'base_uri' => $this->base_api,
            'headers' => [
                'Content-Type' => 'application/json',
            ]
        ]);
    }


    public function getSdk()
    {
        $sdk = new Sdk($this->client_id, '', $this->getAccessToken());
        $sdk->setCache($this->cache);
        return $sdk;
    }

    /**
     * 获取应用access_token
     * @return mixed
     * @throws GuzzleException
     */
    public function getAccessToken()
    {
        if (!$this->cache) {
            $this->setCache($this->cache);
        }
        return cache_has_set($this->cache, 'ai-sdk:authorizer:token:' . $this->corp_code, function () {
            $res = self::getClient()->get('/open/corp/token?authorizer_client=' . $this->corp_code . '&secret=' . $this->secret);
            $content = $res->getBody()->getContents();
            if ($res->getStatusCode() != 200) {
                throw new \Exception('请求失败', $res->getStatusCode());
            }
            // 这里应该做发放成功失败的检测
            return json_decode($content, true)['access_token'] ?? '';
        }, 7000);

    }

    /**
     * 统一请求方法
     *
     * @param       $method
     * @param       $uri
     * @param array $options
     *
     * @return array|ResponseInterface
     * @throws GuzzleException
     */
    public function request($method, $uri, array $options = [])
    {
        $request_options = [];
        $request_options[RequestOptions::HEADERS]['Authorization'] = 'Bearer ' . $this->getAccessToken();
        switch ($method) {
            case 'patch':
            case 'put':
            case 'delete':
            case 'post':
                $request_options[RequestOptions::HEADERS]['Content-Type'] = 'application/json';
                $request_options['form_params'] = $options;
                break;
            case 'get':
                $request_options[RequestOptions::QUERY] = $options;
                break;
            default:
                break;
        }
        try {
            $response = self::getClient()->request($method, $uri, $request_options);
        } catch (\Throwable $throwable) {
            p($request_options, '请求失败options');
            p($throwable->getMessage(), '请求失败log');
            throw new \Exception('远程请求失败', 500);
        }
        return $this->handleResponse($response);
    }

    /**
     * 响应数据格式转换
     *
     * @param ResponseInterface $response
     *
     * @return array
     */
    public function handleResponse(ResponseInterface $response): array
    {
        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @return string
     */
    public function getClientId(): string
    {
        return $this->client_id;
    }

    /**
     * @param string $client_id
     */
    public function setClientId(string $client_id): void
    {
        $this->client_id = $client_id;
    }

    /**
     * @return string
     */
    public function getCorpCode(): string
    {
        return $this->corp_code;
    }

    /**
     * @param string $corp_code
     */
    public function setCorpCode(string $corp_code): void
    {
        $this->corp_code = $corp_code;
    }

}