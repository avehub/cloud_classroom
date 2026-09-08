<?php


namespace App\Services;

use App\Traits\Auth as AuthTrait;
use App\Traits\Service as ServiceTrait;
use Phalcon\Mvc\User\Component;

class Service extends Component
{
    use AuthTrait;
    use ServiceTrait;
}
