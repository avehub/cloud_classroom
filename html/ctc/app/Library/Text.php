<?php


namespace App\Library;

/**
 * Phalcon\Text 在 Phalcon 5 中已移除，此类提供同签名兼容实现
 */
class Text
{

    const RANDOM_ALNUM = 0;

    const RANDOM_ALPHA = 1;

    const RANDOM_NUMERIC = 2;

    const RANDOM_NOZERO = 3;

    const RANDOM_DISTINCT = 5;

    public static function startsWith($haystack, $needle)
    {
        return str_starts_with((string)$haystack, (string)$needle);
    }

    public static function endsWith($haystack, $needle)
    {
        return str_ends_with((string)$haystack, (string)$needle);
    }

    public static function camelize($str, $delimiter = null)
    {
        $delimiter = $delimiter ?: '_-';

        $str = str_replace(['-', '_'], ' ', (string)$str);

        $str = str_replace(' ', '', ucwords($str));

        return lcfirst($str);
    }

    public static function uncamelize($str, $delimiter = null)
    {
        $delimiter = $delimiter ?: '_';

        return strtolower(preg_replace('/(?<=[a-z])([A-Z])/', $delimiter . '$1', (string)$str));
    }

    public static function random($type = self::RANDOM_ALNUM, $length = 8)
    {
        switch ($type) {
            case self::RANDOM_ALPHA:
                $pool = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
                break;
            case self::RANDOM_NUMERIC:
                $pool = '0123456789';
                break;
            case self::RANDOM_NOZERO:
                $pool = '123456789';
                break;
            case self::RANDOM_DISTINCT:
                $pool = '2345679ACDEFHJKLMNPRSTUVWXYZ';
                break;
            default:
                $pool = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
                break;
        }

        $result = '';
        $maxIndex = strlen($pool) - 1;

        for ($i = 0; $i < $length; $i++) {
            $result .= $pool[random_int(0, $maxIndex)];
        }

        return $result;
    }

}
