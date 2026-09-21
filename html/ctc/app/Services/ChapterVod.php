<?php


namespace App\Services;

use App\Repos\Chapter as ChapterRepo;
use App\Services\Vod as VodService;

class ChapterVod extends Service
{

    public function getPlayUrls($chapterId)
    {
        $chapterRepo = new ChapterRepo();

        $vod = $chapterRepo->findChapterVod($chapterId);

        /**
         * 腾讯云点播优先
         */
        if ($vod->file_id) {
            $playUrls = $this->getCosPlayUrls($chapterId);
        } else {
            $playUrls = $this->getRemotePlayUrls($chapterId);
        }

        /**
         *过滤播放地址为空的条目
         */
        foreach ($playUrls as $key => $value) {
            if (empty($value['url'])) unset($playUrls[$key]);
        }

        return $playUrls;
    }

    public function getCosPlayUrls($chapterId)
    {
        $chapterRepo = new ChapterRepo();

        $vod = $chapterRepo->findChapterVod($chapterId);

        if (!$vod || empty($vod->file_id)) return [];

        $vodService = new VodService();

        // 优先按需通过 file_id (Vid) 获取火山官方实时带签名的有效播放流（带短时 Redis 缓存）
        $transcode = $vodService->getLivePlayTranscode($vod->file_id);

        if (empty($transcode) && !empty($vod->file_transcode)) {
            $transcode = $vod->file_transcode;
        }

        if (empty($transcode)) return [];

        // 按分辨率由高到低排序后依次映射为 高清/标清/极速，避免多档转码流相互覆盖
        usort($transcode, function ($a, $b) {
            return intval($b['height'] ?? 0) <=> intval($a['height'] ?? 0);
        });

        $types = ['hd', 'sd', 'fd'];

        $result = [];

        foreach ($transcode as $key => $file) {
            if (empty($file['url'])) continue;

            $file['url'] = $vodService->getPlayUrl($file['url']);

            $type = isset($types[$key]) ? $types[$key] : $this->getDefinitionType($file['height']);

            if (isset($result[$type])) continue;

            $result[$type] = $file;
        }

        return $result;
    }

    public function getRemotePlayUrls($chapterId)
    {
        $chapterRepo = new ChapterRepo();

        $vod = $chapterRepo->findChapterVod($chapterId);

        $result = [
            'hd' => ['url' => ''],
            'sd' => ['url' => ''],
            'fd' => ['url' => ''],
        ];

        if (!empty($vod->file_remote)) {
            $result = $vod->file_remote;
        }

        return $result;
    }

    protected function getDefinitionType($height)
    {
        $default = 'sd';

        $vodTemplates = $this->getVodTemplates();

        foreach ($vodTemplates as $key => $template) {
            if ($height >= $template['height']) {
                return $key;
            }
        }

        return $default;
    }

    protected function getVodTemplates()
    {
        return [
            'hd' => ['height' => 1080, 'rate' => 2500],
            'sd' => ['height' => 720, 'rate' => 1800],
            'fd' => ['height' => 540, 'rate' => 1000],
        ];
    }

}
