<?php


namespace App\Services;

use App\Traits\Auth as AuthTrait;
use App\Traits\Service as ServiceTrait;
use Phalcon\Di\Injectable;

class Service extends Injectable
{
    use AuthTrait;
    use ServiceTrait;
}
