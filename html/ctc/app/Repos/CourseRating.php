<?php


namespace App\Repos;

use App\Models\CourseRating as CourseRatingModel;
use App\Models\Review as ReviewModel;
use Phalcon\Mvc\Model;

class CourseRating extends Repository
{

    /**
     * @param int $courseId
     * @return CourseRatingModel|Model|bool
     */
    public function findByCourseId($courseId)
    {
        return CourseRatingModel::findFirst([
            'conditions' => 'course_id = :course_id:',
            'bind' => ['course_id' => $courseId],
        ]);
    }

    public function averageRating($courseId)
    {
        return (float)ReviewModel::average([
            'column' => 'rating',
            'conditions' => 'course_id = :course_id: AND published = :published: AND deleted = 0',
            'bind' => [
                'course_id' => $courseId,
                'published' => ReviewModel::PUBLISH_APPROVED,
            ],
        ]);
    }

    public function averageRating1($courseId)
    {
        return (float)ReviewModel::average([
            'column' => 'rating1',
            'conditions' => 'course_id = :course_id: AND published = :published: AND deleted = 0',
            'bind' => [
                'course_id' => $courseId,
                'published' => ReviewModel::PUBLISH_APPROVED,
            ],
        ]);
    }

    public function averageRating2($courseId)
    {
        return (float)ReviewModel::average([
            'column' => 'rating2',
            'conditions' => 'course_id = :course_id: AND published = :published: AND deleted = 0',
            'bind' => [
                'course_id' => $courseId,
                'published' => ReviewModel::PUBLISH_APPROVED,
            ],
        ]);
    }

    public function averageRating3($courseId)
    {
        return (float)ReviewModel::average([
            'column' => 'rating3',
            'conditions' => 'course_id = :course_id: AND published = :published: AND deleted = 0',
            'bind' => [
                'course_id' => $courseId,
                'published' => ReviewModel::PUBLISH_APPROVED,
            ],
        ]);
    }

    /**
     * 已发布且未删除的评价数量
     *
     * 用于区分"没有评价"与"评价被删完"，两者都不该把课程评分算成 0。
     *
     * @param int $courseId
     * @return int
     */
    public function countPublishedReviews($courseId)
    {
        return (int)ReviewModel::count([
            'conditions' => 'course_id = :course_id: AND published = :published: AND deleted = 0',
            'bind' => [
                'course_id' => $courseId,
                'published' => ReviewModel::PUBLISH_APPROVED,
            ],
        ]);
    }

    /**
     * 读取课程评分记录，缺失时补建一条默认记录
     *
     * 历史数据可能缺失该记录（例如 Phalcon 5 早期自增主键未回填时，
     * Course::afterCreate() 把评分记录写成了 course_id=0），
     * 缺失会让前台评分显示为 0.0，也会让 CourseStat::updateRating() 致命错误。
     *
     * @param int $courseId
     * @return CourseRatingModel|Model|bool
     */
    public function findOrCreateByCourseId($courseId)
    {
        $courseId = (int)$courseId;

        if ($courseId <= 0) return false;

        $courseRating = $this->findByCourseId($courseId);

        if ($courseRating) return $courseRating;

        $courseRating = new CourseRatingModel();

        $courseRating->course_id = $courseId;
        $courseRating->rating = CourseRatingModel::DEFAULT_RATING;
        $courseRating->rating1 = CourseRatingModel::DEFAULT_RATING;
        $courseRating->rating2 = CourseRatingModel::DEFAULT_RATING;
        $courseRating->rating3 = CourseRatingModel::DEFAULT_RATING;

        try {
            $courseRating->create();
        } catch (\Throwable $e) {
            // 并发下可能已被其它请求补建，忽略后统一回读
        }

        return $this->findByCourseId($courseId) ?: false;
    }

}
