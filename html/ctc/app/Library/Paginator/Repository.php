<?php


namespace App\Library\Paginator;

use Phalcon\Paginator\Repository as PhRepository;

class Repository extends PhRepository
{

    /**
     * @var array
     */
    protected $extraProperties = [];

    public function __get($property)
    {
        if (array_key_exists($property, $this->extraProperties)) {
            return $this->extraProperties[$property];
        }

        if ($property === 'total_pages') {
            return $this->getProperty('total_pages');
        }

        return parent::__get($property);
    }

    public function __set($property, $value)
    {
        $this->extraProperties[$property] = $value;
    }

    public function __isset($property)
    {
        if (array_key_exists($property, $this->extraProperties)) {
            return true;
        }

        return $this->getProperty($property) !== null;
    }

}
