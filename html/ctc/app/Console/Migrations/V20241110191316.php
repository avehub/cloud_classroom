<?php

namespace App\Console\Migrations;

class V20241110191316 extends Migration
{

    public function run()
    {
        $this->handleContactSettings();
    }

    protected function handleContactSettings()
    {
        $setting = [
            'section' => 'contact',
            'item_key' => 'douyin',
            'item_value' => '',
        ];

        $this->saveSetting($setting);
    }

}