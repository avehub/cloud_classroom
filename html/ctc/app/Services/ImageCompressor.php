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
     * @return bool 是否写入了压缩后的文件
     */
    public function compressFile($sourcePath, $destPath)
    {
        $data = @file_get_contents($sourcePath);

        if ($data === false) {
            return false;
        }

        $compressed = $this->compressString($data, $this->detectMime($data));

        if ($compressed === $data) {
            return false;
        }

        return @file_put_contents($destPath, $compressed) !== false;
    }

    /**
     * 压缩图片二进制内容，未压缩时原样返回
     *
     * @param string $data
     * @param string $mime
     * @return string
     */
    public function compressString($data, $mime)
    {
        if (strlen($data) <= self::TARGET_SIZE) {
            return $data;
        }

        $type = $this->detectType($data, $mime);

        if (!$type || !$this->isSupported($type)) {
            return $data;
        }

        if ($type == IMAGETYPE_GIF && $this->isAnimatedGif($data)) {
            return $data;
        }

        $image = $this->createImage($data);

        if (!$image) {
            return $data;
        }

        $best = null;

        for ($quality = self::QUALITY_START; $quality >= self::QUALITY_MIN; $quality -= self::QUALITY_STEP) {

            $result = $this->encodeImage($image, $type, $quality);

            if ($result === false) {
                break;
            }

            $best = $result;

            if (strlen($result) <= self::TARGET_SIZE) {
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
            return $data;
        }

        return $best;
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
