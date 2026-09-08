<?php


namespace App\Services\Logic;

use App\Validators\Order as OrderValidator;

trait OrderTrait
{

    public function checkOrderById($id)
    {
        $validator = new OrderValidator();

        return $validator->checkById($id);
    }

    public function checkOrderBySn($sn)
    {
        $validator = new OrderValidator();

        return $validator->checkBySn($sn);
    }

}
