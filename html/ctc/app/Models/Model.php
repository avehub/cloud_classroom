<?php


namespace App\Models;

class Model extends \Phalcon\Mvc\Model
{

    public function initialize()
    {
        $this->setup([
            'exceptionOnFailedSave' => true,
            'notNullValidations' => false,
        ]);

        $this->useDynamicUpdate(true);
    }

}
