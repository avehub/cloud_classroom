<?php


namespace App\Models;

class VipRedeemBatch extends Model
{

    const STATUS_DISABLED = 0;
    const STATUS_ENABLED = 1;

    public $id = 0;

    public $name = '';

    public $code_count = 0;

    public $expire_time = 0;

    public $status = self::STATUS_ENABLED;

    public $remark = '';

    public $creator_id = 0;

    public $creator_name = '';

    public $create_time = 0;

    public $update_time = 0;

    public function getSource(): string
    {
        return 'kg_vip_redeem_batch';
    }

    public function beforeCreate()
    {
        $this->create_time = time();
    }

    public function beforeUpdate()
    {
        $this->update_time = time();
    }

    public static function statusTypes()
    {
        return [
            self::STATUS_DISABLED => '停用',
            self::STATUS_ENABLED => '启用',
        ];
    }

}
