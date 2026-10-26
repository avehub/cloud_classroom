<?php


use Phinx\Migration\AbstractMigration;

final class V20261026000001 extends AbstractMigration
{

    public function up()
    {
        $this->widenUserAvatarColumn();
    }

    public function down()
    {
        /**
         * 头像放宽为 255 是为了保存微信外链地址。
         * 回退时不再缩短字段，避免已有外链头像被截断。
         */
    }

    protected function widenUserAvatarColumn()
    {
        $table = $this->table('kg_user');

        $table->changeColumn('avatar', 'string', [
            'null' => false,
            'default' => '',
            'limit' => 255,
            'comment' => '头像',
        ])->update();
    }

}
