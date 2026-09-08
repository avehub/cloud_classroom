<?php


namespace App\Services\Logic\Article;

use App\Services\Logic\ArticleTrait;
use App\Services\Logic\Service as LogicService;
use App\Validators\Validator as AppValidator;

class ArticleClose extends LogicService
{

    use ArticleTrait;

    public function handle($id)
    {
        $article = $this->checkArticle($id);

        $user = $this->getLoginUser();

        $validator = new AppValidator();

        $validator->checkOwner($user->id, $article->owner_id);

        $article->closed = $article->closed == 1 ? 0 : 1;

        $article->update();

        return $article;
    }

}
