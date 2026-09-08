<?php


namespace App\Services\Logic\Course;

use App\Builders\ResourceList as ResourceListBuilder;
use App\Repos\Course as CourseRepo;
use App\Services\Logic\CourseTrait;
use App\Services\Logic\Service as LogicService;

class ResourceList extends LogicService
{

    use CourseTrait;
    use CourseUserTrait;

    public function handle($id)
    {
        $course = $this->checkCourse($id);

        $user = $this->getCurrentUser();

        $this->setCourseUser($course, $user);

        $courseRepo = new CourseRepo();

        $resources = $courseRepo->findResources($course->id);

        if ($resources->count() == 0) {
            return [];
        }

        $builder = new ResourceListBuilder();

        $relations = $resources->toArray();

        $uploads = $builder->getUploads($relations);

        foreach ($uploads as $key => $upload) {
            $uploads[$key]['me'] = ['owned' => $this->ownedCourse ? 1 : 0];
        }

        return array_values($uploads);
    }

}
