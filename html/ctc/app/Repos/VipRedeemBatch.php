<?php


namespace App\Repos;

use App\Library\Paginator\Adapter\QueryBuilder as PagerQueryBuilder;
use App\Models\VipRedeemBatch as VipRedeemBatchModel;
use Phalcon\Mvc\Model;

class VipRedeemBatch extends Repository
{

    public function paginate($where = [], $sort = 'latest', $page = 1, $limit = 15)
    {
        $builder = $this->modelsManager->createBuilder();

        $builder->from(VipRedeemBatchModel::class);
        $builder->where('1 = 1');

        if (!empty($where['id'])) {
            $builder->andWhere('id = :id:', ['id' => $where['id']]);
        }

        if (!empty($where['name'])) {
            $builder->andWhere('name LIKE :name:', ['name' => "%{$where['name']}%"]);
        }

        if (isset($where['status']) && $where['status'] !== '') {
            $builder->andWhere('status = :status:', ['status' => $where['status']]);
        }

        $builder->orderBy($sort == 'oldest' ? 'id ASC' : 'id DESC');

        $pager = new PagerQueryBuilder([
            'builder' => $builder,
            'page' => $page,
            'limit' => $limit,
        ]);

        return $pager->paginate();
    }

    /**
     * @param int $id
     * @return VipRedeemBatchModel|Model|bool
     */
    public function findById($id)
    {
        return VipRedeemBatchModel::findFirst([
            'conditions' => 'id = :id:',
            'bind' => ['id' => $id],
        ]);
    }

}
