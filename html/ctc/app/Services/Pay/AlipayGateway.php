<?php


namespace App\Services\Pay;

use App\Services\Service;
use Yansongda\Pay\Gateways\Alipay;
use Yansongda\Pay\Pay;

class AlipayGateway extends Service
{

    /**
     * @var array
     */
    protected $settings;

    public function __construct($options = [])
    {
        $defaults = $this->getSettings('pay.alipay');

        $this->settings = array_merge($defaults, $options);
    }

    public function setReturnUrl($returnUrl)
    {
        $this->settings['return_url'] = $returnUrl;
    }

    public function setNotifyUrl($notifyUrl)
    {
        $this->settings['notify_url'] = $notifyUrl;
    }

    /**
     * @return Alipay
     */
    public function getInstance()
    {
        $config = $this->getConfig();

        $level = $config->get('env') == ENV_DEV ? 'debug' : 'info';

        $options = [
            'app_id' => $this->settings['app_id'] ?? '',
            'private_key' => $this->settings['private_key'] ?? '',
            'notify_url' => $this->settings['notify_url'] ?? '',
            'return_url' => $this->settings['return_url'] ?? '',
            'log' => [
                'file' => log_path('alipay.log'),
                'level' => $level,
                'type' => 'daily',
                'max_file' => 30,
            ],
        ];

        $appCertPath = config_path('alipay/appCertPublicKey.crt');
        $rootCertPath = config_path('alipay/alipayRootCert.crt');
        $aliCertPath = config_path('alipay/alipayCertPublicKey.crt');

        // 优先支持公钥证书模式；若证书文件不存在，则退回普通公钥字符串模式
        if (is_file($appCertPath) && is_file($rootCertPath) && is_file($aliCertPath)) {
            $options['app_cert_public_key'] = $appCertPath;
            $options['alipay_root_cert'] = $rootCertPath;
            $options['ali_public_key'] = $aliCertPath;
        } elseif (!empty($this->settings['ali_public_key'])) {
            $options['ali_public_key'] = $this->settings['ali_public_key'];
        }

        if ($config->get('env') == ENV_DEV) {
            $options['mode'] = 'dev';
        }

        return Pay::alipay($options);
    }

}
