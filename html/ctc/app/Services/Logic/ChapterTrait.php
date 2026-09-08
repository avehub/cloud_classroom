<?php


namespace App\Services\Logic;

use App\Validators\Chapter as ChapterValidator;

trait ChapterTrait
{

    public function checkChapterVod($id)
    {
        $validator = new ChapterValidator();

        return $validator->checkChapterVod($id);
    }

    public function checkChapterLive($id)
    {
        $validator = new ChapterValidator();

        return $validator->checkChapterLive($id);
    }

    public function checkChapterRead($id)
    {
        $validator = new ChapterValidator();

        return $validator->checkChapterRead($id);
    }

    public function checkChapter($id)
    {
        $validator = new ChapterValidator();

        return $validator->checkChapter($id);
    }

    public function checkChapterCache($id)
    {
        $validator = new ChapterValidator();

        return $validator->checkChapterCache($id);
    }

}
