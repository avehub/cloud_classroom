<?php


namespace App\Http\Admin\Controllers;

use App\Services\Auth\Admin as AdminAuth;
use App\Services\Logic\Chapter\PlayTicket as PlayTicketService;
use App\Traits\Response as ResponseTrait;

/**
 * @RoutePrefix("/admin")
 */
class PublicController extends \Phalcon\Mvc\Controller
{

    use ResponseTrait;

    /**
     * @Get("/auth", name="admin.auth")
     */
    public function authAction()
    {
        $this->response->setStatusCode(401);

        if ($this->request->isAjax()) {
            return $this->jsonError(['msg' => '会话已过期，请重新登录']);
        }

        return $this->response->redirect(['for' => 'admin.login']);
    }

    /**
     * @Get("/forbidden", name="admin.forbidden")
     */
    public function forbiddenAction()
    {
        $this->response->setStatusCode(403);

        if ($this->request->isAjax()) {
            return $this->jsonError(['msg' => '无相关操作权限']);
        }
    }

    /**
     * @Get("/ip2region", name="admin.ip2region")
     */
    public function ip2regionAction()
    {
        $ip = $this->request->getQuery('ip', 'string');

        $region = kg_ip2region($ip);

        $this->view->setVar('region', $region);
    }

    /**
     * 后台点播预览票据
     *
     * @Get("/vod/preview-ticket", name="admin.vod_preview_ticket")
     */
    public function vodPreviewTicketAction()
    {
        $auth = new AdminAuth();

        if (!$auth->getAuthInfo()) {
            $this->response->setStatusCode(401);
            return $this->jsonError(['msg' => '会话已过期，请重新登录']);
        }

        $chapterId = $this->request->getQuery('chapter_id', 'int');

        $service = new PlayTicketService();

        $data = $service->handle($chapterId, true);

        $response = $this->jsonSuccess(['data' => $data]);

        $response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->setHeader('Pragma', 'no-cache');

        return $response;
    }

    /**
     * @Get("/vod/player", name="admin.vod_player")
     */
    public function vodPlayerAction()
    {
        $chapterId = $this->request->getQuery('chapter_id', 'int');
        $playUrl = $this->request->getQuery('play_url', 'string');

        $this->view->pick('public/vod_player');
        $this->view->setVar('chapter_id', $chapterId);
        $this->view->setVar('play_url', $playUrl);
    }

}
