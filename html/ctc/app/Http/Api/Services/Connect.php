<?php


namespace App\Http\Api\Services;

use App\Exceptions\BadRequest as BadRequestException;
use App\Models\Connect as ConnectModel;
use App\Repos\Connect as ConnectRepo;
use App\Repos\User as UserRepo;
use App\Services\Auth\Api as ApiAuthService;
use App\Services\Logic\Account\OAuthRegister as OAuthRegisterService;
use App\Services\Logic\Account\Status as StatusService;
use App\Services\Logic\Account\WeChatAvatar as WeChatAvatarService;
use App\Services\Logic\Notice\External\AccountLogin as AccountLoginNoticeService;
use App\Services\OAuth\QQ as QQAuth;
use App\Services\OAuth\WeChat as WeChatAuth;
use App\Services\OAuth\WeiBo as WeiBoAuth;
use App\Validators\Account as AccountValidator;
use Exception;

class Connect extends Service
{

    public function getWechatInfo()
    {
        return [
            'auth_url' => kg_full_url(['for' => 'api.oauth.wechat']),
            'auto_login' => 1,
        ];
    }

    public function getWechatRedirectCache()
    {
        return $this->session->get('wechat_redirect');
    }

    public function setWechatRedirectCache($redirect)
    {
        $this->session->set('wechat_redirect', $redirect);
    }

    /**
     * 规范化微信回跳地址，防止把登录 token 重定向到外部域名。
     *
     * @param string $redirect
     * @param string|null $currentHost
     * @return string
     */
    public function normalizeWechatRedirect($redirect, $currentHost = null)
    {
        $redirect = trim((string)$redirect);

        if ($redirect === '') return '';

        if (strpos($redirect, '//') === 0) return '';

        $parts = parse_url($redirect);

        if ($parts === false) return '';

        if (empty($parts['scheme'])) {
            return empty($parts['host']) ? $redirect : '';
        }

        $scheme = strtolower($parts['scheme']);

        if (!in_array($scheme, ['http', 'https'], true)) return '';

        if (empty($parts['host'])) return '';

        $host = $this->normalizeHost($parts['host']);

        if (!$host) return '';

        if (!in_array($host, $this->getAllowedRedirectHosts($currentHost), true)) {
            return '';
        }

        return $redirect;
    }

    /**
     * @param string|null $currentHost
     * @return array
     */
    protected function getAllowedRedirectHosts($currentHost = null)
    {
        $hosts = [];

        if ($currentHost) $hosts[] = $this->normalizeHost($currentHost);

        $siteHost = parse_url((string)getenv('SITE_URL'), PHP_URL_HOST);

        if ($siteHost) $hosts[] = $this->normalizeHost($siteHost);

        $siteDomain = getenv('SITE_DOMAIN') ?: '';

        if ($siteDomain) $hosts[] = $this->normalizeHost($siteDomain);

        $extra = getenv('OAUTH_REDIRECT_ALLOWED_HOSTS') ?: '';

        foreach (preg_split('/\s*,\s*/', $extra) as $item) {
            if ($item) $hosts[] = $this->normalizeHost($item);
        }

        return array_values(array_unique(array_filter($hosts)));
    }

    /**
     * @param string $host
     * @return string
     */
    protected function normalizeHost($host)
    {
        $host = strtolower(trim((string)$host));

        $host = trim($host, '[]');

        return preg_replace('/:\d+$/', '', $host);
    }

    public function removeWechatRedirectCache()
    {
        $this->session->remove('wechat_redirect');
    }

    public function handleCallback($provider)
    {
        $code = $this->request->getQuery('code');
        $state = $this->request->getQuery('state');

        $openUser = $this->getOpenUserInfo($code, $state, $provider);
        $openUser = OAuthRegisterService::normalizeOpenUser($openUser);
        $relation = $this->getConnectRelation($openUser['id'], $provider);

        $auth = new ApiAuthService();

        if ($relation) {

            $userRepo = new UserRepo();

            $user = $userRepo->findById($relation->user_id);

            if (!$user || $user->deleted == 1) {
                throw new BadRequestException('user.not_found');
            }

            $validator = new AccountValidator();

            $validator->checkIfAllowLogin($user);

            if ($relation->deleted == 1) {
                $relation->deleted = 0;
            }

            $relation->open_name = $openUser['name'];
            $relation->open_avatar = $openUser['avatar'];

            if (empty($relation->union_id) && !empty($openUser['unionid'])) {
                $relation->union_id = $openUser['unionid'];
            }

            $relation->update_time = time();

            $relation->update();

            $avatarService = new WeChatAvatarService();

            $avatarService->sync($user, $relation->provider, $openUser['avatar']);

            $token = $auth->saveAuthInfo($user);

            $this->eventsManager->fire('Account:afterLogin', $this, $user);

            $isNewUser = false;

        } else {

            $registerService = new OAuthRegisterService();

            list($user, $isNewUser) = $registerService->handle($openUser);

            $validator = new AccountValidator();

            $validator->checkIfAllowLogin($user);

            $token = $auth->saveAuthInfo($user);

            if ($isNewUser) {
                $this->eventsManager->fire('Account:afterRegister', $this, $user);
                $this->handleLoginNotice($user);
            } else {
                $this->eventsManager->fire('Account:afterLogin', $this, $user);
            }
        }

        $statusService = new StatusService();

        $status = $statusService->handle($user);

        return [
            'openid' => $openUser['id'],
            'token' => $token,
            'is_new_user' => (int)$isNewUser,
            'need_set_password' => (int)$status['need_set_password'],
            'need_bind_phone' => (int)$status['need_bind_phone'],
        ];
    }

    public function getAuthorizeUrl($provider)
    {
        $auth = $this->getConnectAuth($provider);

        return $auth->getAuthorizeUrl();
    }

    public function getOpenUserInfo($code, $state, $provider)
    {
        $auth = $this->getConnectAuth($provider);

        $auth->checkState($state);

        $token = $auth->getAccessToken($code);

        $openId = $auth->getOpenId($token);

        return $auth->getUserInfo($token, $openId);
    }

    public function getConnectRelation($openId, $provider)
    {
        $connectRepo = new ConnectRepo();

        return $connectRepo->findByOpenId($openId, $provider);
    }

    public function getConnectAuth($provider)
    {
        $auth = null;

        switch ($provider) {
            case ConnectModel::PROVIDER_QQ:
                $auth = $this->getQQAuth();
                break;
            case ConnectModel::PROVIDER_WEIBO:
                $auth = $this->getWeiBoAuth();
                break;
            case ConnectModel::PROVIDER_WECHAT_OA:
                $auth = $this->getWeChatAuth();
                break;
        }

        if (!$auth) {
            throw new Exception('Invalid OAuth Provider');
        }

        return $auth;
    }

    protected function getQQAuth()
    {
        $settings = $this->getSettings('oauth.qq');

        $settings['redirect_uri'] = kg_full_url(['for' => 'api.oauth.qq_callback']);

        return new QQAuth(
            $settings['client_id'],
            $settings['client_secret'],
            $settings['redirect_uri']
        );
    }

    protected function getWeChatAuth()
    {
        /**
         * 使用的是微信公众号网页授权登录功能
         */
        $settings = $this->getSettings('wechat.oa');

        $settings['redirect_uri'] = kg_full_url(['for' => 'api.oauth.wechat_callback']);

        return new WeChatAuth(
            $settings['app_id'],
            $settings['app_secret'],
            $settings['redirect_uri']
        );
    }

    protected function getWeiBoAuth()
    {
        $settings = $this->getSettings('oauth.weibo');

        $settings['redirect_uri'] = kg_full_url(['for' => 'api.oauth.weibo_callback']);

        return new WeiBoAuth(
            $settings['client_id'],
            $settings['client_secret'],
            $settings['redirect_uri']
        );
    }

    protected function handleLoginNotice($user)
    {
        $service = new AccountLoginNoticeService();

        $service->createTask($user);
    }

}
