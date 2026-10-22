<?php


namespace App\Http\Admin\Controllers;

use App\Http\Admin\Services\VipRedeem as VipRedeemService;

/**
 * @RoutePrefix("/admin/vip/redeem")
 */
class VipRedeemController extends Controller
{

    /**
     * @Get("/batch/list", name="admin.vip_redeem.list")
     */
    public function listAction()
    {
        $service = new VipRedeemService();

        $pager = $service->getBatches();

        $this->view->setVar('pager', $pager);
    }

    /**
     * @Get("/batch/add", name="admin.vip_redeem.add")
     */
    public function addAction()
    {
    }

    /**
     * @Post("/batch/create", name="admin.vip_redeem.create")
     */
    public function createAction()
    {
        $service = new VipRedeemService();

        $batch = $service->createBatch();

        $location = $this->url->get(['for' => 'admin.vip_redeem.codes']);
        $location .= '?batch_id=' . $batch->id;

        return $this->jsonSuccess([
            'location' => $location,
            'msg' => '兑换码生成成功',
        ]);
    }

    /**
     * @Post("/batch/{id:[0-9]+}/update", name="admin.vip_redeem.update")
     */
    public function updateAction($id)
    {
        $service = new VipRedeemService();

        $service->updateBatch($id);

        return $this->jsonSuccess(['msg' => '更新批次成功']);
    }

    /**
     * @Get("/code/list", name="admin.vip_redeem.codes")
     */
    public function codesAction()
    {
        $service = new VipRedeemService();

        $result = $service->getCodes();

        $this->view->setVar('pager', $result['pager']);
        $this->view->setVar('code_items', new \ArrayIterator($result['items']));
        $this->view->setVar('status_types', $service->getCodeStatusTypes());
        $this->view->setVar('filters', $this->request->getQuery());
    }

    /**
     * @Get("/code/search", name="admin.vip_redeem.search")
     */
    public function searchAction()
    {
        $service = new VipRedeemService();

        $this->view->setVar('status_types', $service->getCodeStatusTypes());
    }

    /**
     * @Post("/code/{id:[0-9]+}/disable", name="admin.vip_redeem.disable")
     */
    public function disableAction($id)
    {
        $service = new VipRedeemService();

        $service->disableCode($id);

        return $this->jsonSuccess(['msg' => '停用兑换码成功']);
    }

    /**
     * @Post("/code/export", name="admin.vip_redeem.export")
     */
    public function exportAction()
    {
        $service = new VipRedeemService();

        try {
            $content = $service->exportCodes();
        } catch (\App\Exceptions\BadRequest $e) {
            $this->flashSession->error($this->getErrorMessage($e->getMessage()));

            $params = $this->request->getPost();
            unset($params['csrf_token'], $params['code']);

            $location = $this->url->get(['for' => 'admin.vip_redeem.codes']);
            $query = http_build_query($params);

            if ($query) {
                $location .= '?' . $query;
            }

            return $this->response->redirect($location);
        }

        $this->response->setHeader('Content-Type', 'text/csv; charset=utf-8');
        $this->response->setHeader('Content-Disposition', 'attachment; filename="vip-redeem-codes.csv"');
        $this->response->setContent($content);

        return $this->response;
    }

}
