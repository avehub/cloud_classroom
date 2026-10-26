<?php


namespace App\Http\Api\Controllers;

use App\Http\Api\Services\Account as AccountService;
use App\Services\Logic\Account\EmailUpdate as EmailUpdateService;
use App\Services\Logic\Account\PasswordReset as PasswordResetService;
use App\Services\Logic\Account\PasswordSetup as PasswordSetupService;
use App\Services\Logic\Account\PasswordUpdate as PasswordUpdateService;
use App\Services\Logic\Account\PhoneUpdate as PhoneUpdateService;

/**
 * @RoutePrefix("/api/account")
 */
class AccountController extends Controller
{

    /**
     * @Post("/register", name="api.account.register")
     */
    public function registerAction()
    {
        $service = new AccountService();

        $token = $service->register();

        return $this->jsonSuccess(['token' => $token]);
    }

    /**
     * @Post("/password/login", name="api.account.pwd_login")
     */
    public function loginByPasswordAction()
    {
        $service = new AccountService();

        $result = $service->loginByPassword();

        return $this->jsonSuccess([
            'token' => $result['token'],
            'is_new_user' => $result['is_new_user'],
            'need_set_password' => $result['status']['need_set_password'],
            'need_bind_phone' => $result['status']['need_bind_phone'],
        ]);
    }

    /**
     * @Post("/verify/login", name="api.account.verify_login")
     */
    public function loginByVerifyAction()
    {
        $service = new AccountService();

        $result = $service->loginByVerify();

        return $this->jsonSuccess([
            'token' => $result['token'],
            'is_new_user' => $result['is_new_user'],
            'need_set_password' => $result['status']['need_set_password'],
            'need_bind_phone' => $result['status']['need_bind_phone'],
        ]);
    }

    /**
     * @Get("/logout", name="api.account.logout")
     */
    public function logoutAction()
    {
        $service = new AccountService();

        $service->logout();

        return $this->jsonSuccess();
    }

    /**
     * @Post("/password/reset", name="api.account.reset_pwd")
     */
    public function resetPasswordAction()
    {
        $service = new PasswordResetService();

        $service->handle();

        return $this->jsonSuccess();
    }

    /**
     * @Post("/password/setup", name="api.account.setup_pwd")
     */
    public function setupPasswordAction()
    {
        $service = new PasswordSetupService();

        $service->handle();

        return $this->jsonSuccess(['msg' => '设置登录密码成功']);
    }

    /**
     * @Post("/phone/update", name="api.account.update_phone")
     */
    public function updatePhoneAction()
    {
        $service = new PhoneUpdateService();

        $service->handle();

        return $this->jsonSuccess();
    }

    /**
     * @Post("/email/update", name="api.account.update_email")
     */
    public function updateEmailAction()
    {
        $service = new EmailUpdateService();

        $service->handle();

        return $this->jsonSuccess();
    }

    /**
     * @Post("/password/update", name="api.account.update_pwd")
     */
    public function updatePasswordAction()
    {
        $service = new PasswordUpdateService();

        $service->handle();

        return $this->jsonSuccess();
    }

}
