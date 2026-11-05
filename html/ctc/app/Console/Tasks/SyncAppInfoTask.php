<?php


namespace App\Console\Tasks;

use App\Library\AppInfo;
use GuzzleHttp\Client;

class SyncAppInfoTask extends Task
{

    public function mainAction()
    {
        $apiBaseUrl = trim((string)getenv('APP_INFO_SYNC_URL'));

        if ($apiBaseUrl === '') {
            echo '------ app info sync disabled ------' . PHP_EOL;
            return;
        }

        echo '------ start sync app info ------' . PHP_EOL;

        $site = $this->getSettings('site');

        $serverHost = parse_url($site['url'], PHP_URL_HOST);

        $serverIp = gethostbyname($serverHost);

        $appInfo = new AppInfo();

        $params = [
            'server_host' => $serverHost,
            'server_ip' => $serverIp,
            'app_name' => $appInfo->get('name'),
            'app_alias' => $appInfo->get('alias'),
            'app_version' => $appInfo->get('version'),
            'app_link' => $appInfo->get('link'),
        ];

        $client = new Client();

        $url = sprintf('%s/instance/collect', rtrim($apiBaseUrl, '/'));

        $client->request('POST', $url, ['form_params' => $params]);

        echo '------ end sync app info ------' . PHP_EOL;
    }

}
