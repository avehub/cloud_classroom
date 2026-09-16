<?php


use Phinx\Migration\AbstractMigration;

final class V20260916000001 extends AbstractMigration
{

    /**
     * 含 cover 字段的数据表
     *
     * @var array
     */
    protected $coverTables = [
        'kg_course',
        'kg_article',
        'kg_question',
        'kg_answer',
        'kg_topic',
        'kg_package',
        'kg_slide',
        'kg_vip',
        'kg_point_gift',
    ];

    /**
     * 需要剥离的本地存储根前缀
     *
     * @var array
     */
    protected $localPrefixes = [
        '/storage/upload',
        '/upload',
    ];

    public function up()
    {
        $this->fixCoverPaths();
    }

    /**
     * 修复 cover 字段中被重复写入的本地存储前缀
     *
     * cover 应始终存储相对于存储根目录的 key（如 /2026/09/15/xxx.png），
     * 展示 URL 由 Storage 服务在读取时拼接
     */
    protected function fixCoverPaths()
    {
        foreach ($this->coverTables as $table) {

            if (!$this->hasCoverColumn($table)) continue;

            foreach ($this->localPrefixes as $prefix) {

                $rows = $this->getQueryBuilder()
                    ->select('id', 'cover')
                    ->from($table)
                    ->where(['cover LIKE' => $prefix . '%'])
                    ->execute()->fetchAll(PDO::FETCH_ASSOC);

                foreach ($rows as $row) {

                    $cover = $this->normalizeCoverPath($row['cover']);

                    if ($cover === $row['cover']) continue;

                    $this->getQueryBuilder()
                        ->update($table)
                        ->where(['id' => $row['id']])
                        ->set('cover', $cover)
                        ->execute();
                }
            }
        }
    }

    /**
     * 判断数据表是否存在 cover 字段
     *
     * @param string $table
     * @return bool
     */
    protected function hasCoverColumn($table)
    {
        $row = $this->fetchRow(sprintf(
            'SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "%s" AND COLUMN_NAME = "cover"',
            $table
        ));

        return $row && $row['cnt'] > 0;
    }

    /**
     * 剥离 cover 中重复的存储根前缀与遗留 query 参数
     *
     * @param string $cover
     * @return string
     */
    protected function normalizeCoverPath($cover)
    {
        if (!is_string($cover) || $cover === '') {
            return $cover;
        }

        $path = explode('?', $cover)[0];

        $matched = true;

        while ($matched) {

            $matched = false;

            foreach ($this->localPrefixes as $prefix) {

                $length = strlen($prefix);

                if (strpos($path, $prefix) !== 0) continue;

                if (strlen($path) != $length && $path[$length] != '/') continue;

                $path = substr($path, $length);

                $matched = true;
            }
        }

        return $path;
    }

}
