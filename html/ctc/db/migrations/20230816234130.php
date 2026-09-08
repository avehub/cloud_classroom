<?php


use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

final class V20230816234130 extends AbstractMigration
{

    public function up()
    {
        $this->alterReviewLikeTable();
    }

    protected function alterReviewLikeTable()
    {
        $table = $this->table('kg_review_like');

        if (!$table->hasColumn('deleted')) {
            $table->addColumn('deleted', 'integer', [
                'null' => false,
                'default' => '0',
                'limit' => MysqlAdapter::INT_REGULAR,
                'signed' => false,
                'comment' => '删除标识',
                'after' => 'user_id',
            ]);
        }

        $table->save();
    }

}
