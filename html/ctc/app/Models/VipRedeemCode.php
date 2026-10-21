<?php


namespace App\Models;

class VipRedeemCode extends Model
{

    const STATUS_UNUSED = 0;
    const STATUS_USED = 1;
    const STATUS_DISABLED = 2;

    public $id = 0;

    public $batch_id = 0;

    public $code = '';

    public $code_hash = '';

    public $status = self::STATUS_UNUSED;

    public $user_id = 0;

    public $user_name = '';

    public $redeem_time = 0;

    public $client_ip = '';

    public $create_time = 0;

    public $update_time = 0;

    public function getSource(): string
    {
        return 'kg_vip_redeem_code';
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
            self::STATUS_UNUSED => '未使用',
            self::STATUS_USED => '已使用',
            self::STATUS_DISABLED => '已停用',
        ];
    }

}
