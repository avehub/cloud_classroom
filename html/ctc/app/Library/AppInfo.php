<?php


namespace App\Library;

class AppInfo
{

    protected $name = '云课堂';

    protected $alias = 'Cloud Classroom';

    protected $link = '#';

    protected $version = '1.0.0';

    public function __construct()
    {
        $this->name = getenv('APP_NAME') ?: $this->name;
        $this->alias = getenv('APP_ALIAS') ?: $this->alias;
        $this->link = getenv('APP_LINK') ?: (getenv('SITE_URL') ?: $this->link);
        $this->version = getenv('APP_VERSION') ?: $this->version;
    }

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
