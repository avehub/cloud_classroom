<?php


require_once 'SettingTrait.php';

use Phinx\Migration\AbstractMigration;

final class V20260905000001 extends AbstractMigration
{

    use SettingTrait;

    public function up()
    {
        $this->handleStorageSettings();
    }

    protected function handleStorageSettings()
    {
        $rows = [
            [
                'section' => 'storage',
                'item_key' => 'driver',
                'item_value' => 'cos',
            ],
        ];

        $this->insertSettings($rows);
    }

}