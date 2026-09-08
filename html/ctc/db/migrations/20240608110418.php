<?php


use Phinx\Migration\AbstractMigration;

final class V20240608110418 extends AbstractMigration
{

    public function up()
    {
        $this->dropRewardTable();
    }

    protected function dropRewardTable()
    {
        $table = $this->table('kg_reward');

        if ($table->exists()) {
            $table->drop()->save();
        }
    }

}
