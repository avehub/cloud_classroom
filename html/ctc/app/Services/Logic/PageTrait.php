<?php


namespace App\Services\Logic;

use App\Validators\Page as PageValidator;

trait PageTrait
{

    public function checkPage($id)
    {
        $validator = new PageValidator();

        return $validator->checkPage($id);
    }

}
