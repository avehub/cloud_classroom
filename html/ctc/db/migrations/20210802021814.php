<?php


use Phinx\Migration\AbstractMigration;

final class V20210802021814 extends AbstractMigration
{

    public function up()
    {
        $this->alterPageTable();
    }

    protected function alterPageTable()
    {
        $table = $this->table('kg_page');

        if (!$table->hasColumn('alias')) {
            $table->addColumn('alias', 'string', [
                'null' => false,
                'default' => '',
                'limit' => 50,
                'collation' => 'utf8mb4_general_ci',
                'encoding' => 'utf8mb4',
                'comment' => '别名',
                'after' => 'title',
            ]);
        }

        $table->save();
    }

}
