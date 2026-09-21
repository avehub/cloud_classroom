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

    /**
     * 视频确认不存在时的负缓存时长(秒)
     */
    const VID_MISS_CACHE_TTL = 600;

    /**
     * 浏览器无法直接播放的视频编码，命中后不再作为播放源下发，等待 H.264 转码流
     *
     * @var array
     */
    const INCOMPATIBLE_CODECS = ['hevc', 'h265', 'hev1', 'hvc1'];

    /**
     * 系统自动创建的 H.264 工作流模板名称
     */
    const H264_WORKFLOW_NAME = '课堂H.264转码';

    /**
     * 火山引擎系统预设 H.264 MP4 转码模板(分辨率由高到低)
     *
     * 正常情况下通过解析空间内预设工作流实时获取，此处仅作为兜底默认值
     *
     * @var array
     */
    const H264_MP4_PRESETS = [
        ['height' => 720, 'template_id' => 'b343810d7ca54853b141796978a5efa9'],
        ['height' => 480, 'template_id' => 'cedd40eb7aed4906aa5be7f60b802880'],
        ['height' => 360, 'template_id' => '5a36ec526baa43d49b113b40aadef952'],
    ];

    /**
     * 优先采用的转码分辨率(官方预设最高仅 720P，1080P 需通过自定义模板补充)
     *
     * @var array
     */
    const H264_PREFERRED_HEIGHTS = [1080, 720, 480, 360];

    /**
     * 转码档位上限，与前台 高清/标清/极速 三档保持一致
     */
    const H264_MAX_RUNGS = 3;

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

    /**
     * H.264 转码模板梯度缓存(单次请求内复用)
     *
     * @var array
     */
    protected $h264Ladder = [];

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

        if (!$playInfo && $this->client->isLastVidNotFound()) {
            // 视频在火山端已不存在，无需再降级查询源片信息
            return null;
        }

        if ($playInfo) {
            $list = $playInfo['PlayInfoList'] ?? [];
            $duration = isset($playInfo['Duration']) ? intval($playInfo['Duration']) : 0;

            if (!empty($list)) {
                $result = [];
                foreach ($list as $file) {
                    $rawUrl = $file['MainPlayUrl'] ?? '';
                    if (empty($rawUrl)) continue;

                    $codec = strtolower((string)($file['Codec'] ?? ''));

                    // 源片为 HEVC 等浏览器不支持的编码时不下发，等待 H.264 转码流生成
                    if (!$this->isPlayableCodec($codec)) {
                        $this->logger->warning("Volc GetPlayInfo: Vid={$fileId} 存在浏览器不支持的编码流({$codec})，已跳过");
                        continue;
                    }

                    // 剥离 URL 中的动态临时参数与 auth_key，保持纯净的基础播放地址用于持久化
                    $cleanUrl = $this->getCleanPlayUrl($rawUrl);

                    $result[] = [
                        'url' => $cleanUrl,
                        'width' => $file['Width'] ?? 0,
                        'height' => $file['Height'] ?? 0,
                        'definition' => $file['Definition'] ?? '',
                        'duration' => $duration,
                        'format' => $file['Format'] ?? '',
                        'codec' => $codec,
                        'size' => round(($file['Size'] ?? 0) / 1024 / 1024, 2),
                        'rate' => intval(($file['Bitrate'] ?? 0) / 1024),
                    ];
                }
                if (!empty($result)) {
                    return $result;
                }
            }
        }

        // 尚无可播放的分发流(转码中或源片编码不受支持)，返回空由调用方保持"转码中"状态并重试
        return null;
    }

    /**
     * 编码是否为浏览器可直接播放的格式
     *
     * @param string $codec
     * @return bool
     */
    public function isPlayableCodec($codec)
    {
        $codec = strtolower(trim((string)$codec));

        if ($codec === '') return true;

        return !in_array($codec, self::INCOMPATIBLE_CODECS, true);
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
        $missKey = "vod:play_urls_miss:{$fileId}";
        $redis = $this->getRedis();

        if ($redis) {
            // 视频已确认在火山端不存在，短期内直接短路，避免每次访问都打无效接口并刷错误日志
            if ($redis->exists($missKey)) {
                return null;
            }

            $cached = $redis->get($cacheKey);
            if ($cached) {
                $decoded = json_decode($cached, true);
                if (!empty($decoded) && is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        $playInfo = $this->getPlayInfo($fileId);

        if (!$playInfo && $this->client->isLastVidNotFound()) {
            if ($redis) {
                $redis->setex($missKey, self::VID_MISS_CACHE_TTL, 1);
            }
            $this->logger->warning("Volc GetPlayInfo: Vid={$fileId} 在火山端不存在，已跳过实时播放流获取");
            return null;
        }

        if ($playInfo) {
            $list = $playInfo['PlayInfoList'] ?? [];
            $duration = isset($playInfo['Duration']) ? intval($playInfo['Duration']) : 0;

            if (!empty($list)) {
                $result = [];
                foreach ($list as $file) {
                    $rawUrl = $file['MainPlayUrl'] ?? '';
                    if (empty($rawUrl)) continue;

                    $codec = strtolower((string)($file['Codec'] ?? ''));

                    // 过滤 HEVC 等浏览器无法解码的流，避免下发不可播放地址
                    if (!$this->isPlayableCodec($codec)) continue;

                    $result[] = [
                        'url' => $rawUrl,
                        'width' => $file['Width'] ?? 0,
                        'height' => $file['Height'] ?? 0,
                        'definition' => $file['Definition'] ?? '',
                        'duration' => $duration,
                        'format' => $file['Format'] ?? '',
                        'codec' => $codec,
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
        $state = $this->getMediaState($fileId);

        if ($state['exists'] !== true) return false;

        $source = $state['source'];

        $metaData = [
            'Bitrate' => $this->estimateBitrate($source),
            'Size' => $source['Size'] ?? 0,
            'Width' => $source['Width'] ?? 0,
            'Height' => $source['Height'] ?? 0,
            'Duration' => $source['Duration'] ?? 0,
        ];

        return ['MediaInfoSet' => [['MetaData' => $metaData]]];
    }

    /**
     * 拉取媒体在火山端的状态(单次 GetMediaInfos 调用)
     *
     * 火山对不存在的 Vid 通过 NotExistVids 明确返回，据此可区分
     * "视频已删除/上传未提交成功"与"接口临时故障"，避免误判。
     *
     * @param string $fileId
     * @return array ['exists' => bool|null, 'source' => array] exists为null表示无法判定
     */
    public function getMediaState($fileId)
    {
        $state = ['exists' => null, 'source' => []];

        if (empty($fileId)) return $state;

        $info = $this->client->getMediaInfos($fileId);

        if (!is_array($info)) {
            $state['exists'] = $this->client->isLastVidNotFound() ? false : null;
            return $state;
        }

        $notExistVids = array_map('strval', (array)($info['NotExistVids'] ?? []));

        if (in_array((string)$fileId, $notExistVids, true) || empty($info['MediaInfoList'][0])) {
            $state['exists'] = false;
            return $state;
        }

        $state['exists'] = true;
        $state['source'] = $info['MediaInfoList'][0]['SourceInfo'] ?? [];

        return $state;
    }

    /**
     * 媒体文件在火山端是否仍然存在
     *
     * @param string $fileId
     * @return bool|null true=存在 false=确认不存在 null=无法判定
     */
    public function mediaExists($fileId)
    {
        $state = $this->getMediaState($fileId);

        return $state['exists'];
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
     * 校验火山引擎回调签名
     *
     * @param string $rawBody
     * @param string $signature
     * @param string $timestamp
     * @return bool
     */
    public function verifyCallbackSignature($rawBody, $signature, $timestamp)
    {
        $callbackKey = $this->settings['volc_callback_key'] ?? '';
        if (empty($callbackKey)) {
            return true; // 未配置 key 则放行
        }

        if (empty($signature)) {
            return false;
        }

        // 官方 HMAC-SHA256(RawBody + Timestamp, CallbackKey)
        $computed = hash_hmac('sha256', $rawBody . $timestamp, $callbackKey);
        if (hash_equals($computed, $signature)) {
            return true;
        }

        // 兼容 MD5 签名方式: md5(RawBody + CallbackKey)
        $computedMd5 = md5($rawBody . $callbackKey);
        if (hash_equals($computedMd5, $signature)) {
            return true;
        }

        return false;
    }

    /**
     * 处理火山引擎点播回调事件
     *
     * @param array $payload
     * @return bool
     */
    public function handleCallbackEvent(array $payload)
    {
        $eventType = $payload['EventType'] ?? ($payload['Type'] ?? '');
        $data = $payload['Data'] ?? $payload;
        $vid = $data['Vid'] ?? ($data['FileId'] ?? '');

        if (empty($vid)) {
            $this->logger->warning("Volc Callback: Vid is empty, EventType={$eventType}");
            return false;
        }

        $chapterRepo = new \App\Repos\Chapter();
        $chapter = $chapterRepo->findByFileId($vid);

        if (!$chapter) {
            $this->logger->warning("Volc Callback: Chapter not found for Vid={$vid}");
            return false;
        }

        $attrs = $chapter->attrs;
        $vod = $chapterRepo->findChapterVod($chapter->id);

        // 1. 获取源片媒体信息 (同时判定视频在火山端是否存在)
        $state = $this->getMediaState($vid);

        if ($state['exists'] === false) {
            // 视频已删除或上传未提交成功，标记失败避免后续兜底任务无休止轮询
            $attrs['file']['status'] = \App\Models\Chapter::FS_FAILED;
            $chapter->attrs = $attrs;
            $chapter->update();

            $courseStats = new \App\Services\CourseStat();
            $courseStats->updateVodAttrs($chapter->course_id);

            $this->logger->warning("Volc Callback: Vid={$vid} 在火山端不存在，课时已标记为失败");

            return false;
        }

        if (!empty($state['source']['Duration'])) {
            $attrs['duration'] = (int)$state['source']['Duration'];
        }

        // 2. 获取转码播放流
        $transcodes = $this->getFileTranscode($vid);
        if (!empty($transcodes)) {
            if ($vod) {
                $vod->file_transcode = $transcodes;
                $vod->update();
            }
            $attrs['file']['status'] = \App\Models\Chapter::FS_TRANSLATED;
            if (empty($attrs['duration']) && !empty($transcodes[0]['duration'])) {
                $attrs['duration'] = (int)$transcodes[0]['duration'];
            }
        } elseif (!empty($attrs['duration'])) {
            $attrs['file']['status'] = \App\Models\Chapter::FS_TRANSLATING;
        }

        $chapter->attrs = $attrs;
        $chapter->update();

        $courseStats = new \App\Services\CourseStat();
        $courseStats->updateVodAttrs($chapter->course_id);

        $this->logger->info("Volc Callback: Successfully handled EventType={$eventType}, Vid={$vid}, Duration={$attrs['duration']}");

        return true;
    }

    /**
     * 获取 H.264 MP4 转码模板梯度(分辨率由高到低)
     *
     * 优先解析空间内预设工作流中真实的 H.264 MP4 转码模板，解析失败时使用兜底常量。
     * res_range 用于"只降不升"：源片分辨率高于下一档时才输出该档。
     *
     * @return array
     */
    public function getH264TranscodeTemplates()
    {
        if (!empty($this->h264Ladder)) {
            return $this->h264Ladder;
        }

        $presets = $this->detectH264Presets();

        if (empty($presets)) {
            $presets = self::H264_MP4_PRESETS;
        }

        // 官方预设档位较多时，只保留常用的几档
        $filtered = array_values(array_filter($presets, function ($preset) {
            return in_array((int)$preset['height'], self::H264_PREFERRED_HEIGHTS, true);
        }));

        if (!empty($filtered)) {
            $presets = $filtered;
        }

        // 自定义模板(如控制台自建的 1080P H.264 模板)按分辨率覆盖或补充预设档位
        $merged = [];

        foreach ($presets as $preset) {
            $merged[(int)$preset['height']] = $preset;
        }

        foreach ($this->getCustomH264Templates() as $custom) {
            $merged[(int)$custom['height']] = $custom;
        }

        $presets = array_values($merged);

        usort($presets, function ($a, $b) {
            return $b['height'] <=> $a['height'];
        });

        $presets = array_slice($presets, 0, self::H264_MAX_RUNGS);

        $ladder = [];

        foreach ($presets as $index => $preset) {
            $height = (int)$preset['height'];
            $nextHeight = isset($presets[$index + 1]) ? (int)$presets[$index + 1]['height'] : 0;

            $ladder[] = [
                'height' => $height,
                'name' => sprintf('%dP-MP4-H264', $height),
                'template_id' => $preset['template_id'],
                'res_range' => $nextHeight > 0 ? ($nextHeight + 1) . ',-1' : '',
            ];
        }

        $this->h264Ladder = $ladder;

        return $ladder;
    }

    /**
     * 解析自定义 H.264 转码模板配置
     *
     * 配置项 volc_h264_templates 格式为 "分辨率:模板ID"，多个以逗号/空格分隔，
     * 例如: 1080:5c446e0d244e4df79372379ea083c2c0
     *
     * @return array
     */
    public function getCustomH264Templates()
    {
        $value = trim((string)($this->settings['volc_h264_templates'] ?? ''));

        if ($value === '') return [];

        $result = [];

        $pairs = preg_split('/[\s,;，；]+/u', $value);

        foreach ($pairs as $pair) {
            $pair = trim($pair);

            if ($pair === '') continue;

            if (!preg_match('/^(\d{3,4})[:：]([0-9a-zA-Z]+)$/u', $pair, $matches)) {
                $this->logger->warning("Volc H264 Templates: 配置项格式不正确已忽略 {$pair}，正确格式为 分辨率:模板ID");
                continue;
            }

            $height = (int)$matches[1];

            $result[$height] = ['height' => $height, 'template_id' => $matches[2]];
        }

        return array_values($result);
    }

    /**
     * 从空间预设工作流中解析 H.264 MP4 转码模板
     *
     * @return array
     */
    protected function detectH264Presets()
    {
        $templates = $this->client->listWorkflowTemplates();

        if (!is_array($templates)) return [];

        $result = [];

        foreach ($templates as $template) {
            foreach (($template['Activities'] ?? []) as $activity) {
                if (($activity['Type'] ?? '') != 'TranscodeVideo') continue;

                $name = (string)($activity['Name'] ?? '');
                $templateId = (string)($activity['Params']['TemplateId'] ?? '');

                if (empty($templateId)) continue;
                if (stripos($name, 'MP4') === false) continue;
                if (!preg_match('/H\.?264/i', $name)) continue;
                if (stripos($name, 'DRM') !== false) continue;
                if (!preg_match('/(\d{3,4})P/i', $name, $matches)) continue;

                $height = (int)$matches[1];

                if (!isset($result[$height])) {
                    $result[$height] = ['height' => $height, 'template_id' => $templateId];
                }
            }
        }

        return array_values($result);
    }

    /**
     * 检查工作流模板是否已输出 H.264
     *
     * @param string $templateId
     * @return array
     */
    public function inspectWorkflowTemplate($templateId = '')
    {
        $templateId = $templateId ?: (string)($this->settings['volc_trans_template'] ?? '');

        $result = [
            'template_id' => $templateId,
            'name' => '',
            'exists' => false,
            'h264_output' => false,
            'h264_ready' => false,
            'transcode_activities' => [],
            'msg' => '未配置工作流模板',
        ];

        if (empty($templateId)) return $result;

        $template = $this->client->getWorkflowTemplate($templateId);

        if (!$template) {
            $error = $this->client->getLastError();
            $result['msg'] = '读取工作流模板失败: ' . ($error['message'] ?: $error['code']);
            return $result;
        }

        return $this->inspectTemplateData($template, $this->getH264TranscodeTemplates());
    }

    /**
     * 解析工作流模板的转码节点与编码情况
     *
     * @param array $template
     * @param array $ladder
     * @return array
     */
    protected function inspectTemplateData(array $template, array $ladder)
    {
        $h264Ids = array_column($ladder, 'template_id');

        $result = [
            'template_id' => $template['TemplateId'] ?? '',
            'name' => $template['Name'] ?? '',
            'exists' => true,
            'h264_output' => false,
            'h264_ready' => false,
            'transcode_activities' => [],
            'msg' => '',
        ];

        $appliedIds = [];

        $foreignNodes = [];

        foreach (($template['Activities'] ?? []) as $activity) {
            $type = (string)($activity['Type'] ?? '');

            if (!in_array($type, ['TranscodeVideo', 'AdaptBitrateTranscode'], true)) continue;

            $name = (string)($activity['Name'] ?? '');
            $transcodeId = (string)($activity['Params']['TemplateId'] ?? '');

            $isH264 = $type == 'TranscodeVideo' && (
                in_array($transcodeId, $h264Ids, true) ||
                (stripos($name, 'MP4') !== false && preg_match('/H\.?264/i', $name) && stripos($name, 'DRM') === false)
            );

            $result['transcode_activities'][] = [
                'name' => $name,
                'type' => $type,
                'template_id' => $transcodeId,
                'h264' => (bool)$isH264,
            ];

            if ($isH264) {
                $result['h264_output'] = true;
                $appliedIds[] = $transcodeId;
            } else {
                $foreignNodes[] = $name ?: $type;
            }
        }

        sort($appliedIds);

        sort($h264Ids);

        // 与期望梯度完全一致才算就绪，档位变化(如新增 1080P)时需要重新应用
        $result['h264_ready'] = empty($foreignNodes) && array_values(array_unique($appliedIds)) === array_values(array_unique($h264Ids));

        if (!empty($foreignNodes)) {
            $result['msg'] = '存在非 H.264 转码节点: ' . implode('、', $foreignNodes);
        } elseif (!$result['h264_output']) {
            $result['msg'] = '未包含 H.264 MP4 转码节点';
        } elseif (!$result['h264_ready']) {
            $result['msg'] = 'H.264 转码档位与当前配置不一致，需重新应用';
        } else {
            $result['msg'] = '已按 H.264 转码(' . implode('/', array_column($ladder, 'height')) . 'P)';
        }

        return $result;
    }

    /**
     * 确保上传使用的工作流输出 H.264 编码
     *
     * 已配置模板时就地改造(保留封面截图、水印、结束节点等原有配置)，
     * 未配置时创建一个仅包含 H.264 转码的新模板并写入配置。
     *
     * @return array
     */
    public function ensureH264WorkflowTemplate()
    {
        $ladder = $this->getH264TranscodeTemplates();

        if (empty($ladder)) {
            return ['success' => false, 'msg' => '未找到可用的 H.264 MP4 转码模板，请确认点播空间预设模板'];
        }

        $templateId = (string)($this->settings['volc_trans_template'] ?? '');

        $template = $templateId ? $this->client->getWorkflowTemplate($templateId) : false;

        if ($templateId && !$template) {
            $error = $this->client->getLastError();
            return ['success' => false, 'msg' => '读取工作流模板失败: ' . ($error['message'] ?: $error['code'])];
        }

        $backupFile = '';

        if ($template) {

            $inspect = $this->inspectTemplateData($template, $ladder);

            if ($inspect['h264_ready']) {
                return [
                    'success' => true,
                    'changed' => false,
                    'template_id' => $templateId,
                    'msg' => sprintf('工作流模板「%s」已是 H.264 转码配置(%s)', $inspect['name'], $inspect['msg']),
                ];
            }

            $backupFile = $this->backupWorkflowTemplate($template);

            $payload = $this->buildH264WorkflowPayload($template, $ladder);

            if (!$this->client->updateWorkflowTemplate($templateId, $payload)) {
                $error = $this->client->getLastError();
                return ['success' => false, 'msg' => '更新工作流模板失败: ' . ($error['message'] ?: $error['code'])];
            }

        } else {

            $payload = $this->buildH264WorkflowPayload([], $ladder);

            $newTemplateId = $this->client->createWorkflowTemplate($payload);

            if (!$newTemplateId) {
                $error = $this->client->getLastError();
                return ['success' => false, 'msg' => '创建工作流模板失败: ' . ($error['message'] ?: $error['code'])];
            }

            $templateId = $newTemplateId;

            $this->updateVodSetting('volc_trans_template', $templateId);
        }

        $verify = $this->inspectWorkflowTemplate($templateId);

        if (empty($verify['h264_ready'])) {
            return ['success' => false, 'msg' => '工作流模板已提交，但 H.264 校验未通过，请到火山引擎点播控制台检查'];
        }

        $definitions = implode('/', array_map(function ($item) {
            return $item['height'] . 'P';
        }, $ladder));

        $msg = sprintf('工作流模板「%s」已切换为 H.264 转码(%s)', $verify['name'], $definitions);

        if ($backupFile) {
            $msg .= '，原配置已备份';
        }

        $this->logger->info("Volc H264 Workflow: {$msg}, TemplateId={$templateId}, Backup={$backupFile}");

        return [
            'success' => true,
            'changed' => true,
            'template_id' => $templateId,
            'backup' => $backupFile,
            'activities' => $verify['transcode_activities'],
            'msg' => $msg,
        ];
    }

    /**
     * 构造 H.264 工作流模板提交数据(保留非转码节点与水印配置)
     *
     * @param array $template
     * @param array $ladder
     * @return array
     */
    protected function buildH264WorkflowPayload(array $template, array $ladder)
    {
        $logoTemplateId = '';

        $kept = [];

        $end = null;

        foreach (($template['Activities'] ?? []) as $activity) {
            $type = (string)($activity['Type'] ?? '');

            if (in_array($type, ['TranscodeVideo', 'AdaptBitrateTranscode'], true)) {
                if ($logoTemplateId === '') {
                    $logoTemplateId = (string)($activity['Params']['LogoTemplateId'] ?? '');
                }
                continue;
            }

            if ($type == 'End') {
                $end = $activity;
                continue;
            }

            $kept[] = $activity;
        }

        $activities = $kept;

        foreach ($ladder as $item) {
            $activities[] = [
                'ActivityId' => sprintf('TranscodeVideo_h264_%dp', $item['height']),
                'Name' => $item['name'],
                'Description' => '转码输出 H.264 编码的 MP4 文件，保障浏览器直接播放',
                'Type' => 'TranscodeVideo',
                'Params' => [
                    'TemplateId' => $item['template_id'],
                    'LogoTemplateId' => $logoTemplateId,
                    'FileName' => '',
                    'Parallel' => ['Enabled' => false],
                    'Subtitle' => ['Language' => '', 'FontType' => '', 'SubtitleStyleTemplateId' => ''],
                    'Condition' => ['ResRange' => $item['res_range']],
                ],
                'Dependencies' => null,
                'Priority' => 0,
                'ErrorCatch' => false,
            ];
        }

        $activities[] = $end ?: [
            'ActivityId' => 'End',
            'Name' => 'End',
            'Type' => 'End',
            'Params' => ['TranscodeEvent' => 'AllSuccess'],
            'Priority' => 0,
            'ErrorCatch' => false,
        ];

        return [
            'Name' => $template['Name'] ?? '' ?: self::H264_WORKFLOW_NAME,
            'Description' => $template['Description'] ?? '' ?: '上传后自动转码为 H.264 MP4，保障各端浏览器直接播放',
            'SkipCallback' => (bool)($template['SkipCallback'] ?? false),
            'SkipUpdateVideoStatus' => (bool)($template['SkipUpdateVideoStatus'] ?? false),
            'Activities' => $activities,
        ];
    }

    /**
     * 备份工作流模板原始配置，便于回滚
     *
     * @param array $template
     * @return string 备份文件路径
     */
    protected function backupWorkflowTemplate(array $template)
    {
        $templateId = (string)($template['TemplateId'] ?? 'unknown');

        $dir = storage_path('backup/volc_workflow');

        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $file = $dir . '/' . $templateId . '-' . date('YmdHis') . '.json';

        return @file_put_contents($file, kg_json_encode($template)) ? $file : '';
    }

    /**
     * 更新单个点播配置项并刷新缓存
     *
     * @param string $key
     * @param string $value
     * @return void
     */
    protected function updateVodSetting($key, $value)
    {
        $settingRepo = new \App\Repos\Setting();

        $item = $settingRepo->findItem('vod', $key);

        if ($item) {
            $item->item_value = $value;
            $item->update();
        } else {
            $item = new \App\Models\Setting();
            $item->section = 'vod';
            $item->item_key = $key;
            $item->item_value = $value;
            $item->create();
        }

        $cache = new \App\Caches\Setting();

        $cache->rebuild('vod');

        $this->settings[$key] = $value;
    }

    /**
     * 清理播放地址缓存(转码结果变更后调用)
     *
     * @param string $fileId
     * @return void
     */
    public function clearPlayUrlCache($fileId)
    {
        if (empty($fileId)) return;

        $redis = $this->getRedis();

        if (!$redis) return;

        $redis->del("vod:play_urls:{$fileId}");

        $redis->del("vod:play_urls_miss:{$fileId}");
    }

    /**
     * 判断视频是否需要重新转码为 H.264
     *
     * 仅当源片为浏览器不支持的编码(如 HEVC)且尚无可播放转码流时返回 true
     *
     * @param string $fileId
     * @return bool
     */
    public function needsH264Transcode($fileId)
    {
        if (empty($fileId)) return false;

        $info = $this->client->getMediaInfos($fileId);

        $media = $info['MediaInfoList'][0] ?? [];

        if (empty($media)) return false;

        $sourceCodec = strtolower((string)($media['SourceInfo']['Codec'] ?? ''));

        if ($this->isPlayableCodec($sourceCodec)) return false;

        foreach (($media['TranscodeInfos'] ?? []) as $item) {
            $codec = strtolower((string)($item['VideoStreamMeta']['Codec'] ?? ''));
            if ($codec !== '' && $this->isPlayableCodec($codec)) return false;
        }

        return true;
    }

    /**
     * 对指定视频重新触发 H.264 转码
     *
     * @param string $fileId
     * @return string|bool 工作流运行ID
     */
    public function retranscodeToH264($fileId)
    {
        $runId = $this->client->startWorkflow($fileId);

        if (!$runId) return false;

        $this->clearPlayUrlCache($fileId);

        $this->logger->info("Volc Retranscode: Vid={$fileId} 已触发 H.264 转码, RunId={$runId}");

        return $runId;
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