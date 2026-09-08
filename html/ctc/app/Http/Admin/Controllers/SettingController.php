<?php


namespace App\Http\Admin\Controllers;

use App\Caches\Setting as SettingCache;
use App\Http\Admin\Services\Setting as SettingService;

/**
 * @RoutePrefix("/admin/setting")
 */
class SettingController extends Controller
{

    /**
     * @Route("/site", name="admin.setting.site")
     */
    public function siteAction()
    {
        $section = 'site';

        $settingService = new SettingService();

        if ($this->request->isPost()) {

            $data = $this->request->getPost();

            $settingService->updateSettings($section, $data);

            return $this->jsonSuccess(['msg' => '更新配置成功']);

        } else {

            $site = $settingService->getSettings($section);

            $site['url'] = $site['url'] ?: kg_site_url();

            $this->view->setVar('site', $site);
        }
    }

    /**
     * @Route("/secret", name="admin.setting.secret")
     */
    public function secretAction()
    {
        $section = 'secret';

        $settingService = new SettingService();

        if ($this->request->isPost()) {

            $data = $this->request->getPost();

            $settingService->updateSettings($section, $data);

            return $this->jsonSuccess(['msg' => '更新配置成功']);

        } else {

            $secret = $settingService->getSettings($section);

            $this->view->setVar('secret', $secret);
        }
    }

    /**
     * @Route("/storage", name="admin.setting.storage")
     */
    public function storageAction()
    {
        $section = 'cos';

        $settingService = new SettingService();

        if ($this->request->isPost()) {

            $data = $this->request->getPost();

            $driver = $data['driver'] ?? 'cos';

            $driver = in_array($driver, ['cos', 'local']) ? $driver : 'cos';

            unset($data['driver']);

            $settingService->updateStorageSettings($section, $data);

            $settingService->updateSettings('storage', ['driver' => $driver]);

            $cache = new SettingCache();
            $cache->rebuild('cos');
            $cache->rebuild('storage');

            return $this->jsonSuccess(['msg' => '更新配置成功']);

        } else {

            $cos = $settingService->getSettings($section);

            $storage = $settingService->getSettings('storage');

            $this->view->setVar('cos', $cos);

            $this->view->setVar('storage', $storage);
        }
    }

    /**
     * @Route("/test/storage", name="admin.test.storage")
     */
    public function testStorageAction()
    {
        $storage = new \App\Services\Storage();

        $fileName = 'test_' . date('YmdHis') . '.txt';
        $key = $storage->generateFileName('txt', 'test');

        $result = $storage->putString($key, 'Storage test at ' . date('Y-m-d H:i:s'));

        if ($result) {
            $url = $storage->getFileUrl($result);
            return $this->jsonSuccess([
                'msg' => '上传测试成功',
                'url' => $url,
            ]);
        }

        return $this->jsonError(['msg' => '上传测试失败']);
    }

    /**
     * @Route("/vod", name="admin.setting.vod")
     */
    public function vodAction()
    {
        $section = 'vod';

        $settingService = new SettingService();

        if ($this->request->isPost()) {

            $data = $this->request->getPost();

            $settingService->updateSettings($section, $data);

            return $this->jsonSuccess(['msg' => '更新配置成功']);

        } else {

            $vod = $settingService->getSettings($section);

            $this->view->setVar('vod', $vod);
        }
    }

    /**
     * @Route("/live", name="admin.setting.live")
     */
    public function liveAction()
    {
        $settingService = new SettingService();

        if ($this->request->isPost()) {

            $section = $this->request->getPost('section', 'string');

            $data = $this->request->getPost();

            $settingService->updateLiveSettings($section, $data);

            return $this->jsonSuccess(['msg' => '更新配置成功']);

        } else {

            $push = $settingService->getLiveSettings('live.push');
            $pull = $settingService->getLiveSettings('live.pull');
            $notify = $settingService->getLiveSettings('live.notify');

            $this->view->setVar('push', $push);
            $this->view->setVar('pull', $pull);
            $this->view->setVar('notify', $notify);
        }
    }

    /**
     * @Route("/pay", name="admin.setting.pay")
     */
    public function payAction()
    {
        $settingService = new SettingService();

        if ($this->request->isPost()) {

            $section = $this->request->getPost('section', 'string');

            $data = $this->request->getPost();

            $settingService->updateSettings($section, $data);

            return $this->jsonSuccess(['msg' => '更新配置成功']);

        } else {

            $alipay = $settingService->getAlipaySettings();
            $wxpay = $settingService->getWxpaySettings();

            $this->view->setVar('alipay', $alipay);
            $this->view->setVar('wxpay', $wxpay);
        }
    }

    /**
     * @Route("/sms", name="admin.setting.sms")
     */
    public function smsAction()
    {
        /**
         * 短信配置已统一迁移至项目根目录 .env（SMS_ 前缀配置项），此处仅作展示与测试
         */
        $config = $this->getDI()->getShared('config')->get('sms');

        $names = [
            'verify' => '用户身份验证',
            'order_finish' => '购买成功通知',
            'refund_finish' => '退款成功通知',
            'goods_deliver' => '发货成功通知',
            'live_begin' => '课程直播提醒',
            'consult_reply' => '咨询回复通知',
        ];

        $contents = [
            'verify' => '您的验证码为：${code}，该验证码5分钟内有效，请勿泄露于他人！',
            'order_finish' => '下单成功，商品名称：${goods_name}，订单序号：${order_sn}，订单金额：${amount}元',
            'refund_finish' => '退款成功，商品名称：${goods_name}，退款序号：${refund_sn}，退款金额：${amount}元',
            'goods_deliver' => '发货成功，商品名称：${goods_name}，订单序号：${order_sn}，发货时间：${deliver_time}，请注意查收。',
            'live_begin' => '直播预告，课程名称：${course_name}，章节名称：${chapter_name}，开播时间：${start_time}',
            'consult_reply' => '${replier} 回复了你的咨询，课程名称：${course_name}，请登录系统查看详情。',
        ];

        $templates = [];

        foreach ($config->get('templates')->toArray() as $code => $templateId) {
            $templates[$code] = [
                'name' => $names[$code],
                'content' => $contents[$code],
                'env' => 'SMS_TEMPLATE_' . strtoupper($code),
                'id' => $templateId,
                'enabled' => $templateId !== '' ? 1 : 0,
            ];
        }

        $this->view->setVar('sign_name', $config->get('sign_name'));
        $this->view->setVar('region', $config->get('region'));
        $this->view->setVar('templates', $templates);
    }

    /**
     * @Route("/mail", name="admin.setting.mail")
     */
    public function mailAction()
    {
        $section = 'mail';

        $settingService = new SettingService();

        if ($this->request->isPost()) {

            $data = $this->request->getPost();

            $settingService->updateSettings($section, $data);

            return $this->jsonSuccess(['msg' => '更新配置成功']);

        } else {

            $mail = $settingService->getSettings($section);

            $this->view->setVar('mail', $mail);
        }
    }

    /**
     * @Route("/point", name="admin.setting.point")
     */
    public function pointAction()
    {
        $section = 'point';

        $settingService = new SettingService();

        if ($this->request->isPost()) {

            $data = $this->request->getPost();

            $settingService->updateSettings($section, $data);

            return $this->jsonSuccess(['msg' => '更新配置成功']);

        } else {

            $point = $settingService->getSettings($section);

            $this->view->setVar('point', $point);
        }
    }

    /**
     * @Route("/vip", name="admin.setting.vip")
     */
    public function vipAction()
    {
        $settingService = new SettingService();

        if ($this->request->isPost()) {

            $data = $this->request->getPost('vip');

            $settingService->updateVipSettings($data);

            return $this->jsonSuccess(['msg' => '更新配置成功']);

        } else {

            $items = $settingService->getVipSettings();

            $this->view->setVar('items', $items);
        }
    }

    /**
     * @Route("/oauth", name="admin.setting.oauth")
     */
    public function oauthAction()
    {
        $settingService = new SettingService();

        if ($this->request->isPost()) {

            $section = $this->request->getPost('section', 'string');

            $data = $this->request->getPost();

            $settingService->updateSettings($section, $data);

            return $this->jsonSuccess(['msg' => '更新配置成功']);

        } else {

            $qqAuth = $settingService->getQQAuthSettings();
            $weixinAuth = $settingService->getWeixinAuthSettings();
            $weiboAuth = $settingService->getWeiboAuthSettings();
            $localAuth = $settingService->getLocalAuthSettings();

            $this->view->setVar('qq_auth', $qqAuth);
            $this->view->setVar('weixin_auth', $weixinAuth);
            $this->view->setVar('weibo_auth', $weiboAuth);
            $this->view->setVar('local_auth', $localAuth);
        }
    }

    /**
     * @Route("/wechat/oa", name="admin.setting.wechat_oa")
     */
    public function wechatOaAction()
    {
        $settingService = new SettingService();

        if ($this->request->isPost()) {

            $section = $this->request->getPost('section', 'string');

            $data = $this->request->getPost();

            $settingService->updateWeChatOASettings($section, $data);

            return $this->jsonSuccess(['msg' => '更新配置成功']);

        } else {

            $oa = $settingService->getWeChatOASettings();

            $this->view->pick('setting/wechat_oa');
            $this->view->setVar('oa', $oa);
        }
    }

    /**
     * @Route("/dingtalk/robot", name="admin.setting.dingtalk_robot")
     */
    public function dingtalkRobotAction()
    {
        $section = 'dingtalk.robot';

        $settingService = new SettingService();

        if ($this->request->isPost()) {

            $data = $this->request->getPost();

            $settingService->updateSettings($section, $data);

            return $this->jsonSuccess(['msg' => '更新配置成功']);

        } else {

            $robot = $settingService->getSettings($section);

            $this->view->pick('setting/dingtalk_robot');
            $this->view->setVar('robot', $robot);
        }
    }

    /**
     * @Route("/contact", name="admin.setting.contact")
     */
    public function contactAction()
    {
        $section = 'contact';

        $settingService = new SettingService();

        if ($this->request->isPost()) {

            $data = $this->request->getPost();

            $settingService->updateSettings($section, $data);

            return $this->jsonSuccess(['msg' => '更新配置成功']);

        } else {

            $contact = $settingService->getSettings($section);

            $this->view->setVar('contact', $contact);
        }
    }

}
