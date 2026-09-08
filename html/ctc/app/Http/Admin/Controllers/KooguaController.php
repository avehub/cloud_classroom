<?php


namespace App\Http\Admin\Controllers;

use App\Traits\Response as ResponseTrait;

/**
 * @RoutePrefix("/admin/gaodekuai")
 */
class KooguaController extends \Phalcon\Mvc\Controller
{

    use ResponseTrait;

    /**
     * @Get("/wiki", name="admin.gaodekuai.wiki")
     */
    public function wikiAction()
    {
        $url = 'https://www.gaodekuai.cn/page/wiki';

        return $this->response->redirect($url, true);
    }

    /**
     * @Get("/community", name="admin.gaodekuai.community")
     */
    public function communityAction()
    {

        $url = 'https://www.gaodekuai.cn/question/list';

        return $this->response->redirect($url, true);
    }

}
