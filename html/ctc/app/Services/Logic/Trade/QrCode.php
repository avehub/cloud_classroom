<?php


namespace App\Services\Logic\Trade;

use App\Models\Trade as TradeModel;
use App\Services\Logic\Service as LogicService;
use App\Services\Pay\Alipay as AlipayService;
use App\Services\Pay\Wxpay as WxpayService;

class QrCode extends LogicService
{

    public function handle(TradeModel $trade): string
    {
        $qrCode = null;

        if ($trade->channel == TradeModel::CHANNEL_ALIPAY) {
            $qrCode = $this->getAlipayQrCode($trade);
        } elseif ($trade->channel == TradeModel::CHANNEL_WXPAY) {
            $qrCode = $this->getWxpayQrCode($trade);
        }

        return $qrCode;
    }

    protected function getAlipayQrCode(TradeModel $trade): string
    {
        $qrCode = null;

        $service = new AlipayService();

        $text = $service->scan($trade);

        if ($text) {
            $qrCode = $this->url->get(
                ['for' => 'home.qrcode'],
                ['text' => urlencode($text)]
            );
        }

        return $qrCode;
    }

    protected function getWxpayQrCode(TradeModel $trade): string
    {
        $qrCode = null;

        $service = new WxpayService();

        $text = $service->scan($trade);

        if ($text) {
            $qrCode = $this->url->get(
                ['for' => 'home.qrcode'],
                ['text' => urlencode($text)]
            );
        }

        return $qrCode;
    }

}
