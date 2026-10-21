<?php


namespace App\Repos;

use App\Library\Paginator\Adapter\QueryBuilder as PagerQueryBuilder;
use App\Models\VipRedeemCode as VipRedeemCodeModel;
use Phalcon\Mvc\Model;

class VipRedeemCode extends Repository
{

    public function paginate($where = [], $sort = 'latest', $page = 1, $limit = 15)
    {
        $builder = $this->createListBuilder($where);

        $builder->orderBy($sort == 'oldest' ? 'id ASC' : 'id DESC');

        $pager = new PagerQueryBuilder([
            'builder' => $builder,
            'page' => $page,
            'limit' => $limit,
        ]);

        return $pager->paginate();
    }

    public function findByWhere($where = [])
    {
        $builder = $this->createListBuilder($where);

        $builder->orderBy('id ASC');

        return $builder->getQuery()->execute();
    }

    /**
     * @param int $id
     * @return VipRedeemCodeModel|Model|bool
     */
    public function findById($id)
    {
        return VipRedeemCodeModel::findFirst([
            'conditions' => 'id = :id:',
            'bind' => ['id' => $id],
        ]);
    }

    /**
     * @param string $hash
     * @param bool $forUpdate
     * @return VipRedeemCodeModel|Model|bool
     */
    public function findByHash($hash, $forUpdate = false)
    {
        $params = [
            'conditions' => 'code_hash = :code_hash:',
            'bind' => ['code_hash' => $hash],
        ];

        if ($forUpdate) {
            $params['for_update'] = true;
        }

        return VipRedeemCodeModel::findFirst($params);
    }

    /**
     * @param int $userId
     * @param int $limit
     * @return VipRedeemCodeModel[]
     */
    public function findUserRedeems($userId, $limit = 20)
    {
        return VipRedeemCodeModel::find([
            'conditions' => 'user_id = :user_id: AND status = :status:',
            'bind' => [
                'user_id' => $userId,
                'status' => VipRedeemCodeModel::STATUS_USED,
            ],
            'order' => 'redeem_time DESC',
            'limit' => $limit,
        ]);
    }

    protected function createListBuilder($where)
    {
        $builder = $this->modelsManager->createBuilder();

        $builder->from(VipRedeemCodeModel::class);
        $builder->where('1 = 1');

        if (!empty($where['id'])) {
            $builder->andWhere('id = :id:', ['id' => $where['id']]);
        }

        if (!empty($where['batch_id'])) {
            $builder->andWhere('batch_id = :batch_id:', ['batch_id' => $where['batch_id']]);
        }

        if (isset($where['status']) && $where['status'] !== '') {
            $builder->andWhere('status = :status:', ['status' => $where['status']]);
        }

        if (!empty($where['user_id'])) {
            $builder->andWhere('user_id = :user_id:', ['user_id' => $where['user_id']]);
        }

        if (!empty($where['code_hash'])) {
            $builder->andWhere('code_hash = :code_hash:', ['code_hash' => $where['code_hash']]);
        }

        return $builder;
    }

}
