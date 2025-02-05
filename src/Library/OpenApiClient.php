<?php

namespace UUAI\Sdk\Library;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;

class OpenApiClient
{
    protected string $base_api = 'https://api-test.uuptai.com';
    protected string $client_id;
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
    /**
     * @var mixed|null
     */
    private $corpAccessToken;

    public function __construct($app_id = null, $secret = null, $corp_access_token = null)
    {
        $this->client_id = $app_id;
        $this->secret = $secret;
        $this->corpAccessToken = $corp_access_token;

    }

    public function setCache(?CacheInterface $cache): OpenApiClient
    {
        try {
            $this->cache = $cache;
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

    /**
     * 获取应用access_token
     * @return mixed
     * @throws GuzzleException
     */
    public function getAccessToken()
    {
        if (!$this->cache) {
            $this->setCache($this->default_cache_config);
        }
        return cache_has_set($this->cache, 'ai-sdk:getAccessToken:' . $this->client_id, function () {
            if (!empty($this->client_id)) {
                $res = self::getClient()->get('/open/auth/token?client_id=' . $this->client_id . '&secret=' . $this->secret);
            } else {
                $res = self::getClient()->get('/open/authorizer/token?client_id=' . $this->client_id . '&corp_access_token=' . $this->corpAccessToken);
            }
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
        switch (strtolower($method)) {
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
        //返回页面
        // 针对 /open/apis/pay/confirm 确认订单支付页
        if ($response->getHeader('Content-Type') == 'text/html') {
            return $response;
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

}