<?php


namespace App\Caches;

class AppInfo extends Cache
{

    protected $lifetime = 365 * 86400;

    public function getLifetime()
    {
        return $this->lifetime;
    }

    public function getKey($id = null)
    {
        return "_APP_INFO_";
    }

    public function getContent($id = null)
    {
        $appInfo = new \App\Library\AppInfo();

        return [
            'name' => $appInfo->get('name'),
            'alias' => $appInfo->get('alias'),
            'link' => $appInfo->get('link'),
            'version' => $appInfo->get('version'),
        ];
    }

}
