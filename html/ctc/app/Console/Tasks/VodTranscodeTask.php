<?php


namespace App\Console\Tasks;

use App\Models\Chapter as ChapterModel;
use App\Models\ChapterVod as ChapterVodModel;
use App\Services\CourseStat as CourseStatService;
use App\Services\Vod as VodService;

/**
 * 火山引擎点播 H.264 转码运维任务
 */
class VodTranscodeTask extends Task
{

    /**
     * 单次最多触发重转码的数量，避免一次性产生大量转码费用
     */
    const RETRANSCODE_LIMIT = 10;

    /**
     * 单次最多扫描的课时视频数量
     */
    const SCAN_LIMIT = 50;

    /**
     * 将上传转码工作流切换为 H.264 编码
     *
     * @command: php console.php --task=vod_transcode --action=h264
     */
    public function h264Action()
    {
        $vodService = new VodService();

        $result = $vodService->ensureH264WorkflowTemplate();

        if (empty($result['success'])) {
            $this->errorPrint($result['msg'] ?? 'H.264 转码配置失败');
            return;
        }

        $this->successPrint($result['msg']);

        if (!empty($result['backup'])) {
            echo '  原配置备份: ', $result['backup'], PHP_EOL;
        }

        foreach (($result['activities'] ?? []) as $activity) {
            printf("  - [%s] %s %s\n", $activity['type'], $activity['name'], $activity['h264'] ? '(H.264)' : '(非H.264)');
        }
    }

    /**
     * 查看当前工作流模板的编码配置与 H.264 转码梯度
     *
     * @command: php console.php --task=vod_transcode --action=inspect
     */
    public function inspectAction()
    {
        $vodService = new VodService();

        $inspect = $vodService->inspectWorkflowTemplate();

        printf("工作流模板: %s (%s)\n", $inspect['name'] ?: '-', $inspect['template_id'] ?: '未配置');
        printf("检测结论: %s\n", $inspect['msg']);

        foreach ($inspect['transcode_activities'] as $activity) {
            printf("  - [%s] %s %s (%s)\n", $activity['type'], $activity['name'], $activity['h264'] ? 'H.264' : '非H.264', $activity['template_id']);
        }

        echo PHP_EOL, 'H.264 转码梯度:', PHP_EOL;

        foreach ($vodService->getH264TranscodeTemplates() as $item) {
            printf("  - %s => %s (源片条件 ResRange=%s)\n", $item['name'], $item['template_id'], $item['res_range'] ?: '不限');
        }
    }

    /**
     * 为源片编码浏览器不支持(如 HEVC/H.265)的课时重新触发 H.264 转码
     *
     * @command: php console.php --task=vod_transcode --action=retranscode
     */
    public function retranscodeAction()
    {
        $vodService = new VodService();

        $inspect = $vodService->inspectWorkflowTemplate();

        if (empty($inspect['h264_ready'])) {
            $this->errorPrint('当前工作流模板未输出 H.264，请先执行: php console.php --task=vod_transcode --action=h264');
            return;
        }

        $vods = ChapterVodModel::find([
            'conditions' => "file_id <> ''",
            'order' => 'id DESC',
            'limit' => self::SCAN_LIMIT,
        ]);

        $handled = 0;

        foreach ($vods as $vod) {

            if ($handled >= self::RETRANSCODE_LIMIT) {
                echo '已达单次处理上限(', self::RETRANSCODE_LIMIT, ')，剩余视频请再次执行', PHP_EOL;
                break;
            }

            $fileId = $vod->file_id;

            if (!$vodService->needsH264Transcode($fileId)) continue;

            $runId = $vodService->retranscodeToH264($fileId);

            if (!$runId) {
                $this->errorPrint("Vid={$fileId} 触发 H.264 转码失败");
                continue;
            }

            // 清空旧转码结果并回退为"转码中"，由回调/兜底任务写入新的 H.264 播放流
            $vod->file_transcode = [];
            $vod->update();

            $this->markChapterTranslating($vod->chapter_id);

            $handled++;

            $this->successPrint("Vid={$fileId} 已触发 H.264 转码, RunId={$runId}");
        }

        if ($handled == 0) {
            echo '没有需要重新转码为 H.264 的视频', PHP_EOL;
        }
    }

    /**
     * 将课时文件状态回退为转码中
     *
     * @param int $chapterId
     * @return void
     */
    protected function markChapterTranslating($chapterId)
    {
        $chapter = ChapterModel::findFirst([
            'conditions' => 'id = :id:',
            'bind' => ['id' => $chapterId],
        ]);

        if (!$chapter) return;

        $attrs = $chapter->attrs;

        $attrs['file']['status'] = ChapterModel::FS_TRANSLATING;

        $chapter->attrs = $attrs;

        $chapter->update();

        $courseStats = new CourseStatService();

        $courseStats->updateVodAttrs($chapter->course_id);
    }

}
