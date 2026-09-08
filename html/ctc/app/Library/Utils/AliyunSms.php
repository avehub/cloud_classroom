<?php


namespace App\Library\Utils;

class AliyunSms
{

    /**
     * 发送短信（阿里云短信服务 dysmsapi 2017-05-25 版本）
     *
     * @param string $accessKeyId
     * @param string $accessKeySecret
     * @param string $regionId
     * @param string $signName
     * @param string $templateCode
     * @param array $templateParams
     * @param string $phoneNumber
     * @return array
     */
    public function send($accessKeyId, $accessKeySecret, $regionId, $signName, $templateCode, array $templateParams, $phoneNumber)
    {
        $params = [
            'AccessKeyId' => $accessKeyId,
            'Action' => 'SendSms',
            'Format' => 'JSON',
            'PhoneNumbers' => $phoneNumber,
            'RegionId' => $regionId,
            'SignName' => $signName,
            'SignatureMethod' => 'HMAC-SHA1',
            'SignatureNonce' => md5(uniqid(mt_rand(), true)),
            'SignatureVersion' => '1.0',
            'TemplateCode' => $templateCode,
            'TemplateParam' => json_encode($templateParams, JSON_UNESCAPED_UNICODE),
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'Version' => '2017-05-25',
        ];

        $params['Signature'] = $this->sign($params, $accessKeySecret);

        $url = 'https://dysmsapi.aliyuncs.com/?' . http_build_query($params);

        return $this->request($url);
    }

    /**
     * RPC 风格签名（HMAC-SHA1）
     *
     * @param array $params
     * @param string $accessKeySecret
     * @return string
     */
    protected function sign($params, $accessKeySecret)
    {
        ksort($params);

        $canonicalQuery = $this->buildCanonicalQuery($params);

        $stringToSign = 'GET&' . $this->percentEncode('/') . '&' . $this->percentEncode($canonicalQuery);

        return base64_encode(hash_hmac('sha1', $stringToSign, $accessKeySecret . '&', true));
    }

    /**
     * 构造规范化请求串（按参数名排序，percentEncode 后拼接）
     *
     * @param array $params
     * @return string
     */
    protected function buildCanonicalQuery($params)
    {
        $pairs = [];

        foreach ($params as $key => $value) {
            $pairs[] = $this->percentEncode($key) . '=' . $this->percentEncode($value);
        }

        return implode('&', $pairs);
    }

    /**
     * RFC3986 编码
     *
     * @param string $value
     * @return string
     */
    protected function percentEncode($value)
    {
        $result = urlencode($value);

        $result = str_replace(['+', '*'], ['%20', '%2A'], $result);

        return str_replace('%7E', '~', $result);
    }

    /**
     * 发起请求
     *
     * @param string $url
     * @return array
     */
    protected function request($url)
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);

        $errno = curl_errno($ch);

        curl_close($ch);

        if ($response === false || $errno) {
            return ['Code' => 'NetworkError', 'Message' => 'curl error: ' . $errno];
        }

        $result = json_decode($response, true);

        if (!is_array($result)) {
            return ['Code' => 'InvalidResponse', 'Message' => 'invalid response'];
        }

        return $result;
    }

}