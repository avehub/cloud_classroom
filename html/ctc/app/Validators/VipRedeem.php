<?php


namespace App\Validators;

use App\Exceptions\BadRequest as BadRequestException;

class VipRedeem extends Validator
{

    public function checkCode($code)
    {
        $value = $this->filter->sanitize($code, ['trim', 'string']);
        $value = strtoupper(preg_replace('/[\s-]+/', '', $value));

        if (!preg_match('/^[A-Z0-9]{20}$/', $value)) {
            throw new BadRequestException('vip_redeem.invalid_code');
        }

        return $value;
    }

    public function checkBatchName($name)
    {
        $value = $this->filter->sanitize($name, ['trim', 'string']);
        $length = kg_strlen($value);

        if ($length < 2 || $length > 50) {
            throw new BadRequestException('vip_redeem.invalid_batch_name');
        }

        return $value;
    }

    public function checkCodeCount($count)
    {
        $value = $this->filter->sanitize($count, ['trim', 'int']);

        if ($value < 1 || $value > 1000) {
            throw new BadRequestException('vip_redeem.invalid_code_count');
        }

        return $value;
    }

    public function checkExpireTime($expireTime)
    {
        $value = $this->filter->sanitize($expireTime, ['trim', 'string']);

        if (empty($value)) return 0;

        $timestamp = strtotime($value);

        if ($timestamp === false || $timestamp <= time()) {
            throw new BadRequestException('vip_redeem.invalid_expire_time');
        }

        return $timestamp;
    }

    public function checkRemark($remark)
    {
        $value = $this->filter->sanitize($remark, ['trim', 'string']);

        if (kg_strlen($value) > 255) {
            throw new BadRequestException('vip_redeem.invalid_remark');
        }

        return $value;
    }

    public function checkStatus($status)
    {
        $value = $this->filter->sanitize($status, ['trim', 'int']);

        if (!in_array($value, [0, 1])) {
            throw new BadRequestException('vip_redeem.invalid_status');
        }

        return $value;
    }

}
