<?php


use Phinx\Migration\AbstractMigration;

final class V20230625182830 extends AbstractMigration
{

    public function up()
    {
        $this->deleteCaptchaSettings();
    }

    protected function deleteCaptchaSettings()
    {
        $this->getQueryBuilder()
            ->delete('kg_setting')
            ->where(['section' => 'captcha'])
            ->execute();
    }

}
