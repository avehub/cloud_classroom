<?php


namespace App\Http\Admin\Controllers;

use App\Traits\Response as ResponseTrait;

/**
 * @RoutePrefix("/admin/support")
 */
class SupportController extends \Phalcon\Mvc\Controller
{

    use ResponseTrait;

    /**
     * @Get("/wiki", name="admin.support.wiki")
     */
    public function wikiAction()
    {
        $url = trim((string)getenv('APP_SUPPORT_URL')) ?: $this->url->get('/admin');

        return $this->response->redirect($url, true);
    }

    /**
     * @Get("/community", name="admin.support.community")
     */
    public function communityAction()
    {
        $url = trim((string)getenv('APP_COMMUNITY_URL')) ?: $this->url->get('/admin');

        return $this->response->redirect($url, true);
    }

}
