<?php


namespace App\Services\Logic\Chapter;

use App\Exceptions\BadRequest as BadRequestException;
use App\Exceptions\Forbidden as ForbiddenException;
use App\Library\PlayToken;
use App\Models\Course as CourseModel;
use App\Services\ChapterVod as ChapterVodService;
use App\Services\Logic\ChapterTrait;
use App\Services\Logic\CourseTrait;
use App\Services\Logic\Course\CourseUserTrait;
use App\Services\Logic\Service as LogicService;
use App\Traits\Client as ClientTrait;

/**
 * 点播播放票据
 *
 * 第一阶段只做播放授权和防盗链，不改变视频文件本身。
 */
class PlayTicket extends LogicService
{

    use ChapterTrait;
    use CourseTrait;
    use CourseUserTrait;
    use ChapterUserTrait;
    use ClientTrait;

    /**
     * 每分钟最多申请次数
     */
    const RATE_LIMIT = 60;

    /**
     * @param int $chapterId
     * @return array
     */
    public function handle($chapterId, $admin = false)
    {
        $user = $this->getLoginUser();

        if (!empty($user->locked) || !empty($user->deleted)) {
            throw new ForbiddenException('sys.forbidden');
        }

        $chapter = $this->checkChapter($chapterId);

        if ($chapter->model != CourseModel::MODEL_VOD) {
            throw new BadRequestException('chapter.not_found');
        }

        if (!$admin && ($chapter->published == 0 || $chapter->deleted == 1)) {
            throw new BadRequestException('chapter.not_found');
        }

        if (!$admin) {
            $course = $this->checkCourse($chapter->course_id);

            $this->setCourseUser($course, $user);
            $this->setChapterUser($chapter, $user);

            if (!$this->ownedChapter) {
                throw new ForbiddenException('sys.forbidden');
            }
        }

        $this->checkRateLimit($user->id, $chapter->id);

        $chapterVodService = new ChapterVodService();

        $playUrls = $chapterVodService->getPlayUrls($chapter->id);

        if (empty($playUrls)) {
            $this->logEmptyPlayUrls($chapter);
            throw new BadRequestException('chapter.vod_not_ready');
        }

        $tokenService = new PlayToken();

        $tokenTtl = $this->getTokenTtl($playUrls, $tokenService);

        $clientIp = $this->getPlaybackClientIp();
        $userAgent = (string)$this->request->getHeader('User-Agent');
        $sessionHash = $tokenService->hashSession($this->session->getId());

        $result = [];

        foreach ($playUrls as $quality => $item) {

            $url = trim((string)($item['url'] ?? ''));

            if ($url === '') continue;

            $url = $this->normalizePlayUrl($url, $tokenService);

            $protected = $this->isProtectableUrl($url);

            if (!$protected) {
                $this->getLogger('vod')->warning('PlayTicket: 未受保护域名仍返回播放地址 ' . $this->getUrlHost($url) . ', chapter=' . $chapter->id);
            }

            $claims = [
                'uid' => (int)$user->id,
                'cid' => (int)$chapter->id,
                'sid' => $sessionHash,
                'ip' => $tokenService->hashIp($clientIp),
                'ua' => $tokenService->hashUa($userAgent),
                'u' => $tokenService->hashUrl($url),
                'p' => $tokenService->hashPath($url),
            ];

            $token = $tokenService->create($claims, $tokenTtl);

            $item['url'] = $this->appendQuery($url, 'pt', $token);
            $item['protected'] = $protected;

            $result[$quality] = $item;
        }

        if (empty($result)) {
            throw new BadRequestException('chapter.vod_not_ready');
        }

        $this->getLogger('vod')->info(sprintf(
            'PlayTicket: uid=%d, chapter=%d, streams=%d, ttl=%d',
            $user->id,
            $chapter->id,
            count($result),
            $tokenTtl
        ));

        return [
            'chapter_id' => (int)$chapter->id,
            'expires_in' => $tokenTtl,
            'play_urls' => $result,
            'watermark' => [
                'uid' => (int)$user->id,
                'name' => $this->maskName($user->name),
            ],
        ];
    }

    /**
     * 获取播放请求的真实客户端 IP
     *
     * 默认关闭 IP 绑定；开启时必须保证 Nginx/CDN 正确转发 X-Forwarded-For 或 X-Real-IP。
     *
     * @return string
     */
    protected function getPlaybackClientIp()
    {
        $forwarded = (string)$this->request->getHeader('X-Forwarded-For');

        if ($forwarded !== '') {
            $parts = explode(',', $forwarded);
            return trim($parts[0]);
        }

        $realIp = (string)$this->request->getHeader('X-Real-IP');

        if ($realIp !== '') return trim($realIp);

        return $this->getClientIp();
    }

    /**
     * 记录点播地址为空的诊断信息
     *
     * @param \App\Models\Chapter $chapter
     */
    protected function logEmptyPlayUrls($chapter)
    {
        $repo = new \App\Repos\Chapter();

        $vod = $repo->findChapterVod($chapter->id);

        $transcode = $vod->file_transcode ?? [];

        if (is_string($transcode)) {
            $transcode = json_decode($transcode, true) ?: [];
        }

        $this->getLogger('vod')->warning('PlayTicket: empty play urls ' . kg_json_encode([
                'chapter_id' => $chapter->id,
                'file_id' => (string)($vod->file_id ?? ''),
                'has_remote' => !empty($vod->file_remote),
                'transcode_count' => is_array($transcode) ? count($transcode) : 0,
            ]));
    }

    /**
     * @param int $userId
     * @param int $chapterId
     * @throws BadRequestException
     */
    protected function checkRateLimit($userId, $chapterId)
    {
        $redis = $this->getRedis();

        if (!$redis) return;

        $key = 'rate:play_ticket:' . intval($userId) . ':' . intval($chapterId);

        $count = (int)$redis->incr($key);

        // 防止 INCR 与 EXPIRE 之间进程中断导致 key 永久无 TTL
        if ($count === 1 || $redis->ttl($key) < 0) {
            $redis->expire($key, 60);
        }

        if ($count > self::RATE_LIMIT) {
            throw new BadRequestException('sys.too_many_requests');
        }
    }

    /**
     * @param array $playUrls
     * @param PlayToken $tokenService
     * @return int
     */
    protected function getTokenTtl(array $playUrls, PlayToken $tokenService)
    {
        $duration = 0;

        foreach ($playUrls as $item) {
            $duration = max($duration, intval($item['duration'] ?? 0));
        }

        if ($duration > 0) {
            $ttl = max(3600, $duration + 1800);
        } else {
            $ttl = $tokenService->getLifetime();
        }

        return min(max($ttl, 600), 4 * 3600);
    }

    /**
     * @param string $url
     * @param PlayToken $tokenService
     * @return string
     */
    protected function normalizePlayUrl($url, PlayToken $tokenService)
    {
        if ($tokenService->isForceHttps() && stripos($url, 'http://') === 0) {
            return 'https://' . substr($url, 7);
        }

        return $url;
    }

    /**
     * @param string $url
     * @return bool
     */
    protected function isProtectableUrl($url)
    {
        $hosts = $this->getProtectHosts();

        if (empty($hosts)) return true;

        $host = strtolower((string)parse_url($url, PHP_URL_HOST));

        return $host !== '' && in_array($host, $hosts, true);
    }

    /**
     * @return array
     */
    protected function getProtectHosts()
    {
        $config = $this->getConfig();

        $hosts = (array)$config->path('play_token.protect_hosts', []);

        if (empty($hosts)) {
            $vod = $this->getSettings('vod');
            if (!empty($vod['domain'])) {
                $hosts[] = $vod['domain'];
            }
        }

        $result = [];

        foreach ($hosts as $host) {
            $host = strtolower(trim((string)$host));
            if (strpos($host, '://') !== false) {
                $host = strtolower((string)parse_url($host, PHP_URL_HOST));
            }
            if ($host !== '') $result[] = $host;
        }

        return array_values(array_unique($result));
    }

    /**
     * @param string $url
     * @return string
     */
    protected function getUrlHost($url)
    {
        return strtolower((string)parse_url($url, PHP_URL_HOST));
    }

    /**
     * @param string $url
     * @param string $name
     * @param string $value
     * @return string
     */
    protected function appendQuery($url, $name, $value)
    {
        $separator = strpos($url, '?') === false ? '?' : '&';

        return $url . $separator . rawurlencode($name) . '=' . rawurlencode($value);
    }

    /**
     * @param string $name
     * @return string
     */
    protected function maskName($name)
    {
        $name = trim((string)$name);

        if ($name === '') return '用户';

        $length = mb_strlen($name, 'UTF-8');

        if ($length <= 1) return $name . '*';

        return mb_substr($name, 0, 1, 'UTF-8') . str_repeat('*', min(3, $length - 1));
    }

}
