<?php


namespace App\Services;

use Phalcon\Logger\Adapter\File as FileLogger;

/**
 * 火山引擎视频点播(Volcengine VOD)客户端
 *
 * 参考官方 volc-sdk-golang / volc-sdk-python 的 SigV4 签名实现：
 * - Endpoint: https://vod.volcengineapi.com
 * - Service: vod / Region: cn-north-1
 * - Authorization: HMAC-SHA256 Credential={AK}/{date}/{region}/vod/request, SignedHeaders=..., Signature=...
 */
class VolcVodClient extends Service
{

    const HOST = 'vod.volcengineapi.com';

    const SCHEME = 'https';

    const SERVICE = 'vod';

    /**
     * @var array
     */
    protected $settings;

    /**
     * @var string
     */
    protected $accessKey;

    /**
     * @var string
     */
    protected $secretKey;

    /**
     * @var string
     */
    protected $region;

    /**
     * @var string
     */
    protected $spaceName;

    /**
     * @var string
     */
    protected $transTemplateId;

    /**
     * @var string
     */
    protected $callbackKey;

    /**
     * @var FileLogger
     */
    protected $logger;

    public function __construct()
    {
        $this->settings = $this->getSettings('vod');

        $this->accessKey = $this->settings['volc_ak'] ?? '';
        $this->secretKey = $this->settings['volc_sk'] ?? '';
        $this->region = $this->settings['volc_region'] ?: 'cn-north-1';
        $this->spaceName = $this->settings['volc_space_name'] ?? '';
        $this->transTemplateId = $this->settings['volc_trans_template'] ?? '';
        $this->callbackKey = $this->settings['volc_callback_key'] ?? '';

        $this->logger = $this->getLogger('vod');
    }

    /**
     * 通用请求
     *
     * @param string $action
     * @param array $params
     * @param string $version
     * @param string $method
     * @param string $body
     * @return array|bool
     */
    public function request($action, $params = [], $version = '2020-08-01', $method = 'GET', $body = '')
    {
        if (empty($this->accessKey) || empty($this->secretKey)) {
            throw new \RuntimeException('请先配置火山引擎点播AccessKey和SecretKey');
        }

        $method = strtoupper($method);

        $query = array_merge(['Action' => $action, 'Version' => $version], $params);

        $canonicalQuery = $this->createCanonicalQuery($query);

        if ($method == 'POST') {
            if (empty($body)) {
                $body = '{}';
            }
            $contentType = 'application/json';
        } else {
            $body = '';
            $contentType = 'application/x-www-form-urlencoded; charset=utf-8';
        }

        $date = gmdate('Ymd\THis\Z');

        $bodyHash = hash('sha256', $body);

        $headers = [
            'Host' => self::HOST,
            'Content-Type' => $contentType,
            'X-Date' => $date,
            'X-Content-Sha256' => $bodyHash,
        ];

        [$canonicalHeaders, $signedHeaders] = $this->createCanonicalHeaders($headers);

        $datePart = substr($date, 0, 8);

        $credentialScope = $datePart . '/' . $this->region . '/' . self::SERVICE . '/request';

        $canonicalRequest = $method . "\n" .
            '/' . "\n" .
            $canonicalQuery . "\n" .
            $canonicalHeaders . "\n" .
            $signedHeaders . "\n" .
            $bodyHash;

        $stringToSign = "HMAC-SHA256\n{$date}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $signKey = $this->createSignKey($datePart);

        $signature = hash_hmac('sha256', $stringToSign, $signKey);

        $authorization = 'HMAC-SHA256 Credential=' . $this->accessKey . '/' . $credentialScope .
            ', SignedHeaders=' . $signedHeaders .
            ', Signature=' . $signature;

        $url = self::SCHEME . '://' . self::HOST . '/?' . $canonicalQuery;

        $headers['Authorization'] = $authorization;

        $this->logger->debug("Volc {$method} {$action} Request: " . kg_json_encode([
                'url' => $url,
                'headers' => $headers,
                'body' => $body,
            ]));

        try {

            $response = $this->sendHttp($method, $url, $headers, $body);

            $this->logger->debug("Volc {$action} Response: " . $response);

            $data = json_decode($response, true);

            if (!isset($data['ResponseMetadata'])) {
                return false;
            }

            $error = $data['ResponseMetadata']['Error'] ?? null;

            if (!empty($error['Code'])) {
                $msg = sprintf('%s: %s', $error['Code'], $error['Message'] ?? '');
                $this->logger->error("Volc {$action} Error Response: " . $msg);
                throw new \RuntimeException($msg);
            }

            return $data['Result'] ?? [];

        } catch (\Throwable $e) {

            $this->logger->error("Volc {$action} Exception: " . kg_json_encode([
                    'message' => $e->getMessage(),
                ]));

            return false;
        }
    }

/**
     * 申请直传凭证
     *
     * @param string $fileName
     * @param int $fileSize
     * @param string $fileType
     * @return array|bool
     */
    public function applyUpload($fileName, $fileSize = 0, $fileType = 'video')
    {
        $params = [
            'SpaceName' => $this->spaceName,
            'FileType' => $fileType,
        ];

        $result = $this->request('ApplyUploadInfo', $params, '2020-08-01', 'GET');

        if (!$result) return false;

        $address = $result['Data']['UploadAddress'] ?? [];

        if (empty($address['SessionKey']) || empty($address['UploadHosts']) || empty($address['StoreInfos'])) {
            return false;
        }

        $storeInfo = $address['StoreInfos'][0];

        return [
            'upload_host' => $address['UploadHosts'][0],
            'store_uri' => $storeInfo['StoreUri'],
            'auth' => $storeInfo['Auth'],
            'session_key' => $address['SessionKey'],
        ];
    }

    /**
     * 确认上传并返回Vid
     *
     * @param string $sessionKey
     * @param string $fileName
     * @param string $callbackArgs
     * @return string|bool
     */
    public function commitUpload($sessionKey, $fileName = '', $callbackArgs = '')
    {
        $functions = [
            [
                'Name' => 'GetMeta'
            ]
        ];

        if ($this->transTemplateId) {
            $functions[] = [
                'Name' => 'StartWorkflow',
                'Input' => ['TemplateId' => $this->transTemplateId],
            ];
        }

        $params = [
            'SpaceName' => $this->spaceName,
            'SessionKey' => $sessionKey,
            'Functions' => $functions,
        ];

        if ($fileName) {
            $params['CallbackArgs'] = $callbackArgs ?: $fileName;
        }

        $result = $this->request('CommitUploadInfo', [], '2020-08-01', 'POST', kg_json_encode($params));

        if (!$result) {
            // 兼容 GET 形式
            $getParams = [
                'SpaceName' => $this->spaceName,
                'SessionKey' => $sessionKey,
                'Functions' => kg_json_encode($functions),
            ];
            if ($fileName) {
                $getParams['CallbackArgs'] = $callbackArgs ?: $fileName;
            }
            $result = $this->request('CommitUploadInfo', $getParams, '2020-08-01', 'GET');
        }

        if (!$result) return false;

        return $result['Data']['Vid'] ?? ($result['Vid'] ?? false);
    }

    /**
     * 获取播放信息
     *
     * @param string $vid
     * @param string $fileType
     * @return array|bool
     */
    public function getPlayInfo($vid, $fileType = 'video')
    {
        $protocol = $this->settings['protocol'] ?? 'http';
        $ssl = ($protocol == 'https') ? '1' : '0';

        $params = [
            'Vid' => $vid,
            'Ssl' => $ssl,
        ];

        $result = $this->request('GetPlayInfo', $params, '2020-08-01', 'GET');

        if (!$result) return false;

        return $result;
    }

    /**
     * 获取媒体信息
     *
     * @param string $vid
     * @return array|bool
     */
    public function getMediaInfos($vid)
    {
        $params = ['Vids' => $vid];

        $result = $this->request('GetMediaInfos', $params, '2022-12-01', 'GET');

        if (!$result) return false;

        return $result;
    }

    /**
     * 删除媒体
     *
     * @param string $vid
     * @return bool
     */
    public function deleteMedia($vid)
    {
        $params = ['Vids' => $vid];

        $result = $this->request('DeleteMedia', $params, '2020-08-01', 'GET');

        return !empty($result);
    }

    /**
     * 启动工作流(转码)
     *
     * @param string $vid
     * @param string $templateId
     * @return string|bool
     */
    public function startWorkflow($vid, $templateId = '')
    {
        $body = ['Vid' => $vid, 'TemplateId' => $templateId ?: $this->transTemplateId];

        if (empty($body['TemplateId'])) return false;

        $result = $this->request('StartWorkflow', [], '2020-08-01', 'POST', kg_json_encode($body));

        if (!$result) return false;

        return $result['RunId'] ?? false;
    }

    /**
     * 查询工作流执行详情
     *
     * @param string $runId
     * @return array|bool
     */
    public function getWorkflowExecutionDetail($runId)
    {
        $params = ['RunId' => $runId];

        $result = $this->request('GetWorkflowExecutionDetail', $params, '2020-08-01', 'GET');

        if (!$result) return false;

        return $result;
    }

    /**
     * 配置测试(查询媒体列表，验证AK/SK/空间配置)
     *
     * @return bool
     */
    public function ping()
    {
        $params = ['SpaceName' => $this->spaceName, 'Offset' => 0, 'Limit' => 1];

        $result = $this->request('GetMediaList', $params, '2022-12-01', 'GET');

        return !empty($result);
    }

    /**
     * 获取客户端上传STS临时凭证 (用于 Web SDK 直传)
     *
     * @param int $durationSeconds 默认 3600 秒
     * @return array
     */
    public function getUploadStsAuth($durationSeconds = 3600)
    {
        $stsHost = 'sts.volcengineapi.com';
        $stsService = 'sts';
        $date = gmdate('Ymd\THis\Z');
        $datePart = substr($date, 0, 8);
        $credentialScope = $datePart . '/' . $this->region . '/' . $stsService . '/request';

        $policy = [
            'Statement' => [
                [
                    'Effect' => 'Allow',
                    'Action' => [
                        'vod:ApplyUploadInfo',
                        'vod:CommitUploadInfo'
                    ],
                    'Resource' => [
                        "trn:vod:::space/{$this->spaceName}",
                        "trn:vod:*:*:space/{$this->spaceName}"
                    ]
                ]
            ]
        ];

        $params = [
            'Action' => 'AssumeRole',
            'Version' => '2018-01-01',
            'DurationSeconds' => (string)$durationSeconds,
            'Policy' => json_encode($policy)
        ];

        $canonicalQuery = $this->createCanonicalQuery($params);
        $headers = [
            'Host' => $stsHost,
            'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8',
            'X-Date' => $date,
            'X-Content-Sha256' => hash('sha256', ''),
        ];

        [$canonicalHeaders, $signedHeaders] = $this->createCanonicalHeaders($headers);

        $canonicalRequest = "GET
/
{$canonicalQuery}
{$canonicalHeaders}
{$signedHeaders}
" . hash('sha256', '');
        $stringToSign = "HMAC-SHA256
{$date}
{$credentialScope}
" . hash('sha256', $canonicalRequest);

        $kDate = hash_hmac('sha256', $datePart, $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $stsService, $kRegion, true);
        $signKey = hash_hmac('sha256', 'request', $kService, true);

        $signature = hash_hmac('sha256', $stringToSign, $signKey);
        $headers['Authorization'] = 'HMAC-SHA256 Credential=' . $this->accessKey . '/' . $credentialScope .
            ', SignedHeaders=' . $signedHeaders .
            ', Signature=' . $signature;

        $url = 'https://' . $stsHost . '/?' . $canonicalQuery;

        try {
            $response = $this->sendHttp('GET', $url, $headers);
            $data = json_decode($response, true);
            $credentials = $data['Result']['Credentials'] ?? null;
            if ($credentials) {
                return [
                    'AccessKeyId' => $credentials['AccessKeyId'],
                    'SecretAccessKey' => $credentials['SecretAccessKey'],
                    'SessionToken' => $credentials['SessionToken'],
                    'ExpiredTime' => $credentials['ExpiredTime'],
                    'CurrentTime' => $credentials['CurrentTime'] ?? date('c'),
                    'space_name' => $this->spaceName,
                ];
            }
        } catch (\Throwable $e) {
            $this->logger->error('Volc AssumeRole Exception: ' . $e->getMessage());
        }

        return false;
    }

/**
     * 拼接规范化Query(按键排序，RFC3986编码)
     *
     * @param array $params
     * @return string
     */
    protected function createCanonicalQuery(array $params)
    {
        ksort($params, SORT_STRING);

        $parts = [];

        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $value = implode(',', $value);
            }
            $parts[] = rawurlencode($key) . '=' . rawurlencode((string)$value);
        }

        return implode('&', $parts);
    }

    /**
     * 规范化Header与SignedHeaders
     *
     * @param array $headers
     * @return array [canonicalHeaders, signedHeaders]
     */
    protected function createCanonicalHeaders(array $headers)
    {
        $result = [];

        foreach ($headers as $key => $value) {
            $lowerKey = strtolower($key);
            $isReqHeader = in_array($lowerKey, ['content-type', 'content-md5', 'host', 'x-security-token']);
            $isXHeader = strpos($lowerKey, 'x-') === 0;
            if (!$isReqHeader && !$isXHeader) continue;
            $result[$lowerKey] = trim($value);
        }

        ksort($result, SORT_STRING);

        $canonicalHeaders = '';

        foreach ($result as $key => $value) {
            if ($key == 'host' && strpos($value, ':') !== false) {
                [$host, $port] = explode(':', $value);
                if (in_array($port, ['80', '443'])) {
                    $value = $host;
                }
            }
            $canonicalHeaders .= $key . ':' . $value . "\n";
        }

        $signedHeaders = implode(';', array_keys($result));

        return [$canonicalHeaders, $signedHeaders];
    }

    /**
     * 派生签名密钥 HMAC(HMAC(HMAC(HMAC(SK,date),region),service),'request')
     *
     * @param string $datePart
     * @return string
     */
    protected function createSignKey($datePart)
    {
        $kDate = hash_hmac('sha256', $datePart, $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', self::SERVICE, $kRegion, true);
        return hash_hmac('sha256', 'request', $kService, true);
    }

    /**
     * 发送HTTP请求
     *
     * @param string $method
     * @param string $url
     * @param array $headers
     * @param string $body
     * @return string
     */
    protected function sendHttp($method, $url, array $headers, $body = '')
    {
        $ch = curl_init();

        $curlHeaders = [];

        foreach ($headers as $key => $value) {
            $curlHeaders[] = $key . ': ' . $value;
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        if ($method == 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException(sprintf('请求火山引擎点播失败: %s', $error));
        }

        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($statusCode != 200 && empty($response)) {
            throw new \RuntimeException(sprintf('火山引擎点播接口返回异常HTTP状态码: %d', $statusCode));
        }

        return $response;
    }

}