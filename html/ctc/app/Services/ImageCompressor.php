<?php


namespace App\Services;

class ImageCompressor extends Service
{

    /**
     * 目标体积（1M）
     */
    const TARGET_SIZE = 1048576;

    /**
     * 起始质量
     */
    const QUALITY_START = 85;

    /**
     * 质量递减步长
     */
    const QUALITY_STEP = 10;

    /**
     * 质量下限
     */
    const QUALITY_MIN = 40;

    /**
     * 压缩图片文件
     *
     * @param string $sourcePath
     * @param string $destPath
     * @return array|false 未压缩返回 false；压缩成功返回 ['mime' => ..., 'extension' => ..., 'size' => ...]
     */
    public function compressFile($sourcePath, $destPath)
    {
        $data = @file_get_contents($sourcePath);

        if ($data === false) {
            return false;
        }

        $result = $this->compressString($data, $this->detectMime($data));

        if ($result['data'] === $data) {
            return false;
        }

        if (@file_put_contents($destPath, $result['data']) === false) {
            return false;
        }

        return [
            'mime' => $result['mime'],
            'extension' => $result['extension'],
            'size' => strlen($result['data']),
        ];
    }

    /**
     * 压缩图片二进制内容
     *
     * 无透明通道的 PNG 会转换为 JPEG 以保证达标，调用方须使用返回的 mime/extension
     *
     * @param string $data
     * @param string $mime
     * @return array ['data' => string, 'mime' => string, 'extension' => string|null]
     */
    public function compressString($data, $mime)
    {
        $mime = $this->detectMime($data) ?: $mime;

        $result = [
            'data' => $data,
            'mime' => $mime,
            'extension' => $this->mimeToExtension($mime),
        ];

        if (strlen($data) <= self::TARGET_SIZE) {
            return $result;
        }

        $type = $this->detectType($data, $mime);

        if (!$type || !$this->isSupported($type)) {
            return $result;
        }

        if ($type == IMAGETYPE_GIF && $this->isAnimatedGif($data)) {
            return $result;
        }

        $image = $this->createImage($data);

        if (!$image) {
            return $result;
        }

        /**
         * 无透明通道的 PNG 转 JPEG 循环降质（PNG 无损重编码无法有效缩减体积）
         */
        if ($type == IMAGETYPE_PNG && !$this->hasAlphaChannel($data, $image)) {

            $jpeg = $this->convertToJpeg($image);

            if ($jpeg !== false && strlen($jpeg) < strlen($data)) {
                imagedestroy($image);

                return [
                    'data' => $jpeg,
                    'mime' => 'image/jpeg',
                    'extension' => 'jpg',
                ];
            }
        }

        $best = null;

        for ($quality = self::QUALITY_START; $quality >= self::QUALITY_MIN; $quality -= self::QUALITY_STEP) {

            $encoded = $this->encodeImage($image, $type, $quality);

            if ($encoded === false) {
                break;
            }

            $best = $encoded;

            if (strlen($encoded) <= self::TARGET_SIZE) {
                break;
            }

            /**
             * BMP/GIF 无质量参数，无需循环降质
             */
            if (in_array($type, [IMAGETYPE_BMP, IMAGETYPE_GIF])) {
                break;
            }
        }

        imagedestroy($image);

        if ($best === null || strlen($best) >= strlen($data)) {
            return $result;
        }

        $result['data'] = $best;

        return $result;
    }

    /**
     * 探测图片 mime 类型
     *
     * @param string $data
     * @return string
     */
    protected function detectMime($data)
    {
        $info = @getimagesizefromstring($data);

        return $info ? $info['mime'] : '';
    }

    /**
     * 探测图片类型（IMAGETYPE_* 常量）
     *
     * @param string $data
     * @param string $mime
     * @return int|null
     */
    protected function detectType($data, $mime)
    {
        $info = @getimagesizefromstring($data);

        if ($info) {
            return $info[2];
        }

        $map = [
            'image/jpeg' => IMAGETYPE_JPEG,
            'image/png' => IMAGETYPE_PNG,
            'image/webp' => IMAGETYPE_WEBP,
            'image/bmp' => IMAGETYPE_BMP,
            'image/x-ms-bmp' => IMAGETYPE_BMP,
            'image/gif' => IMAGETYPE_GIF,
        ];

        return $map[$mime] ?? null;
    }

    /**
     * mime 转扩展名
     *
     * @param string $mime
     * @return string|null
     */
    protected function mimeToExtension($mime)
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/pjpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
            'image/x-ms-bmp' => 'bmp',
            'image/gif' => 'gif',
        ];

        return $map[$mime] ?? null;
    }

    /**
     * 是否为支持压缩的类型（svg/psd/tiff/ico 等不支持）
     *
     * @param int $type
     * @return bool
     */
    protected function isSupported($type)
    {
        $map = [
            IMAGETYPE_JPEG => 'imagecreatefromjpeg',
            IMAGETYPE_PNG => 'imagecreatefrompng',
            IMAGETYPE_WEBP => 'imagecreatefromwebp',
            IMAGETYPE_BMP => 'imagecreatefrombmp',
            IMAGETYPE_GIF => 'imagecreatefromgif',
        ];

        return isset($map[$type]) && function_exists($map[$type]);
    }

    /**
     * 是否为动图GIF
     *
     * @param string $data
     * @return bool
     */
    protected function isAnimatedGif($data)
    {
        return substr_count($data, "\x00\x21\xF9\x04") > 1;
    }

    /**
     * PNG 是否含透明通道
     *
     * 优先解析 IHDR color type（0/2 无透明，3 看 tRNS，4/6 保守视为含透明），
     * 头部解析失败退回像素抽样
     *
     * @param string $data
     * @param resource $image
     * @return bool
     */
    protected function hasAlphaChannel($data, $image)
    {
        if (substr($data, 0, 8) === "\x89PNG\r\n\x1a\n" && substr($data, 12, 4) === 'IHDR' && strlen($data) > 25) {

            $colorType = ord($data[25]);

            if (in_array($colorType, [0, 2])) {
                return false;
            }

            if ($colorType == 3) {
                return strpos($data, 'tRNS') !== false;
            }

            return true;
        }

        $width = imagesx($image);

        $height = imagesy($image);

        $stepX = max(1, (int)($width / 100));

        $stepY = max(1, (int)($height / 100));

        for ($y = 0; $y < $height; $y += $stepY) {
            for ($x = 0; $x < $width; $x += $stepX) {
                $rgba = imagecolorat($image, $x, $y);
                if ((($rgba >> 24) & 0x7F) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 图像转 JPEG（白底防边缘杂色），循环降质
     *
     * @param resource $image
     * @return string|false
     */
    protected function convertToJpeg($image)
    {
        $width = imagesx($image);

        $height = imagesy($image);

        $canvas = imagecreatetruecolor($width, $height);

        if (!$canvas) {
            return false;
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);

        imagefill($canvas, 0, 0, $white);

        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);

        $best = null;

        for ($quality = self::QUALITY_START; $quality >= self::QUALITY_MIN; $quality -= self::QUALITY_STEP) {

            $encoded = $this->encodeImage($canvas, IMAGETYPE_JPEG, $quality);

            if ($encoded === false) {
                break;
            }

            $best = $encoded;

            if (strlen($encoded) <= self::TARGET_SIZE) {
                break;
            }
        }

        imagedestroy($canvas);

        return $best === null ? false : $best;
    }

    /**
     * 从二进制内容创建图像资源
     *
     * @param string $data
     * @return resource|false
     */
    protected function createImage($data)
    {
        if (!function_exists('imagecreatefromstring')) {
            return false;
        }

        return @imagecreatefromstring($data);
    }

    /**
     * 编码图像到内存
     *
     * @param resource $image
     * @param int $type
     * @param int $quality
     * @return string|false
     */
    protected function encodeImage($image, $type, $quality)
    {
        ob_start();

        switch ($type) {
            case IMAGETYPE_JPEG:
                $result = imagejpeg($image, null, $quality);
                break;
            case IMAGETYPE_PNG:
                imagealphablending($image, false);
                imagesavealpha($image, true);
                $result = imagepng($image, null, $this->pngQuality($quality));
                break;
            case IMAGETYPE_WEBP:
                $result = imagewebp($image, null, $quality);
                break;
            case IMAGETYPE_BMP:
                $result = imagebmp($image);
                break;
            case IMAGETYPE_GIF:
                $result = imagegif($image);
                break;
            default:
                $result = false;
        }

        $output = ob_get_clean();

        return $result ? $output : false;
    }

    /**
     * PNG质量映射（0-100 => 0-9）
     *
     * @param int $quality
     * @return int
     */
    protected function pngQuality($quality)
    {
        return (int)round((100 - $quality) / 100 * 9);
    }

}
