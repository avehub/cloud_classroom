<?php


namespace App\Library\Utils;

use App\Exceptions\BadRequest as BadRequestException;
use Phalcon\Di;

class VipRedeemCode
{

    const FORMAT_PREFIX = 'v2:';

    public static function encrypt($code)
    {
        $iv = random_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($code, 'aes-256-cbc', self::getKey(), OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            throw new BadRequestException('vip_redeem.code_encrypt_failed');
        }

        return self::FORMAT_PREFIX . base64_encode($iv . $encrypted);
    }

    public static function decrypt($value)
    {
        if (strpos($value, self::FORMAT_PREFIX) === 0) {
            $payload = base64_decode(substr($value, strlen(self::FORMAT_PREFIX)), true);

            if ($payload !== false) {
                $ivLength = openssl_cipher_iv_length('aes-256-cbc');
                $iv = substr($payload, 0, $ivLength);
                $encrypted = substr($payload, $ivLength);
                $code = openssl_decrypt($encrypted, 'aes-256-cbc', self::getKey(), OPENSSL_RAW_DATA, $iv);
            } else {
                $code = false;
            }
        } else {
            if (class_exists('Phalcon\\Encryption\\Crypt')) {
                $crypt = new \Phalcon\Encryption\Crypt();
            } else {
                $crypt = new \Phalcon\Crypt();
            }

            $crypt->setKey(self::getKey());
            $code = $crypt->decryptBase64($value, null, true);
        }

        if (!is_string($code) || !preg_match('/^[A-Z2-9]{20}$/', $code)) {
            throw new BadRequestException('vip_redeem.code_decrypt_failed');
        }

        return $code;
    }

    protected static function getKey()
    {
        $config = Di::getDefault()->getShared('config');

        return hash('sha256', $config->get('key'), true);
    }

}
