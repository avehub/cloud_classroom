<?php


namespace App\Builders;

use App\Repos\Course as CourseRepo;

class ReviewList extends Builder
{

    public function handleCourses(array $reviews)
    {
        $courses = $this->getCourses($reviews);

        foreach ($reviews as $key => $review) {
            $reviews[$key]['course'] = $courses[$review['course_id']] ?? null;
        }

        return $reviews;
    }

    public function handleUsers(array $reviews)
    {
        $users = $this->getUsers($reviews);

        foreach ($reviews as $key => $review) {
            $reviews[$key]['owner'] = $users[$review['owner_id']] ?? null;
        }

        return $reviews;
    }

    public function getCourses(array $reviews)
    {
        $ids = kg_array_column($reviews, 'course_id');

        $courseRepo = new CourseRepo();

        $courses = $courseRepo->findByIds($ids, ['id', 'title']);

        $result = [];

        foreach ($courses->toArray() as $course) {
            $result[$course['id']] = $course;
        }

        return $result;
    }

    public function getUsers(array $reviews)
    {
        $ids = kg_array_column($reviews, 'owner_id');

        return $this->getShallowUserByIds($ids);
    }

}
