<?php


namespace App\Console\Tasks;

use App\Models\Chapter as ChapterModel;
use App\Repos\Chapter as ChapterRepo;
use App\Services\CourseStat as CourseStatService;
use App\Services\Vod as VodService;

class VodEventTask extends Task
{

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

        // 查找时长为0且未删除的课时 (最多每次同步20条，避免堆积)
        $chapters = ChapterModel::find([
            'conditions' => 'deleted = 0 AND model = 1',
            'order' => 'id DESC',
            'limit' => 50,
        ]);

        foreach ($chapters as $chapter) {
            $attrs = $chapter->attrs;
            $duration = $attrs['duration'] ?? 0;
            $status = $attrs['file']['status'] ?? 0;

            if ($duration > 0 && $status == ChapterModel::FS_TRANSLATED) {
                continue;
            }

            $vod = $chapterRepo->findChapterVod($chapter->id);
            if (!$vod || empty($vod->file_id)) {
                continue;
            }

            $fileId = $vod->file_id;

            // 1. 获取源片媒体信息 (获取时长)
            if ($duration == 0) {
                $originInfo = $vodService->getOriginVideoInfo($fileId);
                if (!empty($originInfo['duration'])) {
                    $attrs['duration'] = (int)$originInfo['duration'];
                }
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
            } elseif ($attrs['duration'] > 0) {
                $attrs['file']['status'] = ChapterModel::FS_TRANSLATING;
            }

            $chapter->attrs = $attrs;
            $chapter->update();

            $this->updateCourseVodAttrs($chapter->course_id);
        }
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
