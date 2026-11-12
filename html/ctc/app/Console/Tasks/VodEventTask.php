<?php


namespace App\Console\Tasks;

use App\Models\Chapter as ChapterModel;
use App\Models\Course as CourseModel;
use App\Repos\Chapter as ChapterRepo;
use App\Services\CourseStat as CourseStatService;
use App\Services\Vod as VodService;

class VodEventTask extends Task
{

    /**
     * 单次最多同步的课时数量，避免任务长时间占用与点播接口限流
     */
    const SCAN_LIMIT = 50;

    public function mainAction()
    {
        $this->syncPendingChapters();
    }

    /**
     * 兜底同步：查询处于未完成转码或时长为0的课时，向火山云主动拉取元数据与转码流
     */
    protected function syncPendingChapters()
    {
        $chapterRepo = new ChapterRepo();
        $vodService = new VodService();

        $chapters = $this->findChaptersNeedingSync();

        foreach ($chapters as $chapter) {

            /**
             * 单个课时失败不能中断整批，否则排在其后的课时永远得不到修复
             */
            try {
                $this->syncChapter($chapter, $chapterRepo, $vodService);
            } catch (\Throwable $e) {
                $this->errorPrint("课时#{$chapter->id} 同步点播信息失败: {$e->getMessage()}");
            }
        }
    }

    /**
     * 筛出真正需要兜底同步的点播课时
     *
     * 不再按 id 倒序盲扫最近 50 条：课时一旦滑出窗口就永远得不到修复，
     * 课程时长会一直停留在 0。这里在 SQL 层直接筛出待修复记录并按 id 正序处理，
     * 保证存量积压也能逐步清零。
     *
     * @return \Phalcon\Mvc\Model\ResultsetInterface|\Phalcon\Mvc\Model\Resultset|ChapterModel[]
     */
    protected function findChaptersNeedingSync()
    {
        $translatedLike = '%"status":"' . ChapterModel::FS_TRANSLATED . '"%';
        $failedLike = '%"status":"' . ChapterModel::FS_FAILED . '"%';
        $pendingLike = '%"status":"' . ChapterModel::FS_PENDING . '"%';
        $zeroDurationLike = '%"duration":0%';

        return ChapterModel::query()
            ->where('deleted = 0 AND model = :model:', ['model' => CourseModel::MODEL_VOD])
            // 已标记失败的课时不再轮询，避免反复请求报错与日志刷屏
            ->andWhere('attrs NOT LIKE :failed:', ['failed' => $failedLike])
            // 还没上传过视频的课时无从查询，不占用扫描额度（章级记录 attrs 为 []）
            ->andWhere('attrs NOT LIKE :pending:', ['pending' => $pendingLike])
            ->andWhere('attrs LIKE :has_file:', ['has_file' => '%"file"%'])
            // 仅保留“未转码完成”或“时长仍为 0”的课时
            ->andWhere('(attrs NOT LIKE :translated: OR attrs LIKE :zero_duration:)', [
                'translated' => $translatedLike,
                'zero_duration' => $zeroDurationLike,
            ])
            ->orderBy('id ASC')
            ->limit(self::SCAN_LIMIT)
            ->execute();
    }

    /**
     * 同步单个课时的点播元数据与转码流
     *
     * @param ChapterModel $chapter
     * @param ChapterRepo $chapterRepo
     * @param VodService $vodService
     * @return void
     */
    protected function syncChapter(ChapterModel $chapter, ChapterRepo $chapterRepo, VodService $vodService)
    {
        $attrs = $chapter->attrs;
        $duration = $attrs['duration'] ?? 0;
        $status = $attrs['file']['status'] ?? '';

        if ($duration > 0 && $status == ChapterModel::FS_TRANSLATED) {
            return;
        }

        // 如果该课时已被标记为失败(例如视频在火山端不存在或已删除)，跳过后续请求
        if ($status == ChapterModel::FS_FAILED) {
            return;
        }

        $vod = $chapterRepo->findChapterVod($chapter->id);

        if (!$vod || empty($vod->file_id)) {
            return;
        }

        $fileId = $vod->file_id;

        // 1. 获取源片媒体信息：同时判定视频在火山端是否还存在，并顺带补齐时长
        $state = $vodService->getMediaState($fileId);

        if ($state['exists'] === false) {
            // 视频已删除或上传未提交成功，标记失败以终止无休止轮询与错误日志刷屏
            $attrs['file']['status'] = ChapterModel::FS_FAILED;
            $chapter->attrs = $attrs;
            $chapter->update();

            $this->updateCourseVodAttrs($chapter->course_id);

            $this->errorPrint("课时#{$chapter->id} 视频(Vid={$fileId})在火山端不存在，已标记为转码失败");

            return;
        }

        if ($duration == 0 && !empty($state['source']['Duration'])) {
            $attrs['duration'] = (int)$state['source']['Duration'];
        }

        // 2. 获取转码播放流
        $transcodes = $vodService->getFileTranscode($fileId);

        if (!empty($transcodes)) {
            $vod->file_transcode = $transcodes;
            $vod->update();
            $attrs['file']['status'] = ChapterModel::FS_TRANSLATED;
            if (empty($attrs['duration']) && !empty($transcodes[0]['duration'])) {
                $attrs['duration'] = (int)$transcodes[0]['duration'];
            }
        } elseif (!empty($attrs['duration'])) {
            $attrs['file']['status'] = ChapterModel::FS_TRANSLATING;
        }

        $chapter->attrs = $attrs;
        $chapter->update();

        $this->updateCourseVodAttrs($chapter->course_id);
    }

    protected function handleNewFileUploadEvent($event)
    {
        $fileId = $event['FileUploadEvent']['FileId'] ?? 0;
        $width = $event['FileUploadEvent']['MetaData']['Height'] ?? 0;
        $height = $event['FileUploadEvent']['MetaData']['Width'] ?? 0;
        $duration = $event['FileUploadEvent']['MetaData']['Duration'] ?? 0;

        if ($fileId == 0) return false;

        $chapterRepo = new ChapterRepo();

        $chapter = $chapterRepo->findByFileId($fileId);

        if (!$chapter) return false;

        $attrs = $chapter->attrs;

        /**
         * 获取不到时长，尝试通过主动查询获取
         */
        if ($duration == 0) {
            $duration = $this->getFileDuration($fileId);
        }

        $isVideo = $width > 0 && $height > 0;

        $vodService = new VodService();

        if ($duration > 0) {
            if ($isVideo) {
                $vodService->createTransVideoTask($fileId);
            } else {
                $vodService->createTransAudioTask($fileId);
            }
            $attrs['file']['status'] = ChapterModel::FS_TRANSLATING;
        } else {
            $attrs['file']['status'] = ChapterModel::FS_FAILED;
        }

        $attrs['duration'] = (int)$duration;

        $chapter->attrs = $attrs;

        $chapter->update();

        $this->updateCourseVodAttrs($chapter->course_id);

        return true;
    }

    protected function handleProcedureStateChangedEvent($event)
    {
        $fileId = $event['ProcedureStateChangeEvent']['FileId'] ?? 0;

        if ($fileId == 0) return false;

        $chapterRepo = new ChapterRepo();

        $chapter = $chapterRepo->findByFileId($fileId);

        if (!$chapter) return false;

        $attrs = $chapter->attrs;

        /**
         * 获取不到时长，尝试通过接口获得
         */
        if ($attrs['duration'] == 0) {
            $attrs['duration'] = $this->getFileDuration($fileId);
        }

        $failCount = $successCount = 0;

        $processResult = $event['ProcedureStateChangeEvent']['MediaProcessResultSet'] ?? [];

        if ($processResult) {
            foreach ($processResult as $item) {
                if ($item['Type'] == 'Transcode') {
                    if ($item['TranscodeTask']['Status'] == 'SUCCESS') {
                        $successCount++;
                    } elseif ($item['TranscodeTask']['Status'] == 'FAIL') {
                        $failCount++;
                    }
                }
            }
        }

        $fileStatus = ChapterModel::FS_TRANSLATING;

        if (!$processResult) {
            $fileStatus = ChapterModel::FS_FAILED;
        }

        /**
         * 当有一个成功标记为成功
         */
        if ($successCount > 0) {
            $fileStatus = ChapterModel::FS_TRANSLATED;
        } elseif ($failCount > 0) {
            $fileStatus = ChapterModel::FS_FAILED;
        }

        $attrs['file']['id'] = $fileId;
        $attrs['file']['status'] = $fileStatus;

        $chapter->attrs = $attrs;

        $chapter->update();

        $this->updateCourseVodAttrs($chapter->course_id);

        return true;
    }

    protected function handleFileDeletedEvent($event)
    {
        return true;
    }

    protected function pullEvents()
    {
        $vodService = new VodService();

        if (method_exists($vodService, 'pullEvents')) {
            return $vodService->pullEvents();
        }

        return [];
    }

    protected function confirmEvents($handles)
    {
        $vodService = new VodService();

        if (method_exists($vodService, 'confirmEvents')) {
            return $vodService->confirmEvents($handles);
        }

        return true;
    }

    protected function updateCourseVodAttrs($courseId)
    {
        $courseStats = new CourseStatService();

        $courseStats->updateVodAttrs($courseId);
    }

    protected function getFileDuration($fileId)
    {
        $service = new VodService();

        $metaInfo = $service->getOriginVideoInfo($fileId);

        return $metaInfo['duration'] ?? 0;
    }

}
