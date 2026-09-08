<?php


namespace App\Repos;

use App\Models\Resource as ResourceModel;
use Phalcon\Mvc\Model;

class Resource extends Repository
{

    /**
     * @param int $id
     * @return ResourceModel|Model|bool
     */
    public function findById($id)
    {
        return ResourceModel::findFirst([
            'conditions' => 'id = :id:',
            'bind' => ['id' => $id],
        ]);
    }

}
