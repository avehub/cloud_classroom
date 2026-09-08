<?php


namespace App\Services;

use App\Library\Utils\AliyunSms as AliyunSmsUtil;
use Phalcon\Logger\Adapter\File as FileLogger;

abstract class Smser extends Service
{

    /**
     * @var array
     */
    protected $settings;

    /**
     * @var FileLogger
     */
    protected $logger;

    /**
     * 短信模板参数名（与阿里云模板中 ${变量} 保持一致，顺序与各通知类传参顺序对应）
     *
     * @var array
     */
    protected $templateParamNames = [
        'verify' => ['code', 'minutes'],
        'order_finish' => ['goods_name', 'order_sn', 'amount'],
        'refund_finish' => ['goods_name', 'refund_sn', 'amount'],
        'goods_deliver' => ['goods_name', 'order_sn', 'deliver_time'],
        'live_begin' => ['course_name', 'chapter_name', 'start_time'],
        'consult_reply' => ['replier', 'course_name'],
    ];

    public function __construct()
    {
        $this->settings = $this->getConfig()->get('sms')->toArray();

        $this->logger = $this->getLogger('sms');
    }

    /**
     * 发送短信
     *
     * @param string $phoneNumber
     * @param string $templateCode
     * @param array $params
     * @return bool
     */
    public function send($phoneNumber, $templateCode, $params)
    {
        $templateId = $this->getTemplateId($templateCode);

        if (empty($templateId)) {
            $this->logger->warning('Send Message Skipped: 模板未配置 ' . $templateCode);
            return false;
        }

        $templateParams = $this->formatTemplateParams($templateCode, $params);

        $client = new AliyunSmsUtil();

        try {

            $this->logger->debug('Send Message Request: ' . kg_json_encode([
                    'phone' => $phoneNumber,
                    'template_id' => $templateId,
                    'template_params' => $templateParams,
                ]));

            $response = $client->send(
                $this->settings['access_key_id'],
                $this->settings['access_key_secret'],
                $this->settings['region'],
                $this->settings['sign_name'],
                $templateId,
                $templateParams,
                $phoneNumber
            );

            $this->logger->debug('Send Message Response: ' . kg_json_encode($response));

            $result = strtoupper($response['Code'] ?? '') == 'OK';

            if (!$result) {
                $this->logger->error('Send Message Failed: ' . kg_json_encode([
                        'phone' => $phoneNumber,
                        'template_id' => $templateId,
                        'code' => $response['Code'] ?? '',
                        'message' => $response['Message'] ?? '',
                    ]));
            }

        } catch (\Exception $e) {

            $this->logger->error('Send Message Exception: ' . kg_json_encode([
                    'code' => $e->getCode(),
                    'message' => $e->getMessage(),
                ]));

            $result = false;
        }

        return $result;
    }

    protected function formatTemplateParams($templateCode, $params)
    {
        $names = $this->templateParamNames[$templateCode] ?? [];

        $templateParams = [];

        foreach (array_values($params) as $index => $value) {
            $name = $names[$index] ?? 'param' . ($index + 1);
            $templateParams[$name] = strval($value);
        }

        return $templateParams;
    }

    protected function getTemplateId($code)
    {
        return $this->settings['templates'][$code] ?? '';
    }

}
