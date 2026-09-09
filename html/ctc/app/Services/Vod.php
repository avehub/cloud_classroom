<?php


namespace App\Services;

use Phalcon\Logger\Adapter\File as FileLogger;

/**
 * 火山引擎点播(VOD)服务
 *
 * 由腾讯云点播迁移至火山引擎点播：
 * - 客户端：VolcVodClient(实现 SigV4 VOD OpenAPI 签名)
 * - 上传：ApplyUploadInfo -> 浏览器直传 TOS -> CommitUploadInfo
 * - 转码：StartWorkflow(工作流模板)
 * - 播放：GetPlayInfo 返回播放地址
 * - 事件：后续通过 GetWorkflowExecutionDetail 轮询 / HTTP 回调
 */
class Vod extends Service
{

    /** @var VolcVodClient */
    protected $client;

    /**
     * @var FileLogger
     */
    protected $logger;

    /**
     * @var array
     */
    protected $settings;

    public function __construct()
    {
        $this->settings = $this->getSettings('vod');

        $this->logger = $this->getLogger('vod');

        $this->client = $this->getVodClient();
    }

    /**
     * 配置测试
     *
     * @return bool
     */
    public function test()
    {
        try {

            $result = $this->client->ping();

            return !empty($result);

        } catch (\Throwable $e) {

            $this->logger->error('Volc Vod Test Exception: ' . kg_json_encode([
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
        return $this->client->applyUpload($fileName, $fileSize, $fileType);
    }

    /**
     * 兼容接口：获取火山点播直传授权
     *
     * @param string $fileName
     * @param string $fileType
     * @return array|bool
     */
    public function getVolcUploadAuth($fileName = '', $fileType = 'video')
    {
        $sts = $this->client->getUploadStsAuth();
        if ($sts) {
            return $sts;
        }

        return $this->applyUpload($fileName, 0, $fileType);
    }

    /**
     * 确认上传
     *
     * @param string $sessionKey
     * @param string $fileName
     * @return string|bool
     */
    public function commitUpload($sessionKey, $fileName = '')
    {
        return $this->client->commitUpload($sessionKey, $fileName);
    }

    /**
     * 兼容接口：确认火山点播上传
     *
     * @param string $sessionKey
     * @param string $spaceName
     * @param array $options
     * @return string|bool
     */
    public function commitVolcUpload($sessionKey, $spaceName = '', $options = [])
    {
        $fileName = $options['title'] ?? '';
        return $this->commitUpload($sessionKey, $fileName);
    }

    /**
     * 获取文件转码
     *
     * @param string $fileId
     * @return array|null
     */
    public function getFileTranscode($fileId)
    {
        if (!$fileId) return null;

        $playInfo = $this->getPlayInfo($fileId);

        if ($playInfo) {
            $list = $playInfo['PlayInfoList'] ?? [];
            $duration = isset($playInfo['Duration']) ? intval($playInfo['Duration']) : 0;

            if (!empty($list)) {
                $result = [];
                foreach ($list as $file) {
                    $rawUrl = $file['MainPlayUrl'] ?? '';
                    if (empty($rawUrl)) continue;

                    // 剥离 URL 中的动态临时参数与 auth_key，保持纯净的基础播放地址用于持久化
                    $cleanUrl = $this->getCleanPlayUrl($rawUrl);

                    $result[] = [
                        'url' => $cleanUrl,
                        'width' => $file['Width'] ?? 0,
                        'height' => $file['Height'] ?? 0,
                        'definition' => $file['Definition'] ?? '',
                        'duration' => $duration,
                        'format' => $file['Format'] ?? '',
                        'size' => round(($file['Size'] ?? 0) / 1024 / 1024, 2),
                        'rate' => intval(($file['Bitrate'] ?? 0) / 1024),
                    ];
                }
                if (!empty($result)) {
                    return $result;
                }
            }
        }

        // 若尚未转码或 GetPlayInfo 暂无分发流，降级获取源片信息作为默认播放源
        $mediaInfo = $this->getOriginVideoInfo($fileId);
        if ($mediaInfo) {
            $domain = $this->settings['domain'] ?? '';
            $protocol = $this->settings['protocol'] ?: 'https';
            if ($domain) {
                $url = "{$protocol}://{$domain}/{$fileId}.mp4";
                return [
                    [
                        'url' => $url,
                        'width' => $mediaInfo['width'] ?? 0,
                        'height' => $mediaInfo['height'] ?? 0,
                        'definition' => 'sd',
                        'duration' => $mediaInfo['duration'] ?? 0,
                        'format' => 'mp4',
                        'size' => round(($mediaInfo['size'] ?? 0) / 1024 / 1024, 2),
                        'rate' => intval(($mediaInfo['bit_rate'] ?? 0) / 1024),
                    ]
                ];
            }
        }

        return null;
    }

    /**
     * 剥离 URL 中的动态临时参数（保留纯净地址持久化）
     *
     * @param string $url
     * @return string
     */
    public function getCleanPlayUrl($url)
    {
        if (empty($url)) return '';

        $parsed = parse_url($url);
        $scheme = $parsed['scheme'] ?? 'http';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $path = $parsed['path'] ?? '';

        $cleanUrl = "{$scheme}://{$host}{$port}{$path}";

        // 保留非鉴权的基础参数（如有）
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $queryArr);
            unset($queryArr['auth_key']);
            if (!empty($queryArr)) {
                $cleanUrl .= '?' . http_build_query($queryArr);
            }
        }

        return $cleanUrl;
    }

    /**
     * 获取播放信息
     *
     * @param string $fileId
     * @return array|bool
     */
    public function getPlayInfo($fileId)
    {
        return $this->client->getPlayInfo($fileId);
    }

    /**
     * 获取按需实时有效播放流（带 Redis 短时缓存）
     *
     * @param string $fileId
     * @return array|null
     */
    public function getLivePlayTranscode($fileId)
    {
        if (!$fileId) return null;

        $cacheKey = "vod:play_urls:{$fileId}";
        $redis = $this->getRedis();

        if ($redis) {
            $cached = $redis->get($cacheKey);
            if ($cached) {
                $decoded = json_decode($cached, true);
                if (!empty($decoded) && is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        $playInfo = $this->getPlayInfo($fileId);

        if ($playInfo) {
            $list = $playInfo['PlayInfoList'] ?? [];
            $duration = isset($playInfo['Duration']) ? intval($playInfo['Duration']) : 0;

            if (!empty($list)) {
                $result = [];
                foreach ($list as $file) {
                    $rawUrl = $file['MainPlayUrl'] ?? '';
                    if (empty($rawUrl)) continue;

                    $result[] = [
                        'url' => $rawUrl,
                        'width' => $file['Width'] ?? 0,
                        'height' => $file['Height'] ?? 0,
                        'definition' => $file['Definition'] ?? '',
                        'duration' => $duration,
                        'format' => $file['Format'] ?? '',
                        'size' => round(($file['Size'] ?? 0) / 1024 / 1024, 2),
                        'rate' => intval(($file['Bitrate'] ?? 0) / 1024),
                    ];
                }

                if (!empty($result)) {
                    if ($redis) {
                        // 缓存 1200 秒（低于 1800 秒），确保过期前自动向火山官方重新获取最新带签名的有效播放流
                        $redis->setex($cacheKey, 1200, json_encode($result));
                    }
                    return $result;
                }
            }
        }

        return null;
    }

    /**
     * 获取播放地址 (直接使用火山官方 GetPlayInfo 下发的合法带签名播放链接)
     *
     * @param string $url
     * @return string
     */
    public function getPlayUrl($url)
    {
        if (empty($url)) return '';

        return (string)$url;
    }

    /**
     * 获取媒体信息(归一化腾讯结构)
     *
     * @param string $fileId
     * @return array|bool
     */
    public function getMediaInfo($fileId)
    {
        try {

            $info = $this->client->getMediaInfos($fileId);

            if (!$info || empty($info['MediaInfoList'][0])) return false;

            $media = $info['MediaInfoList'][0];

            $source = $media['SourceInfo'] ?? [];

            $metaData = [
                'Bitrate' => $this->estimateBitrate($source),
                'Size' => $source['Size'] ?? 0,
                'Width' => $source['Width'] ?? 0,
                'Height' => $source['Height'] ?? 0,
                'Duration' => $source['Duration'] ?? 0,
            ];

            return ['MediaInfoSet' => [['MetaData' => $metaData]]];

        } catch (\Throwable $e) {

            $this->logger->error('Volc Get Media Info Exception: ' . kg_json_encode([
                    'message' => $e->getMessage(),
                ]));

            return false;
        }
    }

    /**
     * 获取任务信息
     *
     * @param string $taskId
     * @return array|bool
     */
    public function getTaskInfo($taskId)
    {
        return $this->client->getWorkflowExecutionDetail($taskId);
    }

    /**
     * 获取原始视频信息
     *
     * @param string $fileId
     * @return array|bool
     */
    public function getOriginVideoInfo($fileId)
    {
        $response = $this->getMediaInfo($fileId);

        if (!$response) return false;

        $metaData = $response['MediaInfoSet'][0]['MetaData'];

        return [
            'bit_rate' => $metaData['Bitrate'],
            'size' => $metaData['Size'],
            'width' => $metaData['Width'],
            'height' => $metaData['Height'],
            'duration' => $metaData['Duration'],
        ];
    }

    /**
     * 获取原始音频信息
     *
     * @param string $fileId
     * @return array|bool
     */
    public function getOriginAudioInfo($fileId)
    {
        $response = $this->getMediaInfo($fileId);

        if (!$response) return false;

        $metaData = $response['MediaInfoSet'][0]['MetaData'];

        return [
            'bit_rate' => $metaData['Bitrate'],
            'size' => $metaData['Size'],
            'width' => $metaData['Width'],
            'height' => $metaData['Height'],
            'duration' => $metaData['Duration'],
        ];
    }

/**
     * 创建视频转码任务
     *
     * @param string $fileId
     * @return string|bool
     */
    public function createTransVideoTask($fileId)
    {
        return $this->client->startWorkflow($fileId);
    }

    /**
     * 创建音频转码任务
     *
     * @param string $fileId
     * @return string|bool
     */
    public function createTransAudioTask($fileId)
    {
        return $this->client->startWorkflow($fileId);
    }

    /**
     * 获取水印模板(火山引擎通过工作流模板配置水印，此处保留原配置项)
     *
     * @return mixed
     */
    public function getWatermarkTemplate()
    {
        $result = null;

        if ($this->settings['wmk_enabled'] == 1 && $this->settings['wmk_tpl_id'] > 0) {
            $result = (int)$this->settings['wmk_tpl_id'];
        }

        return $result;
    }

    /**
     * 获取视频转码模板
     *
     * @return array
     */
    public function getVideoTransTemplates()
    {
        $hlsTemplates = [
            100220 => ['quality' => 'fd', 'height' => 540, 'bit_rate' => 1000, 'frame_rate' => 25],
            100230 => ['quality' => 'sd', 'height' => 720, 'bit_rate' => 1800, 'frame_rate' => 25],
            100240 => ['quality' => 'hd', 'height' => 1080, 'bit_rate' => 2500, 'frame_rate' => 25],
        ];

        $mp4Templates = [
            100020 => ['quality' => 'fd', 'height' => 540, 'bit_rate' => 1000, 'frame_rate' => 25],
            100030 => ['quality' => 'sd', 'height' => 720, 'bit_rate' => 1800, 'frame_rate' => 25],
            100040 => ['quality' => 'hd', 'height' => 1080, 'bit_rate' => 2500, 'frame_rate' => 25],
        ];

        $format = $this->settings['video_format'] ?: 'hls';

        $quality = !empty($this->settings['video_quality']) ? json_decode($this->settings['video_quality'], true) : ['sd'];

        $templates = $format == 'hls' ? $hlsTemplates : $mp4Templates;



        $result = [];

        foreach ($templates as $key => $item) {
            if (in_array($item['quality'], $quality)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    /**
     * 获取音频转码模板
     *
     * @return array
     */
    public function getAudioTransTemplates()
    {
        $mp3Templates = [
            1010 => ['quality' => 'sd', 'bit_rate' => 128, 'sample_rate' => 44100],
        ];

        $m4aTemplates = [
            1120 => ['quality' => 'sd', 'bit_rate' => 96, 'sample_rate' => 44100],
        ];

        $format = $this->settings['audio_format'] ?: 'mp3';

        $quality = !empty($this->settings['audio_quality']) ? json_decode($this->settings['audio_quality'], true) : ['sd'];

        $templates = $format == 'mp3' ? $mp3Templates : $m4aTemplates;



        $result = [];

        foreach ($templates as $key => $item) {
            if (in_array($item['quality'], $quality)) {
                $result[$key] = $item;
            }
        }

        return $result;

    }

    /**
     * 估算码率(位率 bps)（火山 SourceInfo 未直接返回码率）
     *
     * @param array $source
     * @return int
     */
    protected function estimateBitrate(array $source)
    {
        $size = $source['Size'] ?? 0;
        $duration = $source['Duration'] ?? 0;

        if ($size > 0 && $duration > 0) {
            return intval($size * 8 / $duration);
        }

        return 0;
    }

    /**
     * 获取Vod客户端
     *
     * @return VolcVodClient
     */
    public function getVodClient()
    {
        return new VolcVodClient();
    }

}