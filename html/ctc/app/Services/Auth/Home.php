<?php


namespace App\Services\Auth;

use App\Models\User as UserModel;
use App\Models\UserSession as UserSessionModel;
use App\Repos\UserSession as UserSessionRepo;
use App\Services\Auth as AuthService;
use App\Traits\Client as ClientTrait;

class Home extends AuthService
{

    use ClientTrait;

    public function saveAuthInfo(UserModel $user)
    {
        $sessionId = $this->session->getId();

        $lifetime = $this->getSessionLifetime();

        $this->createUserSession($user->id, $sessionId, $lifetime);

        $loginToken = $this->generateLoginToken($user->id);

        $cache = $this->getCache();

        $cache->save($this->getLoginTokenCacheKey($user->id), $loginToken, $lifetime);

        $authKey = $this->getAuthKey();

        $authInfo = [
            'id' => $user->id,
            'name' => $user->name,
            'token' => $loginToken,
        ];

        $this->session->set($authKey, $authInfo);

        return $authInfo;
    }

    public function clearAuthInfo()
    {
        $authKey = $this->getAuthKey();

        $this->session->remove($authKey);
    }

    public function getAuthInfo()
    {
        $authKey = $this->getAuthKey();

        $authInfo = $this->session->get($authKey);

        if (!$authInfo) return null;

        $cache = $this->getCache();

        $loginToken = $cache->get($this->getLoginTokenCacheKey($authInfo['id']));

        /**
         * 历史会话或缓存失效，静默退出登录
         */
        if (empty($authInfo['token']) || empty($loginToken)) {
            $this->clearAuthInfo();
            return null;
        }

        /**
         * 令牌不一致，说明账号已在其他设备登录
         */
        if ($authInfo['token'] != $loginToken) {
            $this->clearAuthInfo();
            $this->session->set($this->getKickedKey(), 1);
            return null;
        }

        return $authInfo;
    }

    public function getKickedNotice()
    {
        $key = $this->getKickedKey();

        $kicked = (bool)$this->session->get($key);

        if ($kicked) {
            $this->session->remove($key);
        }

        return $kicked;
    }

    public function logoutClients($userId)
    {
        $cache = $this->getCache();

        $repo = new UserSessionRepo();

        $records = $repo->findUserActiveSessions($userId);

        if ($records->count() == 0) return;

        foreach ($records as $record) {
            $key = $this->getSessionCacheKey($record->session_id);
            $cache->delete($key);
        }
    }

    protected function createUserSession($userId, $sessionId, $lifetime)
    {
        $userSession = new UserSessionModel();

        $userSession->user_id = $userId;
        $userSession->session_id = $sessionId;
        $userSession->client_type = $this->getClientType();
        $userSession->client_ip = $this->getClientIp();
        $userSession->expire_time = time() + $lifetime;

        $userSession->create();
    }

    protected function getSessionLifetime()
    {
        $config = $this->getConfig();

        return $config->path('session.lifetime') ?: 24 * 3600;
    }

    protected function getSessionCacheKey($sessionId)
    {
        return "_PHCR_SESSION_:{$sessionId}";
    }

    protected function getAuthKey()
    {
        return 'home_auth_info';
    }

    protected function getKickedKey()
    {
        return 'home_kicked_notice';
    }

    protected function generateLoginToken($userId)
    {
        return md5(uniqid() . time() . $userId);
    }

    protected function getLoginTokenCacheKey($userId)
    {
        return "user_login_token:{$userId}";
    }

}
