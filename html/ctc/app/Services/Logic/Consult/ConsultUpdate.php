<?php


namespace App\Services\Logic\Consult;

use App\Services\Logic\ConsultTrait;
use App\Services\Logic\Service as LogicService;
use App\Validators\Consult as ConsultValidator;

class ConsultUpdate extends LogicService
{

    use ConsultTrait;
    use ConsultDataTrait;

    public function handle($id)
    {
        $post = $this->request->getPost();

        $user = $this->getLoginUser();

        $consult = $this->checkConsult($id);

        $validator = new ConsultValidator();

        $validator->checkEditPriv($consult, $user);

        $data = $this->handlePostData($post);

        $consult->assign($data);

        $consult->update();

        $this->eventsManager->fire('Consult:afterUpdate', $this, $consult);

        return $consult;
    }

}
