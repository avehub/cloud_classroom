<?php


namespace App\Http\Home\Services;

use App\Exceptions\BadRequest as BadRequestException;
use App\Models\Connect as ConnectModel;
use App\Models\User as UserModel;
use App\Repos\Connect as ConnectRepo;
use App\Repos\User as UserRepo;
use App\Services\Auth\Home as AuthService;
use App\Services\Logic\Account\OAuthRegister as OAuthRegisterService;
use App\Services\Logic\Account\WeChatAvatar as WeChatAvatarService;
use App\Services\Logic\Notice\External\AccountLogin as AccountLoginNotice;
use App\Services\OAuth\QQ as QQAuth;
use App\Services\OAuth\WeiBo as WeiBoAuth;
use App\Services\OAuth\WeiXin as WeiXinAuth;
use App\Validators\Account as AccountValidator;

class Connect extends Service
{

    public function bindLogin()
    {
        $post = $this->request->getPost();

        $openUser = $this->getBindOpenUser();

        $validator = new AccountValidator();

        $user = $validator->checkUserLogin($post['account'], $post['password']);

        $validator->checkIfAllowLogin($user);

        $this->handleConnectRelation($user, $openUser);

        $this->handleLoginNotice($user);

        $auth = $this->getAppAuth();

        $auth->saveAuthInfo($user);

        $this->clearBindSession();

        return $user;
    }

    public function bindUser(array $openUser)
    {
        $user = $this->getLoginUser();

        $this->handleConnectRelation($user, $openUser);

        return $user;
    }

    public function authConnectLogin(ConnectModel $connect, array $openUser = [])
    {
        $userRepo = new UserRepo();

        $user = $userRepo->findById($connect->user_id);

        if (!$user || $user->deleted == 1) {
            throw new BadRequestException('user.not_found');
        }

        $validator = new AccountValidator();

        $validator->checkIfAllowLogin($user);

        if ($connect->deleted == 1) {
            $connect->deleted = 0;
        }

        if (!empty($openUser)) {
            $openUser = OAuthRegisterService::normalizeOpenUser($openUser);

            $connect->open_name = $openUser['name'];
            $connect->open_avatar = $openUser['avatar'];

            if (empty($connect->union_id) && !empty($openUser['unionid'])) {
                $connect->union_id = $openUser['unionid'];
            }
        }

        $connect->update_time = time();

        $connect->update();

        $avatarService = new WeChatAvatarService();

        $avatarService->sync($user, $connect->provider, $connect->open_avatar);

        $this->handleLoginNotice($user);

        $auth = $this->getAppAuth();

        $auth->saveAuthInfo($user);

        return $user;
    }

    /**
     * @param array $openUser
     * @return array [UserModel, bool]
     */
    public function authConnectRegister(array $openUser): array
    {
        $registerService = new OAuthRegisterService();

        list($user, $isNewUser) = $registerService->handle($openUser);

        $validator = new AccountValidator();

        $validator->checkIfAllowLogin($user);

        $auth = $this->getAppAuth();

        $auth->saveAuthInfo($user);

        if ($isNewUser) {
            $this->eventsManager->fire('Account:afterRegister', $this, $user);
            $this->handleLoginNotice($user);
        } else {
            $this->eventsManager->fire('Account:afterLogin', $this, $user);
        }

        return [$user, $isNewUser];
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

    public function setBindSession(array $openUser)
    {
        $this->session->set('oauth_bind_open_user', $openUser);
        $this->session->set('oauth_bind_provider', $openUser['provider']);
    }

    public function getBindOpenUser(): array
    {
        $openUser = $this->session->get('oauth_bind_open_user');
        $provider = (int)$this->session->get('oauth_bind_provider');

        if (empty($openUser) || empty($provider) || (int)$openUser['provider'] !== $provider) {
            throw new BadRequestException('account.bind_session_expired');
        }

        return $openUser;
    }

    public function clearBindSession()
    {
        $this->session->remove('oauth_bind_open_user');
        $this->session->remove('oauth_bind_provider');
    }

    public function getConnectAuth($provider)
    {
        $auth = null;

        switch ($provider) {
            case ConnectModel::PROVIDER_QQ:
                $auth = $this->getQQAuth();
                break;
            case ConnectModel::PROVIDER_WEIXIN:
                $auth = $this->getWeiXinAuth();
                break;
            case ConnectModel::PROVIDER_WEIBO:
                $auth = $this->getWeiBoAuth();
                break;
        }

        if (!$auth) {
            throw new \Exception('Invalid OAuth Provider');
        }

        return $auth;
    }

    protected function getQQAuth()
    {
        $settings = $this->getSettings('oauth.qq');

        return new QQAuth(
            $settings['client_id'],
            $settings['client_secret'],
            $settings['redirect_uri']
        );
    }

    protected function getWeiXinAuth()
    {
        $settings = $this->getSettings('oauth.weixin');

        return new WeiXinAuth(
            $settings['client_id'],
            $settings['client_secret'],
            $settings['redirect_uri']
        );
    }

    protected function getWeiBoAuth()
    {
        $settings = $this->getSettings('oauth.weibo');

        return new WeiBoAuth(
            $settings['client_id'],
            $settings['client_secret'],
            $settings['redirect_uri']
        );
    }

    protected function getAppAuth()
    {
        /**
         * @var $auth AuthService
         */
        $auth = $this->getDI()->get('auth');

        return $auth;
    }

    protected function handleConnectRelation(UserModel $user, array $openUser)
    {
        $openUser = OAuthRegisterService::normalizeOpenUser($openUser);

        $connectRepo = new ConnectRepo();

        $connect = $connectRepo->findByOpenId($openUser['id'], $openUser['provider']);

        if ($connect) {

            if ($connect->user_id != $user->id) {
                throw new BadRequestException('account.oauth_already_bound');
            }

            $connect->open_name = $openUser['name'];
            $connect->open_avatar = $openUser['avatar'];

            if (empty($connect->union_id) && !empty($openUser['unionid'])) {
                $connect->union_id = $openUser['unionid'];
            }

            if ($connect->deleted == 1) {
                $connect->deleted = 0;
            }

            $connect->update();

        } else {

            $connect = new ConnectModel();

            $connect->user_id = $user->id;
            $connect->union_id = $openUser['unionid'];
            $connect->open_id = $openUser['id'];
            $connect->open_name = $openUser['name'];
            $connect->open_avatar = $openUser['avatar'];
            $connect->provider = $openUser['provider'];

            $connect->create();
        }

        $avatarService = new WeChatAvatarService();

        $avatarService->sync($user, $openUser['provider'], $openUser['avatar']);
    }

    protected function handleLoginNotice(UserModel $user)
    {
        $notice = new AccountLoginNotice();

        $notice->createTask($user);
    }

}
