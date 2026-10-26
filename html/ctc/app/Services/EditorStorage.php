<?php


namespace App\Services;

use App\Library\Utils\FileInfo;
use App\Models\Upload as UploadModel;
use App\Repos\Upload as UploadRepo;
use App\Library\Text;

class EditorStorage extends Storage
{

    const MAX_REMOTE_IMAGE_BYTES = 8388608;

    const REMOTE_TIMEOUT = 10;

    public function handle($content)
    {
        $content = $this->handleBase64Image($content);
        $content = $this->handleRemoteImage($content);

        return trim($content);
    }

    protected function handleBase64Image($content)
    {
        $content = preg_replace("/data-ke-src=\"(.*?)\"/", '', $content);

        preg_match_all('/src="(data:image\/(\S+);base64,(\S+))"/U', $content, $matches);

        if (count($matches[3]) > 0) {
            foreach ($matches[3] as $key => $value) {
                $imageUrl = $this->uploadBase64Image($matches[3][$key], $matches[2][$key]);
                $content = str_replace($matches[1][$key], $imageUrl, $content);
            }
        }

        return $content;
    }

    protected function handleRemoteImage($content)
    {
        $baseUrl = $this->getBaseUrl();

        preg_match_all('/<img src="(\S+)"/', $content, $matches);

        if (count($matches[1]) > 0) {
            foreach ($matches[1] as $key => $value) {
                if (!Text::startsWith($value, $baseUrl)) {
                    $imageUrl = $this->uploadRemoteImage($value);
                    $content = str_replace($value, $imageUrl, $content);
                }
            }
        }

        return $content;
    }

    protected function uploadBase64Image($encodeContent, $extension)
    {
        $content = base64_decode($encodeContent);

        $mime = FileInfo::getMimeTypeByExt($extension);

        $compressor = new ImageCompressor();

        $compressed = $compressor->compressString($content, $mime);

        $content = $compressed['data'];

        $mime = $compressed['mime'];

        $extension = $compressed['extension'] ?: $extension;

        $keyName = $this->generateFileName($extension, '/img/content/');

        $md5 = md5($content);

        $uploadRepo = new UploadRepo();

        $upload = $uploadRepo->findByMd5($md5);

        if (!$upload) {

            $uploadPath = $this->putString($keyName, $content);

            if ($uploadPath) {

                $upload = new UploadModel();

                $upload->type = UploadModel::TYPE_CONTENT_IMG;
                $upload->mime = $mime;
                $upload->name = pathinfo($uploadPath, PATHINFO_BASENAME);
                $upload->size = strlen($content);
                $upload->path = $uploadPath;
                $upload->md5 = $md5;

                $upload->create();
            }

            $imageUrl = $uploadPath ? $this->getImageUrl($uploadPath) : '';

        } else {

            $imageUrl = $this->getImageUrl($upload->path);
        }

        return $imageUrl;
    }

    protected function uploadRemoteImage($remoteUrl)
    {
        if (!$this->isAllowedRemoteUrl($remoteUrl)) {
            $this->logger->warning('Blocked remote image url: ' . $remoteUrl);
            return $remoteUrl;
        }

        $extension = $this->getImageExtension($remoteUrl);

        $content = $this->fetchRemoteContent($remoteUrl);

        if ($content === false) return $remoteUrl;

        $mime = FileInfo::getMimeTypeByExt($extension);

        $compressor = new ImageCompressor();

        $compressed = $compressor->compressString($content, $mime);

        $content = $compressed['data'];

        $mime = $compressed['mime'];

        $extension = $compressed['extension'] ?: $extension;

        $keyName = $this->generateFileName($extension, '/img/content/');

        $md5 = md5($content);

        $uploadRepo = new UploadRepo();

        $upload = $uploadRepo->findByMd5($md5);

        if (!$upload) {

            $uploadPath = $this->putString($keyName, $content);

            if ($uploadPath) {

                $upload = new UploadModel();

                $upload->type = UploadModel::TYPE_CONTENT_IMG;
                $upload->mime = $mime;
                $upload->name = pathinfo($uploadPath, PATHINFO_BASENAME);
                $upload->size = strlen($content);
                $upload->path = $uploadPath;
                $upload->md5 = $md5;

                $upload->create();
            }

            $imageUrl = $uploadPath ? $this->getImageUrl($uploadPath) : $remoteUrl;

        } else {

            $imageUrl = $this->getImageUrl($upload->path);
        }

        return $imageUrl;
    }

    /**
     * 判断远程图片地址是否允许服务端抓取
     *
     * 仅允许公网 HTTP(S)，禁止 file/php/gopher 等协议、回环、私网、
     * 链路本地和云元数据地址，避免编辑器成为 LFI/SSRF 入口。
     *
     * @param string $url
     * @return bool
     */
    protected function isAllowedRemoteUrl($url)
    {
        if (!is_string($url) || $url === '') return false;

        $parts = parse_url($url);

        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);

        if (!in_array($scheme, ['http', 'https'], true)) return false;

        $host = trim($parts['host'], '[]');

        if ($this->isBlockedIp($host)) return false;

        $ips = $this->resolveHost($host);

        if (empty($ips)) return false;

        foreach ($ips as $ip) {
            if ($this->isBlockedIp($ip)) return false;
        }

        return true;
    }

    /**
     * 抓取远程图片，禁止重定向并限制响应大小/超时
     *
     * @param string $url
     * @return string|false
     */
    protected function fetchRemoteContent($url)
    {
        $content = '';

        $ch = curl_init($url);

        if ($ch === false) return false;

        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => self::REMOTE_TIMEOUT,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'CTC-EditorStorage/1.0',
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$content) {
                if (strlen($content) + strlen($chunk) > self::MAX_REMOTE_IMAGE_BYTES) {
                    return 0;
                }

                $content .= $chunk;

                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);

        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        $error = curl_error($ch);

        curl_close($ch);

        if ($ok === false || $status < 200 || $status >= 300) {
            $this->logger->warning('Fetch remote image failed: ' . $error . ' status=' . $status);
            return false;
        }

        return $content === '' ? false : $content;
    }

    /**
     * @param string $host
     * @return array
     */
    protected function resolveHost($host)
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) return [$host];

        $ips = [];

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ip'])) $ips[] = $record['ip'];
                if (!empty($record['ipv6'])) $ips[] = $record['ipv6'];
            }
        }

        if (empty($ips)) {
            $ip = @gethostbyname($host);
            if (filter_var($ip, FILTER_VALIDATE_IP)) $ips[] = $ip;
        }

        return array_values(array_unique($ips));
    }

    /**
     * @param string $ip
     * @return bool
     */
    protected function isBlockedIp($ip)
    {
        $long = ip2long($ip);

        if ($long !== false) {
            $ranges = [
                ['0.0.0.0', 8],
                ['10.0.0.0', 8],
                ['100.64.0.0', 10],
                ['127.0.0.0', 8],
                ['169.254.0.0', 16],
                ['172.16.0.0', 12],
                ['192.0.0.0', 24],
                ['192.168.0.0', 16],
                ['198.18.0.0', 15],
                ['224.0.0.0', 4],
                ['240.0.0.0', 4],
            ];

            foreach ($ranges as $range) {
                $mask = $range[1] === 0 ? 0 : (-1 << (32 - $range[1]));
                if (($long & $mask) === (ip2long($range[0]) & $mask)) {
                    return true;
                }
            }

            return false;
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) return false;

        $ip = strtolower($ip);

        if ($ip === '::' || $ip === '::1') return true;

        if (strpos($ip, '::ffff:') === 0) {
            return $this->isBlockedIp(substr($ip, 7));
        }

        return strpos($ip, 'fc') === 0
            || strpos($ip, 'fd') === 0
            || strpos($ip, 'fe8') === 0
            || strpos($ip, 'fe9') === 0
            || strpos($ip, 'fea') === 0
            || strpos($ip, 'feb') === 0;
    }

    /**
     * 例如：https://abc.com/123.jpg!large，这类不规范地址，需要特殊处理
     *
     * @param string $imageUrl
     * @return string
     */
    protected function getImageExtension($imageUrl)
    {
        $path = parse_url($imageUrl, PHP_URL_PATH);

        preg_match('/(\S+)\.(png|gif|jpg|jpeg|webp)/i', $path, $matches);

        return $matches[2] ? strtolower($matches[2]) : 'jpg';
    }

}