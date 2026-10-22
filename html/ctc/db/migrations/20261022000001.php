<?php


use Phinx\Migration\AbstractMigration;

final class V20261022000001 extends AbstractMigration
{

    public function up()
    {
        $times = $this->fetchAll(
            'SELECT DISTINCT create_time FROM kg_vip_redeem_code WHERE batch_id = 0'
        );

        foreach ($times as $time) {
            $batches = $this->fetchAll(
                'SELECT id FROM kg_vip_redeem_batch WHERE create_time = ' . (int)$time['create_time']
            );

            if (count($batches) != 1) continue;

            $this->getQueryBuilder()
                ->update('kg_vip_redeem_code')
                ->set('batch_id', $batches[0]['id'])
                ->where([
                    'batch_id' => 0,
                    'create_time' => $time['create_time'],
                ])
                ->execute();
        }
    }

}
