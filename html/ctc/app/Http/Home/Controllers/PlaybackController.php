<?php


namespace App\Http\Home\Controllers;

use App\Library\PlayToken;
use App\Traits\Service as ServiceTrait;
use Phalcon\Mvc\Controller;

/**
 * CDN 远程鉴权 / 播放票据校验
 *
 * 该控制器不加载站点导航、SEO、登录态等页面资源，避免 CDN 对每个媒体请求回调时产生额外开销。
 *
 * @RoutePrefix("/chapter")
 */
class PlaybackController extends Controller
{

    use ServiceTrait;

    /**
     * 每个播放票据每分钟最多校验次数
     */
    const RATE_LIMIT = 1200;

    /**
     * CDN 远程鉴权入口
     *
     * @Get("/play-auth", name="home.chapter.play_auth")
     */
    public function authAction()
    {
        $token = $this->getPlayToken();
        $uri = $this->getOriginalUri();
        $clientIp = $this->getClientIp();
        $userAgent = $this->getUserAgent();

        try {

            $tokenService = new PlayToken();

        } catch (\Throwable $e) {

            return $this->deny('play token service unavailable: ' . $e->getMessage());
        }

        $claims = $tokenService->verify($token, $clientIp, $userAgent);

        if (!$claims) {
            return $this->deny('invalid or expired token');
        }

        if ($tokenService->isBindUrl() && $uri !== '') {

            $expectedUrlHash = (string)($claims['u'] ?? '');
            $expectedPathHash = (string)($claims['p'] ?? '');

            $urlHashes = [$tokenService->hashUrl($uri)];
            $pathHashes = [$tokenService->hashPath($uri)];

            $decodedUri = urldecode($uri);

            if ($decodedUri !== $uri) {
                $urlHashes[] = $tokenService->hashUrl($decodedUri);
                $pathHashes[] = $tokenService->hashPath($decodedUri);
            }

            $urlMatched = $expectedUrlHash !== '' && in_array($expectedUrlHash, $urlHashes, true);
            $pathMatched = $expectedPathHash !== '' && in_array($expectedPathHash, $pathHashes, true);

            if (!$urlMatched && !$pathMatched) {
                return $this->deny('token url mismatch');
            }
        }

        if (!$this->checkRateLimit($token)) {
            return $this->deny('rate limit exceeded', 429);
        }

        $this->response->setStatusCode(200);
        $this->response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        $this->response->setHeader('Pragma', 'no-cache');
        $this->response->setHeader('Content-Type', 'text/plain; charset=utf-8');
        $this->response->setContent('OK');

        return $this->response;
    }

    /**
     * @return string
     */
    protected function getPlayToken()
    {
        $keys = ['pt', 'token', 'sign', 'auth_token'];

        foreach ($keys as $key) {
            $value = $this->request->getQuery($key, 'string');
            if (!empty($value)) return $value;
        }

        $headers = ['X-Play-Token', 'X-Auth-Token'];

        foreach ($headers as $header) {
            $value = $this->request->getHeader($header);
            if (!empty($value)) return $value;
        }

        return '';
    }

    /**
     * @return string
     */
    protected function getOriginalUri()
    {
        $keys = ['uri', 'request_uri', 'url', 'path'];

        foreach ($keys as $key) {
            $value = $this->request->getQuery($key, 'string');
            if (!empty($value)) return $value;
        }

        $headers = [
            'X-Original-URI',
            'X-Original-Url',
            'X-Forwarded-Uri',
            'X-Forwarded-Url',
            'X-Request-Uri',
        ];

        foreach ($headers as $header) {
            $value = $this->request->getHeader($header);
            if (!empty($value)) return $value;
        }

        return '';
    }

    /**
     * @return string
     */
    protected function getClientIp()
    {
        $forwarded = (string)$this->request->getHeader('X-Forwarded-For');

        if ($forwarded !== '') {
            $parts = explode(',', $forwarded);
            return trim($parts[0]);
        }

        $realIp = (string)$this->request->getHeader('X-Real-IP');

        if ($realIp !== '') return trim($realIp);

        return (string)$this->request->getClientAddress();
    }

    /**
     * @return string
     */
    protected function getUserAgent()
    {
        $forwarded = (string)$this->request->getHeader('X-Forwarded-User-Agent');

        if ($forwarded !== '') return $forwarded;

        return (string)$this->request->getHeader('User-Agent');
    }

    /**
     * @param string $token
     * @return bool
     */
    protected function checkRateLimit($token)
    {
        $redis = $this->getRedis();

        if (!$redis) return true;

        $key = 'rate:play_auth:' . substr(hash('sha256', (string)$token), 0, 24);

        $count = (int)$redis->incr($key);

        if ($count === 1) {
            $redis->expire($key, 60);
        }

        return $count <= self::RATE_LIMIT;
    }

    /**
     * @param string $reason
     * @param int $status
     * @return \Phalcon\Http\Response
     */
    protected function deny($reason, $status = 403)
    {
        $this->getLogger('vod')->warning('PlayAuth denied: ' . $reason);

        $this->response->setStatusCode($status);
        $this->response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        $this->response->setHeader('Pragma', 'no-cache');
        $this->response->setHeader('Content-Type', 'text/plain; charset=utf-8');
        $this->response->setContent($status == 429 ? 'Too Many Requests' : 'Forbidden');

        return $this->response;
    }

}
