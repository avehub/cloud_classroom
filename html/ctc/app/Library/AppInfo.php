<?php


namespace App\Library;

class AppInfo
{

    protected $name = '搞得快云课堂';

    protected $alias = 'GDK Cloud Classroom';

    protected $link = 'https://www.gaodekuai.cn';

    protected $version = '1.0.0';

    public function __get($name)
    {
        return $this->get($name);
    }

    public function get($name)
    {
        if (isset($this->{$name})) {
            return $this->{$name};
        }

        return null;
    }

}
