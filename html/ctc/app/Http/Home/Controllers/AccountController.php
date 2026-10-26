<?php


namespace App\Http\Home\Controllers;

use App\Http\Home\Services\Account as AccountService;
use App\Http\Home\Services\Connect as ConnectService;
use App\Models\Connect as ConnectModel;
use App\Services\Logic\Account\EmailUpdate as EmailUpdateService;
use App\Services\Logic\Account\OAuthProvider as OAuthProviderService;
use App\Services\Logic\Account\PasswordReset as PasswordResetService;
use App\Services\Logic\Account\PasswordSetup as PasswordSetupService;
use App\Services\Logic\Account\PasswordUpdate as PasswordUpdateService;
use App\Services\Logic\Account\PhoneUpdate as PhoneUpdateService;
use App\Services\Logic\User\Console\AccountInfo as AccountInfoService;
use App\Services\Logic\Url\FullH5Url as FullH5UrlService;

/**
 * @RoutePrefix("/account")
 */
class AccountController extends Controller
{

    /**
     * @Get("/register", name="home.account.register")
     */
    public function registerAction()
    {
        return $this->response->redirect(['for' => 'home.account.login']);
    }

    /**
     * @Get("/login", name="home.account.login")
     */
    public function loginAction()
    {
        $service = new FullH5UrlService();

        if ($service->isMobileBrowser() && $service->h5Enabled()) {
            $location = $service->getAccountLoginUrl();
            return $this->response->redirect($location);
        }

        if ($this->authUser->id > 0) {
            return $this->response->redirect(['for' => 'home.index']);
        }

        $service = new OAuthProviderService();

        $oauthProvider = $service->handle();

        $returnUrl = $this->request->getHTTPReferer();

        $this->seo->prependTitle('用户登录');

        $this->view->setVar('oauth_provider', $oauthProvider);
        $this->view->setVar('return_url', $returnUrl);

        if ($oauthProvider['weixin']['enabled'] == 1) {
            $connectService = new ConnectService();
            $authorizeUrl = $connectService->getAuthorizeUrl(ConnectModel::PROVIDER_WEIXIN);
            $this->view->setVar('weixin_authorize_url', $authorizeUrl);
        }
    }

    /**
     * @Get("/logout", name="home.account.logout")
     */
    public function logoutAction()
    {
        $service = new AccountService();

        $service->logout();

        return $this->response->redirect(['for' => 'home.index']);
    }

    /**
     * @Get("/forget", name="home.account.forget")
     */
    public function forgetAction()
    {
        $service = new FullH5UrlService();

        if ($service->isMobileBrowser() && $service->h5Enabled()) {
            $location = $service->getAccountForgetUrl();
            return $this->response->redirect($location);
        }

        if ($this->authUser->id > 0) {
            return $this->response->redirect(['for' => 'home.index']);
        }

        $this->seo->prependTitle('忘记密码');
    }

    /**
     * @Post("/password/login", name="home.account.pwd_login")
     */
    public function loginByPasswordAction()
    {
        $service = new AccountService();

        $result = $service->loginByPassword();

        $returnUrl = $this->request->getPost('return_url', 'string');

        $location = $returnUrl ?: $this->url->get(['for' => 'home.index']);

        $content = [
            'location' => $location,
            'msg' => '登录账号成功',
            'is_new_user' => $result['is_new_user'],
            'need_set_password' => $result['status']['need_set_password'],
            'need_bind_phone' => $result['status']['need_bind_phone'],
        ];

        return $this->jsonSuccess($content);
    }

    /**
     * @Post("/verify/login", name="home.account.verify_login")
     */
    public function loginByVerifyAction()
    {
        $service = new AccountService();

        $result = $service->loginByVerify();

        $returnUrl = $this->request->getPost('return_url', 'string');

        $location = $returnUrl ?: $this->url->get(['for' => 'home.index']);

        if ($result['status']['need_set_password']) {
            $location = $this->appendQuery($location, ['set_password' => 1]);
        }

        $content = [
            'location' => $location,
            'msg' => $result['is_new_user'] ? '登录成功，账号已自动创建' : '登录账号成功',
            'is_new_user' => $result['is_new_user'],
            'need_set_password' => $result['status']['need_set_password'],
            'need_bind_phone' => $result['status']['need_bind_phone'],
        ];

        return $this->jsonSuccess($content);
    }

    /**
     * @Get("/password/setup", name="home.account.password_setup")
     */
    public function passwordSetupAction()
    {
        if ($this->authUser->id == 0) {
            return $this->response->redirect(['for' => 'home.account.login']);
        }

        $service = new AccountInfoService();

        $account = $service->handle();

        $this->view->pick('account/password_setup_layer');
        $this->view->setVar('account', $account);
    }

    /**
     * @Post("/password/setup", name="home.account.do_password_setup")
     */
    public function doPasswordSetupAction()
    {
        $service = new PasswordSetupService();

        $service->handle();

        $location = $this->url->get(['for' => 'home.uc.account'], ['type' => 'password']);

        $content = [
            'location' => $location,
            'target' => 'parent',
            'msg' => '设置登录密码成功',
        ];

        return $this->jsonSuccess($content);
    }

    /**
     * @Post("/password/reset", name="home.account.reset_pwd")
     */
    public function resetPasswordAction()
    {
        $service = new PasswordResetService();

        $service->handle();

        $loginUrl = $this->url->get(['for' => 'home.account.login']);

        $content = [
            'location' => $loginUrl,
            'msg' => '重置密码成功',
        ];

        return $this->jsonSuccess($content);
    }

    /**
     * @Post("/phone/update", name="home.account.update_phone")
     */
    public function updatePhoneAction()
    {
        $service = new PhoneUpdateService();

        $service->handle();

        $location = $this->url->get(['for' => 'home.uc.account']);

        $content = [
            'location' => $location,
            'msg' => '更新手机成功',
        ];

        return $this->jsonSuccess($content);
    }

    /**
     * @Post("/email/update", name="home.account.update_email")
     */
    public function updateEmailAction()
    {
        $service = new EmailUpdateService();

        $service->handle();

        $location = $this->url->get(['for' => 'home.uc.account']);

        $content = [
            'location' => $location,
            'msg' => '更新邮箱成功',
        ];

        return $this->jsonSuccess($content);
    }

    /**
     * @Post("/password/update", name="home.account.update_pwd")
     */
    public function updatePasswordAction()
    {
        $service = new PasswordUpdateService();

        $service->handle();

        $location = $this->url->get(['for' => 'home.uc.account']);

        $content = [
            'location' => $location,
            'msg' => '更新密码成功',
        ];

        return $this->jsonSuccess($content);
    }

}
