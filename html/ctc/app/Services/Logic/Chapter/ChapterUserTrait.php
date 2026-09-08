<?php


namespace App\Services\Logic\Chapter;

use App\Models\Chapter as ChapterModel;
use App\Models\ChapterUser as ChapterUserModel;
use App\Models\User as UserModel;
use App\Repos\ChapterUser as ChapterUserRepo;

trait ChapterUserTrait
{

    /**
     * @var bool
     */
    protected $ownedChapter = false;

    /**
     * @var bool
     */
    protected $joinedChapter = false;

    /**
     * @var ChapterUserModel|null
     */
    protected $chapterUser;

    public function setChapterUser(ChapterModel $chapter, UserModel $user)
    {
        if ($user->id == 0) return;

        $chapterUser = null;

        $courseUser = $this->courseUser;

        if ($courseUser) {
            $chapterUserRepo = new ChapterUserRepo();
            $chapterUser = $chapterUserRepo->findChapterUser($chapter->id, $user->id);
        }

        $this->chapterUser = $chapterUser;

        if ($chapterUser) {
            $this->joinedChapter = true;
        }

        if ($this->ownedCourse || $chapter->free) {
            $this->ownedChapter = true;
        }
    }

}
