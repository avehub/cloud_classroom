<?php


namespace App\Library;

use Phalcon\Di\Di;

/**
 * 点播播放票据
 *
 * 票据使用 HMAC-SHA256 签名，不依赖数据库；默认绑定用户、章节、会话、UA 和播放地址。
 * 该票据用于第一阶段防盗链，不等同于视频内容加密。
 */
class PlayToken
{

    /**
     * @var string
     */
    protected $secret;

    /**
     * @var int
     */
    protected $lifetime;

    /**
     * @var bool
     */
    protected $bindIp;

    /**
     * @var bool
     */
    protected $bindUa;

    public function __construct()
    {
        $config = Di::getDefault()->getShared('config');

        $this->secret = (string)($config->path('play_token.secret') ?: $config->get('key'));
        $this->lifetime = (int)($config->path('play_token.lifetime') ?: 7200);
        $this->bindIp = (bool)$config->path('play_token.bind_ip', false);
        $this->bindUa = (bool)$config->path('play_token.bind_ua', true);

        if ($this->secret === '') {
            throw new \RuntimeException('播放票据密钥未配置，请设置 APP_KEY 或 PLAY_TOKEN_SECRET');
        }
    }

    /**
     * 签发票据
     *
     * @param array $claims
     * @param int|null $ttl
     * @return string
     */
    public function create(array $claims, $ttl = null)
    {
        $now = time();

        $claims = array_merge([
            'v' => 1,
            'iat' => $now,
            'exp' => $now + ($ttl ?: $this->lifetime),
            'n' => bin2hex(random_bytes(8)),
        ], $claims);

        $payload = $this->base64UrlEncode(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $signature = hash_hmac('sha256', $payload, $this->secret, true);

        return $payload . '.' . $this->base64UrlEncode($signature);
    }

    /**
     * 校验票据
     *
     * @param string $token
     * @param string $clientIp
     * @param string $userAgent
     * @return array|false
     */
    public function verify($token, $clientIp = '', $userAgent = '')
    {
        if (!$token || !is_string($token)) return false;

        $parts = explode('.', $token);

        if (count($parts) != 2) return false;

        list($payload, $signature) = $parts;

        $expected = $this->base64UrlEncode(hash_hmac('sha256', $payload, $this->secret, true));

        if (!hash_equals($expected, $signature)) return false;

        $json = $this->base64UrlDecode($payload);

        if ($json === false) return false;

        $claims = json_decode($json, true);

        if (!is_array($claims)) return false;

        if (intval($claims['v'] ?? 0) !== 1) return false;

        if (intval($claims['exp'] ?? 0) < time()) return false;

        if ($this->bindIp && !empty($claims['ip'])) {
            if (!hash_equals((string)$claims['ip'], $this->hashIp($clientIp))) return false;
        }

        if ($this->bindUa && !empty($claims['ua'])) {
            if (!hash_equals((string)$claims['ua'], $this->hashUa($userAgent))) return false;
        }

        return $claims;
    }

    /**
     * @return int
     */
    public function getLifetime()
    {
        return $this->lifetime;
    }

    /**
     * @return bool
     */
    public function isBindUrl()
    {
        return (bool)Di::getDefault()->getShared('config')->path('play_token.bind_url', true);
    }

    /**
     * @return bool
     */
    public function isForceHttps()
    {
        return (bool)Di::getDefault()->getShared('config')->path('play_token.force_https', true);
    }

    /**
     * @param string $ip
     * @return string
     */
    public function hashIp($ip)
    {
        $ip = trim((string)$ip);

        return $ip === '' ? '' : substr(hash('sha256', $ip), 0, 16);
    }

    /**
     * @param string $userAgent
     * @return string
     */
    public function hashUa($userAgent)
    {
        $userAgent = trim((string)$userAgent);

        return $userAgent === '' ? '' : substr(hash('sha256', $userAgent), 0, 16);
    }

    /**
     * @param string $sessionId
     * @return string
     */
    public function hashSession($sessionId)
    {
        $sessionId = trim((string)$sessionId);

        return $sessionId === '' ? '' : substr(hash('sha256', $sessionId), 0, 16);
    }

    /**
     * 完整播放地址哈希
     *
     * @param string $url
     * @return string
     */
    public function hashUrl($url)
    {
        return substr(hash('sha256', $this->normalizeUrl($url)), 0, 24);
    }

    /**
     * 路径+参数哈希，用于 CDN 只回传 path 的场景
     *
     * @param string $url
     * @return string
     */
    public function hashPath($url)
    {
        return substr(hash('sha256', $this->normalizePath($url)), 0, 24);
    }

    /**
     * 规范化播放地址，移除播放票据参数并排序 query
     *
     * @param string $url
     * @return string
     */
    public function normalizeUrl($url)
    {
        $parts = $this->parseUrl($url);

        if ($parts === false) return trim((string)$url);

        $base = '';

        if ($parts['scheme'] !== '') {
            $base .= $parts['scheme'] . '://';
        }

        $base .= $parts['host'] . $parts['port'] . $parts['path'];

        return $parts['query'] !== '' ? $base . '?' . $parts['query'] : $base;
    }

    /**
     * @param string $url
     * @return string
     */
    public function normalizePath($url)
    {
        $parts = $this->parseUrl($url);

        if ($parts === false) return trim((string)$url);

        return $parts['query'] !== '' ? $parts['path'] . '?' . $parts['query'] : $parts['path'];
    }

    /**
     * @param string $url
     * @return array|false
     */
    protected function parseUrl($url)
    {
        $url = html_entity_decode(trim((string)$url), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $parts = parse_url($url);

        if ($parts === false) return false;

        $query = [];

        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
            unset($query['pt']);
            ksort($query);
        }

        return [
            'scheme' => strtolower((string)($parts['scheme'] ?? '')),
            'host' => strtolower((string)($parts['host'] ?? '')),
            'port' => isset($parts['port']) ? ':' . $parts['port'] : '',
            'path' => (string)($parts['path'] ?? ''),
            'query' => empty($query) ? '' : http_build_query($query),
        ];
    }

    /**
     * @param string $data
     * @return string
     */
    protected function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * @param string $data
     * @return string|false
     */
    protected function base64UrlDecode($data)
    {
        $data = strtr((string)$data, '-_', '+/');

        $padding = strlen($data) % 4;

        if ($padding > 0) {
            $data .= str_repeat('=', 4 - $padding);
        }

        return base64_decode($data, true);
    }

}
