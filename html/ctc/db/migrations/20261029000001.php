<?php


use Phinx\Migration\AbstractMigration;

final class V20261029000001 extends AbstractMigration
{

    public function up()
    {
        /**
         * 修复历史分类脏数据：部分分类因创建后主键未回填，
         * path 为空且 level=0，课程详情读取父级分类时会触发异常。
         */
        for ($i = 0; $i < 5; $i++) {

            $affected = $this->repairTopLevelCategories();

            $affected += $this->repairChildCategories();

            if ($affected == 0) {
                break;
            }
        }
    }

    public function down()
    {
        /**
         * 数据修复无需回退；回退时保留已修复的层级与路径。
         */
    }

    protected function repairTopLevelCategories()
    {
        return $this->execute(
            "UPDATE kg_category
             SET level = 1, path = CONCAT(',', id, ',')
             WHERE parent_id = 0 AND (level < 1 OR path = '')"
        );
    }

    protected function repairChildCategories()
    {
        return $this->execute(
            "UPDATE kg_category c
             INNER JOIN kg_category p ON c.parent_id = p.id
             SET c.level = p.level + 1, c.path = CONCAT(p.path, c.id, ',')
             WHERE c.parent_id > 0
               AND p.level >= 1
               AND p.path <> ''
               AND (c.level < 1 OR c.path = '' OR c.level <> p.level + 1)"
        );
    }

}
