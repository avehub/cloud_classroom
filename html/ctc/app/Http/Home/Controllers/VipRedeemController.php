<?php


namespace App\Http\Home\Controllers;

use App\Services\Logic\Vip\CodeRedeem as CodeRedeemService;
use App\Services\Logic\Vip\RedeemInfo as RedeemInfoService;
use Phalcon\Mvc\Dispatcher;

/**
 * @RoutePrefix("/vip/redeem")
 */
class VipRedeemController extends Controller
{

    public function beforeExecuteRoute(Dispatcher $dispatcher)
    {
        parent::beforeExecuteRoute($dispatcher);

        if ($this->authUser->id == 0) {
            $dispatcher->forward([
                'controller' => 'account',
                'action' => 'login',
            ]);
            return false;
        }

        return true;
    }

    /**
     * @Get("/", name="home.vip_redeem.index")
     */
    public function indexAction()
    {
        $service = new RedeemInfoService();

        $info = $service->handle();

        $this->seo->prependTitle('兑换会员');

        $this->view->pick('vip/redeem');
        $this->view->setVar('user', $info['user']);
        $this->view->setVar('redeems', $info['redeems']);
    }

    /**
     * @Post("/", name="home.vip_redeem.create")
     */
    public function createAction()
    {
        $service = new CodeRedeemService();

        $result = $service->handle();

        $result['msg'] = '永久会员兑换成功';
        $result['location'] = $this->url->get(['for' => 'home.vip_redeem.index']);

        return $this->jsonSuccess($result);
    }

}
