<?php


trait PageTrait
{

    protected function insertPages(array $rows)
    {
        foreach ($rows as $key => $row) {
            $exists = $this->pageExists($row['alias']);
            if ($exists) unset($rows[$key]);
        }

        if (count($rows) == 0) return;

        $this->table('kg_page')->insert($rows)->save();
    }

    protected function pageExists($alias)
    {
        $row = $this->getQueryBuilder()
            ->select('*')
            ->from('kg_page')
            ->where(['alias' => $alias])
            ->execute()->fetch();

        return (bool)$row;
    }

}