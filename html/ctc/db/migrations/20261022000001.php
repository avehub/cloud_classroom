<?php


use Phinx\Migration\AbstractMigration;
use Phinx\Db\Adapter\MysqlAdapter;

final class V20261022000001 extends AbstractMigration
{

    public function up()
    {
        $this->updateVipExpiryComment();
        $this->insertPermanentVipPackage();
    }

    public function down()
    {
        $this->execute("DELETE FROM kg_vip WHERE expiry = 0");

        $this->table('kg_vip')->changeColumn('expiry', 'integer', [
            'null' => false,
            'default' => '0',
            'limit' => MysqlAdapter::INT_REGULAR,
            'signed' => false,
            'comment' => '有效期',
        ])->update();
    }

    protected function updateVipExpiryComment()
    {
        $this->table('kg_vip')->changeColumn('expiry', 'integer', [
            'null' => false,
            'default' => '0',
            'limit' => MysqlAdapter::INT_REGULAR,
            'signed' => false,
            'comment' => '有效期（月），0表示永久',
        ])->update();
    }

    protected function insertPermanentVipPackage()
    {
        $count = $this->fetchRow("SELECT COUNT(*) AS total FROM kg_vip WHERE expiry = 0");

        if ($count && $count['total'] > 0) return;

        $now = time();

        $this->table('kg_vip')->insert([
            'title' => '永久会员',
            'cover' => '',
            'expiry' => 0,
            'price' => 999.00,
            'published' => 1,
            'deleted' => 0,
            'create_time' => $now,
            'update_time' => $now,
        ])->saveData();
    }

}
