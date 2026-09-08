<?php


namespace App\Builders;

use App\Repos\User as UserRepo;
use Phalcon\Di\Injectable;

class Builder extends Injectable
{

    public function objects(array $items)
    {
        return kg_objectify($items);
    }

    protected function getShallowUserByIds(array $ids)
    {
        $userRepo = new UserRepo();

        $users = $userRepo->findShallowUserByIds($ids);

        $baseUrl = kg_cos_url();

        $result = [];

        foreach ($users->toArray() as $user) {
            $user['avatar'] = $baseUrl . $user['avatar'];
            $result[$user['id']] = $user;
        }

        return $result;
    }

}
