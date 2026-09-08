<?php


namespace App\Services\Logic;

use App\Validators\Help as HelpValidator;

trait HelpTrait
{

    public function checkHelp($id)
    {
        $validator = new HelpValidator();

        return $validator->checkHelp($id);
    }

}
