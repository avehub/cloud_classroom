<?php


namespace App\Caches;

use App\Models\Question as QuestionModel;
use App\Repos\Question as QuestionRepo;
use App\Services\Logic\Question\QuestionList as QuestionListService;

class IndexQuestionList extends Cache
{

    protected $lifetime = 3600;

    public function getLifetime()
    {
        return $this->lifetime;
    }

    public function getKey($id = null)
    {
        return 'index_question_list';
    }

    public function getContent($id = null)
    {
        $questionRepo = new QuestionRepo();

        $where = [
            'published' => QuestionModel::PUBLISH_APPROVED,
            'deleted' => 0,
        ];

        $pager = $questionRepo->paginate($where, 'latest', 1, 10);

        $service = new QuestionListService();

        $pager = $service->handleQuestions($pager);

        return $pager->items ?: [];
    }

}
