<?php


use Phinx\Migration\AbstractMigration;

require_once 'SettingTrait.php';

final class V20210916072842 extends AbstractMigration
{

    use SettingTrait;

    public function up()
    {
        $this->handleLocalAuthSettings();
    }

    protected function handleLocalAuthSettings()
    {
        $rows = [
            [
                'section' => 'oauth.local',
                'item_key' => 'login_with_phone',
                'item_value' => '1',
            ],
            [
                'section' => 'oauth.local',
                'item_key' => 'login_with_email',
                'item_value' => '1',
            ]
        ];

        $this->insertSettings($rows);
    }

}
