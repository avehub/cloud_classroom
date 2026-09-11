<?php


namespace App\Services;

use Phalcon\Logger\Adapter\File as FileLogger;
use Qcloud\Cos\Client as CosClient;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Sts\V20180813\Models\GetFederationTokenRequest;
use TencentCloud\Sts\V20180813\Models\GetFederationTokenResponse;
use TencentCloud\Sts\V20180813\StsClient;

class Storage extends Service
{

    /**
     * 存储方式: local|cos
     *
     * @var string
     */
    const DRIVER_LOCAL = 'local';

    const DRIVER_COS = 'cos';

    /**
     * COS配置
     *
     * @var array
     */
    protected $settings;

    /**
     * 存储方式配置
     *
     * @var array
     */
    protected $storageSettings;

    /**
     * @var FileLogger
     */
    protected $logger;

    /**
     * @var CosClient
     */
    protected $client;

    /**
     * 本地存储根目录相对路径（html/ctc/storage/upload）
     *
     * @var string
     */
    protected $localRootDir = 'storage/upload';

    public function __construct()
    {
        $this->settings = $this->getSettings('cos');

        $this->storageSettings = $this->getSettings('storage');

        $this->logger = $this->getLogger('storage');
    }

    /**
     * 当前是否为本地存储
     *
     * @return bool
     */
    public function isLocalStorage()
    {
        $driver = $this->storageSettings['driver'] ?? '';

        return $driver == self::DRIVER_LOCAL;
    }

    /**
     * 获取本地存储根目录绝对路径
     *
     * @return string
     */
    public function getLocalRootPath()
    {
        $path = storage_path('upload');

        if (!is_dir($path)) {
            @mkdir($path, 0777, true);
        }

        return $path;
    }

    /**
     * 获取本地存储文件绝对路径
     *
     * @param string $key
     * @return string
     */
    protected function getLocalFilePath($key)
    {
        return rtrim($this->getLocalRootPath(), '/') . '/' . ltrim($key, '/');
    }

    /**
     * 准备本地目录结构
     *
     * @param string $filePath
     * @throws \RuntimeException
     */
    protected function prepareLocalPath($filePath)
    {
        $dir = dirname($filePath);

        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new \RuntimeException('Create local storage dir failed: ' . $dir);
            }
        }
    }

    /**
     * 获取本地存储基准URL
     *
     * @return string
     */
    protected function getLocalBaseUrl()
    {
        $base = rtrim($this->localRootDir, '/');

        $siteUrl = kg_site_url();

        if (preg_match('#^https?://#i', $siteUrl)) {
            return rtrim($siteUrl, '/') . '/' . $base;
        }

        /**
         * 非HTTP上下文（如命令行任务）时退化为相对路径
         */
        $config = $this->getConfig();

        return rtrim($config->get('base_uri', '/'), '/') . '/' . $base;
    }

    /**
     * 获取临时凭证
     *
     * @return GetFederationTokenResponse|bool
     */
    public function getFederationToken()
    {
        if ($this->isLocalStorage()) {
            return false;
        }

        $secret = $this->getSettings('secret');

        $resource = sprintf('qcs::cos:%s:uid/%s:%s/*',
            $this->settings['region'],
            $secret['app_id'],
            $this->settings['bucket']
        );

        $policy = json_encode([
            'version' => '2.0',
            'statement' => [
                'effect' => 'allow',
                'action' => [
                    'name/cos:PutObject',
                    'name/cos:PostObject',
                    'name/cos:InitiateMultipartUpload',
                    'name/cos:ListMultipartUploads',
                    'name/cos:ListParts',
                    'name/cos:UploadPart',
                    'name/cos:CompleteMultipartUpload',
                ],
                'resource' => [$resource],
            ],
        ]);

        try {

            $credential = new Credential($secret['secret_id'], $secret['secret_key']);

            $httpProfile = new HttpProfile();

            $httpProfile->setEndpoint('sts.tencentcloudapi.com');

            $clientProfile = new ClientProfile();

            $clientProfile->setHttpProfile($httpProfile);

            $client = new StsClient($credential, $this->settings['region'], $clientProfile);

            $request = new GetFederationTokenRequest();

            $params = json_encode([
                'Name' => 'foo',
                'Policy' => urlencode($policy),
            ]);

            $request->fromJsonString($params);

            $result = $client->GetFederationToken($request);

        } catch (TencentCloudSDKException $e) {

            $this->logger->error('Get Tmp Token Exception: ' . kg_json_encode([
                    'code' => $e->getCode(),
                    'message' => $e->getMessage(),
                    'requestId' => $e->getRequestId(),
                ]));

            $result = false;
        }

        return $result;
    }

    /**
     * 上传字符内容
     *
     * @param string $key
     * @param string $body
     * @return string|bool
     */
    public function putString($key, $body)
    {
        if ($this->isLocalStorage()) {
            return $this->localPutString($key, $body);
        }

        $bucket = $this->settings['bucket'];

        try {

            $response = $this->getClient()->upload($bucket, $key, $body);

            $result = $response['Location'] ? $key : false;

        } catch (TencentCloudSDKException $e) {

            $this->logger->error('Put String Exception: ' . kg_json_encode([
                    'code' => $e->getCode(),
                    'message' => $e->getMessage(),
                    'requestId' => $e->getRequestId(),
                ]));

            $result = false;
        }

        return $result;
    }

    /**
     * 上传文件
     *
     * @param string $key
     * @param string $filename
     * @return string|bool
     */
    public function putFile($key, $filename)
    {
        if ($this->isLocalStorage()) {
            return $this->localPutFile($key, $filename);
        }

        $bucket = $this->settings['bucket'];

        try {

            $body = fopen($filename, 'rb');

            $response = $this->getClient()->upload($bucket, $key, $body);

            $result = $response['Location'] ? $key : false;

        } catch (TencentCloudSDKException $e) {

            $this->logger->error('Put File Exception: ' . kg_json_encode([
                    'code' => $e->getCode(),
                    'message' => $e->getMessage(),
                    'requestId' => $e->getRequestId(),
                ]));

            $result = false;
        }

        return $result;
    }

    /**
     * 删除文件
     *
     * @param string $key
     * @return string|bool
     */
    public function deleteObject($key)
    {
        if ($this->isLocalStorage()) {
            return $this->localDeleteObject($key);
        }

        $bucket = $this->settings['bucket'];

        try {

            $response = $this->getClient()->DeleteObject([
                'Bucket' => $bucket,
                'Key' => $key,
            ]);

            $result = $response['Location'] ? $key : false;

        } catch (TencentCloudSDKException $e) {

            $this->logger->error('Delete Object Exception: ' . kg_json_encode([
                    'code' => $e->getCode(),
                    'message' => $e->getMessage(),
                    'requestId' => $e->getRequestId(),
                ]));

            $result = false;
        }

        return $result;
    }

    /**
     * 获取文件URL
     *
     * @param string $key
     * @return string
     */
    public function getFileUrl($key)
    {
        return $this->getBaseUrl() . $key;
    }

    /**
     *  获取图片URL
     *
     * @param string $key
     * @param string $style
     * @return string
     */
    public function getImageUrl($key, $style = null)
    {
        if ($this->isLocalStorage()) {
            return $this->getFileUrl($key);
        }

        $style = $style ?: '';

        return $this->getBaseUrl() . $key . $style;
    }

    /**
     * 获取基准URL
     *
     * @return string
     */
    public function getBaseUrl()
    {
        if ($this->isLocalStorage()) {
            return $this->getLocalBaseUrl();
        }

        $protocol = $this->settings['protocol'];
        $domain = $this->settings['domain'];

        return sprintf('%s://%s', $protocol, trim($domain, '/'));
    }

    /**
     * 生成文件存储名
     *
     * @param string $extension
     * @param string $prefix
     * @return string
     */
    public function generateFileName($extension = '', $prefix = '')
    {
        $name = uniqid();

        $dot = $extension ? '.' : '';

        if ($this->isLocalStorage()) {
            $date = date('Y/m/d');

            return sprintf('/%s/%s%s%s', $date, $name, $dot, $extension);
        }

        return sprintf('%s/%s%s%s', $prefix, $name, $dot, $extension);
    }

    /**
     * 获取文件扩展名
     *
     * @param string $filename
     * @return string
     */
    protected function getFileExtension($filename)
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        return strtolower($extension);
    }

    /**
     * 获取CosClient（懒加载）
     *
     * @return CosClient
     */
    protected function getClient()
    {
        if (!$this->client) {
            $this->client = $this->getCosClient();
        }

        return $this->client;
    }

    /**
     * 获取CosClient
     *
     * @return CosClient
     */
    protected function getCosClient()
    {
        $secret = $this->getSettings('secret');

        return new CosClient([
            'region' => $this->settings['region'],
            'schema' => $this->settings['protocol'],
            'credentials' => [
                'secretId' => $secret['secret_id'],
                'secretKey' => $secret['secret_key'],
            ]]);
    }

    /**
     * 本地存储: 上传字符内容
     *
     * @param string $key
     * @param string $body
     * @return string|bool
     */
    protected function localPutString($key, $body)
    {
        try {

            $filePath = $this->getLocalFilePath($key);

            $this->prepareLocalPath($filePath);

            if (@file_put_contents($filePath, $body) === false) {
                throw new \RuntimeException('Write local file failed: ' . $filePath);
            }

            $result = $key;

        } catch (\Exception $e) {

            $this->logger->error('Local Put String Exception: ' . kg_json_encode([
                    'key' => $key,
                    'message' => $e->getMessage(),
                ]));

            $result = false;
        }

        return $result;
    }

    /**
     * 本地存储: 上传文件
     *
     * @param string $key
     * @param string $filename
     * @return string|bool
     */
    protected function localPutFile($key, $filename)
    {
        try {

            $filePath = $this->getLocalFilePath($key);

            $this->prepareLocalPath($filePath);

            if (!@copy($filename, $filePath)) {
                throw new \RuntimeException('Copy local file failed: ' . $filename);
            }

            $result = $key;

        } catch (\Exception $e) {

            $this->logger->error('Local Put File Exception: ' . kg_json_encode([
                    'key' => $key,
                    'message' => $e->getMessage(),
                ]));

            $result = false;
        }

        return $result;
    }

    /**
     * 本地存储: 删除文件
     *
     * @param string $key
     * @return string|bool
     */
    protected function localDeleteObject($key)
    {
        $filePath = $this->getLocalFilePath($key);

        if (is_file($filePath)) {
            return @unlink($filePath) ? $key : false;
        }

        return $key;
    }

}
