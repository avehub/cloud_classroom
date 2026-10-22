<?php


namespace App\Http\Admin\Services;

use App\Exceptions\BadRequest as BadRequestException;
use App\Library\Paginator\Query as PagerQuery;
use App\Models\VipRedeemBatch as VipRedeemBatchModel;
use App\Models\VipRedeemCode as VipRedeemCodeModel;
use App\Repos\VipRedeemBatch as VipRedeemBatchRepo;
use App\Repos\VipRedeemCode as VipRedeemCodeRepo;
use App\Validators\VipRedeem as VipRedeemValidator;

class VipRedeem extends Service
{

    public function getBatches()
    {
        $pagerQuery = new PagerQuery();

        $params = $pagerQuery->getParams();

        $batchRepo = new VipRedeemBatchRepo();

        return $batchRepo->paginate(
            $params,
            $pagerQuery->getSort(),
            $pagerQuery->getPage(),
            $pagerQuery->getLimit()
        );
    }

    public function createBatch()
    {
        $post = $this->request->getPost();

        $validator = new VipRedeemValidator();

        $name = $validator->checkBatchName($post['name']);
        $codeCount = $validator->checkCodeCount($post['code_count']);
        $expireTime = $validator->checkExpireTime($post['expire_time'] ?? '');
        $remark = $validator->checkRemark($post['remark'] ?? '');

        $user = $this->getLoginUser();

        $this->db->begin();

        try {
            $batch = new VipRedeemBatchModel();

            $batch->name = $name;
            $batch->code_count = $codeCount;
            $batch->expire_time = $expireTime;
            $batch->remark = $remark;
            $batch->creator_id = $user->id;
            $batch->creator_name = $user->name;
            $batch->create();

            if ($batch->id < 1) {
                $batch->id = (int)$this->db->lastInsertId();
            }

            if ($batch->id < 1) {
                throw new \RuntimeException('vip_redeem.create_failed');
            }

            $codeRepo = new VipRedeemCodeRepo();

            for ($i = 0; $i < $codeCount; $i++) {
                do {
                    $plainCode = $this->generateCode();
                    $codeHash = hash('sha256', $plainCode);
                } while ($codeRepo->findByHash($codeHash));

                $code = new VipRedeemCodeModel();

                $code->batch_id = $batch->id;
                $code->code = \App\Library\Utils\VipRedeemCode::encrypt($plainCode);
                $code->code_hash = $codeHash;
                $code->create();
            }

            $this->db->commit();

            return $batch;

        } catch (\Exception $e) {
            $this->db->rollback();

            $logger = $this->getLogger();
            $logger->error('Create Vip Redeem Batch Exception: ' . kg_json_encode([
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'message' => $e->getMessage(),
                ]));

            throw new \RuntimeException('sys.trans_rollback');
        }
    }

    public function updateBatch($id)
    {
        $batch = $this->findBatchOrFail($id);

        $status = $this->request->getPost('status');

        if ($status !== null) {
            $validator = new VipRedeemValidator();
            $batch->status = $validator->checkStatus($status);
        }

        $batch->update();

        return $batch;
    }

    public function getCodes()
    {
        $pagerQuery = new PagerQuery();

        $params = $this->normalizeCodeFilters($pagerQuery->getParams());

        $codeRepo = new VipRedeemCodeRepo();

        $pager = $codeRepo->paginate(
            $params,
            $pagerQuery->getSort(),
            $pagerQuery->getPage(),
            $pagerQuery->getLimit()
        );

        $items = [];

        if ($pager->total_items > 0) {
            $items = $this->handleCodes($pager->items);
            $pager->items = $items;
        }

        return [
            'pager' => $pager,
            'items' => $items,
        ];
    }

    public function disableCode($id)
    {
        $codeRepo = new VipRedeemCodeRepo();

        $code = $codeRepo->findById($id);

        if (!$code) {
            throw new BadRequestException('vip_redeem.code_not_found');
        }

        if ($code->status == VipRedeemCodeModel::STATUS_USED) {
            throw new BadRequestException('vip_redeem.code_used_not_allowed');
        }

        $code->status = VipRedeemCodeModel::STATUS_DISABLED;
        $code->update();

        return $code;
    }

    public function exportCodes()
    {
        $params = $this->normalizeCodeFilters($this->request->getPost());

        if (empty($params['batch_id'])) {
            throw new BadRequestException('vip_redeem.batch_not_found');
        }

        $batch = $this->findBatchOrFail($params['batch_id']);

        $codeRepo = new VipRedeemCodeRepo();

        $items = $codeRepo->findByWhere($params);

        if ($items->count() == 0) {
            throw new BadRequestException('vip_redeem.no_export_data');
        }

        $handle = fopen('php://temp', 'r+');

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['批次编号', '批次名称', '兑换码', '状态', '用户编号', '用户名称', '兑换时间', '兑换IP', '生成时间']);

        $statusTypes = VipRedeemCodeModel::statusTypes();

        foreach ($items as $item) {
            $plainCode = \App\Library\Utils\VipRedeemCode::decrypt($item->code);

            fputcsv($handle, [
                $batch->id,
                $batch->name,
                implode('-', str_split($plainCode, 5)),
                $statusTypes[$item->status],
                $item->user_id ?: '',
                $item->user_name,
                $item->redeem_time ? date('Y-m-d H:i:s', $item->redeem_time) : '',
                $item->client_ip,
                date('Y-m-d H:i:s', $item->create_time),
            ]);
        }

        rewind($handle);

        return stream_get_contents($handle);
    }

    public function getCodeStatusTypes()
    {
        return VipRedeemCodeModel::statusTypes();
    }

    public function getBatchStatusTypes()
    {
        return VipRedeemBatchModel::statusTypes();
    }

    protected function normalizeCodeFilters($params)
    {
        if (!empty($params['code'])) {
            $validator = new VipRedeemValidator();
            $code = $validator->checkCode($params['code']);
            $params['code_hash'] = hash('sha256', $code);
            unset($params['code']);
        }

        unset($params['_url'], $params['page'], $params['limit'], $params['sort'], $params['csrf_token']);

        return $params;
    }

    protected function handleCodes($items)
    {
        $batchIds = [];

        foreach ($items as $item) {
            $batchIds[] = $item->batch_id;
        }

        $batchIds = array_unique($batchIds);
        $batches = [];

        if ($batchIds) {
            $batchItems = VipRedeemBatchModel::find([
                'conditions' => 'id IN ({ids:array})',
                'bind' => ['ids' => $batchIds],
            ]);

            foreach ($batchItems as $batch) {
                $batches[$batch->id] = $batch->name;
            }
        }

        $items->rewind();
        $result = [];

        foreach ($items as $item) {
            $plainCode = \App\Library\Utils\VipRedeemCode::decrypt($item->code);

            $result[] = (object)[
                'id' => $item->id,
                'batch_id' => $item->batch_id,
                'batch_name' => $batches[$item->batch_id] ?? 'N/A',
                'code' => substr($plainCode, 0, 4) . '************' . substr($plainCode, -4),
                'plain_code' => implode('-', str_split($plainCode, 5)),
                'status' => $item->status,
                'user_id' => $item->user_id,
                'user_name' => $item->user_name,
                'redeem_time' => $item->redeem_time,
                'client_ip' => $item->client_ip,
                'create_time' => $item->create_time,
            ];
        }

        return $result;
    }

    protected function findBatchOrFail($id)
    {
        $batchRepo = new VipRedeemBatchRepo();

        $batch = $batchRepo->findById($id);

        if (!$batch) {
            throw new BadRequestException('vip_redeem.batch_not_found');
        }

        return $batch;
    }

    protected function generateCode()
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $maxIndex = strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < 20; $i++) {
            $code .= $alphabet[random_int(0, $maxIndex)];
        }

        return $code;
    }

}
