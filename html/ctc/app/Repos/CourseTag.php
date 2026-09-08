<?php


namespace App\Repos;

use App\Models\CourseTag as CourseTagModel;
use Phalcon\Mvc\Model;
use Phalcon\Mvc\Model\Resultset;
use Phalcon\Mvc\Model\ResultsetInterface;

class CourseTag extends Repository
{

    /**
     * @param int $courseId
     * @param int $tagId
     * @return CourseTagModel|Model|bool
     */
    public function findCourseTag($courseId, $tagId)
    {
        return CourseTagModel::findFirst([
            'conditions' => 'course_id = :course_id: AND tag_id = :tag_id:',
            'bind' => ['course_id' => $courseId, 'tag_id' => $tagId],
        ]);
    }

    /**
     * @param array $tagIds
     * @return ResultsetInterface|Resultset|CourseTagModel[]
     */
    public function findByTagIds($tagIds)
    {
        return CourseTagModel::query()
            ->inWhere('tag_id', $tagIds)
            ->execute();
    }

}
