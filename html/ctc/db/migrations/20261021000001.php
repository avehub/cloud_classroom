<?php


use Phinx\Migration\AbstractMigration;
use Phinx\Db\Adapter\MysqlAdapter;

final class V20261021000001 extends AbstractMigration
{

    public function up()
    {
        $this->addPermanentVipColumn();
        $this->createVipRedeemBatchTable();
        $this->createVipRedeemCodeTable();
    }

    public function down()
    {
        $this->table('kg_vip_redeem_code')->drop()->save();
        $this->table('kg_vip_redeem_batch')->drop()->save();

        $userTable = $this->table('kg_user');

        if ($userTable->hasColumn('vip_permanent')) {
            $userTable->removeColumn('vip_permanent')->save();
        }
    }

    protected function addPermanentVipColumn()
    {
        $table = $this->table('kg_user');

        if ($table->hasColumn('vip_permanent')) return;

        $table->addColumn('vip_permanent', 'integer', [
            'null' => false,
            'default' => '0',
            'limit' => MysqlAdapter::INT_TINY,
            'signed' => false,
            'comment' => '永久会员标识',
            'after' => 'vip_expiry_time',
        ])->update();
    }

    protected function createVipRedeemBatchTable()
    {
        $tableName = 'kg_vip_redeem_batch';

        if ($this->table($tableName)->exists()) return;

        $this->table($tableName, [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_general_ci',
            'comment' => '会员兑换码批次',
        ])
            ->addColumn('id', 'integer', [
                'null' => false,
                'limit' => MysqlAdapter::INT_REGULAR,
                'signed' => false,
                'identity' => 'enable',
            ])
            ->addColumn('name', 'string', [
                'null' => false,
                'default' => '',
                'limit' => 50,
                'comment' => '批次名称',
            ])
            ->addColumn('code_count', 'integer', [
                'null' => false,
                'default' => '0',
                'limit' => MysqlAdapter::INT_REGULAR,
                'signed' => false,
                'comment' => '兑换码数量',
            ])
            ->addColumn('expire_time', 'integer', [
                'null' => false,
                'default' => '0',
                'limit' => MysqlAdapter::INT_REGULAR,
                'signed' => false,
                'comment' => '兑换截止时间',
            ])
            ->addColumn('status', 'integer', [
                'null' => false,
                'default' => '1',
                'limit' => MysqlAdapter::INT_TINY,
                'signed' => false,
                'comment' => '状态：0停用 1启用',
            ])
            ->addColumn('remark', 'string', [
                'null' => false,
                'default' => '',
                'limit' => 255,
                'comment' => '备注',
            ])
            ->addColumn('creator_id', 'integer', [
                'null' => false,
                'default' => '0',
                'limit' => MysqlAdapter::INT_REGULAR,
                'signed' => false,
                'comment' => '创建管理员编号',
            ])
            ->addColumn('creator_name', 'string', [
                'null' => false,
                'default' => '',
                'limit' => 50,
                'comment' => '创建管理员名称',
            ])
            ->addColumn('create_time', 'integer', [
                'null' => false,
                'default' => '0',
                'limit' => MysqlAdapter::INT_REGULAR,
                'signed' => false,
            ])
            ->addColumn('update_time', 'integer', [
                'null' => false,
                'default' => '0',
                'limit' => MysqlAdapter::INT_REGULAR,
                'signed' => false,
            ])
            ->addIndex(['status'], ['name' => 'status'])
            ->create();
    }

    protected function createVipRedeemCodeTable()
    {
        $tableName = 'kg_vip_redeem_code';

        if ($this->table($tableName)->exists()) return;

        $this->table($tableName, [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_general_ci',
            'comment' => '永久会员兑换码',
        ])
            ->addColumn('id', 'integer', [
                'null' => false,
                'limit' => MysqlAdapter::INT_REGULAR,
                'signed' => false,
                'identity' => 'enable',
            ])
            ->addColumn('batch_id', 'integer', [
                'null' => false,
                'default' => '0',
                'limit' => MysqlAdapter::INT_REGULAR,
                'signed' => false,
                'comment' => '批次编号',
            ])
            ->addColumn('code', 'string', [
                'null' => false,
                'default' => '',
                'limit' => 255,
                'comment' => '加密兑换码',
            ])
            ->addColumn('code_hash', 'string', [
                'null' => false,
                'default' => '',
                'limit' => 64,
                'comment' => '兑换码哈希',
            ])
            ->addColumn('status', 'integer', [
                'null' => false,
                'default' => '0',
                'limit' => MysqlAdapter::INT_TINY,
                'signed' => false,
                'comment' => '状态：0未使用 1已使用 2已停用',
            ])
            ->addColumn('user_id', 'integer', [
                'null' => false,
                'default' => '0',
                'limit' => MysqlAdapter::INT_REGULAR,
                'signed' => false,
                'comment' => '兑换用户编号',
            ])
            ->addColumn('user_name', 'string', [
                'null' => false,
                'default' => '',
                'limit' => 50,
                'comment' => '兑换用户名称',
            ])
            ->addColumn('redeem_time', 'integer', [
                'null' => false,
                'default' => '0',
                'limit' => MysqlAdapter::INT_REGULAR,
                'signed' => false,
                'comment' => '兑换时间',
            ])
            ->addColumn('client_ip', 'string', [
                'null' => false,
                'default' => '',
                'limit' => 45,
                'comment' => '兑换IP',
            ])
            ->addColumn('create_time', 'integer', [
                'null' => false,
                'default' => '0',
                'limit' => MysqlAdapter::INT_REGULAR,
                'signed' => false,
            ])
            ->addColumn('update_time', 'integer', [
                'null' => false,
                'default' => '0',
                'limit' => MysqlAdapter::INT_REGULAR,
                'signed' => false,
            ])
            ->addIndex(['code_hash'], ['name' => 'code_hash', 'unique' => true])
            ->addIndex(['batch_id'], ['name' => 'batch_id'])
            ->addIndex(['status'], ['name' => 'status'])
            ->addIndex(['user_id'], ['name' => 'user_id'])
            ->create();
    }

}
