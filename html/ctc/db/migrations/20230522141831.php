<?php


use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

final class V20230522141831 extends AbstractMigration
{

    public function up()
    {
        $this->alterReviewLikeTable();
    }

    protected function alterReviewLikeTable()
    {
        $table = $this->table('kg_review_like');

        if (!$table->hasColumn('update_time')) {
            $table->addColumn('update_time', 'integer', [
                'null' => false,
                'default' => '0',
                'limit' => MysqlAdapter::INT_REGULAR,
                'signed' => false,
                'comment' => '更新时间',
                'after' => 'create_time',
            ]);
        }

        $table->save();
    }

}
