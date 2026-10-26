<?php


namespace App\Http\Api\Services;

use App\Exceptions\BadRequest as BadRequestException;
use App\Repos\User as UserRepo;
use App\Services\Auth\Api as AuthService;
use App\Services\Logic\Account\AutoRegister as AutoRegisterService;
use App\Services\Logic\Account\LoginFieldTrait as LoginFieldTrait;
use App\Services\Logic\Account\Register as RegisterService;
use App\Services\Logic\Account\Status as StatusService;
use App\Validators\Account as AccountValidator;
use App\Validators\Verify as VerifyValidator;

class Account extends Service
{

    use LoginFieldTrait;

    /**
     * @var AuthService
     */
    protected $auth;

    public function __construct()
    {
        $this->auth = $this->getDI()->get('auth');
    }

    public function register()
    {
        $service = new RegisterService();

        $account = $service->handle();

        $userRepo = new UserRepo();

        $user = $userRepo->findById($account->id);

        $token = $this->auth->saveAuthInfo($user);

        $this->eventsManager->fire('Account:afterRegister', $this, $user);

        return $token;
    }

    public function loginByPassword()
    {
        $post = $this->request->getPost();

        $post = $this->handleLoginFields($post);

        $validator = new AccountValidator();

        $user = $validator->checkUserLogin($post['account'], $post['password']);

        $validator->checkIfAllowLogin($user);

        $token = $this->auth->saveAuthInfo($user);

        $this->eventsManager->fire('Account:afterLogin', $this, $user);

        return $this->handleLoginResult($user, false, $token);
    }

    public function loginByVerify()
    {
        $post = $this->request->getPost();

        $post = $this->handleLoginFields($post);

        $validator = new AccountValidator();

        $validator->checkLoginName($post['account']);

        $verifyValidator = new VerifyValidator();

        $verifyValidator->checkCode($post['account'], $post['verify_code']);

        $registerService = new AutoRegisterService();

        list($account, $isNewUser) = $registerService->handle($post['account']);

        $userRepo = new UserRepo();

        $user = $userRepo->findById($account->id);

        if (!$user || $user->deleted == 1) {
            throw new BadRequestException('user.not_found');
        }

        $validator->checkIfAllowLogin($user);

        $token = $this->auth->saveAuthInfo($user);

        if ($isNewUser) {
            $this->eventsManager->fire('Account:afterRegister', $this, $user);
        } else {
            $this->eventsManager->fire('Account:afterLogin', $this, $user);
        }

        return $this->handleLoginResult($user, $isNewUser, $token);
    }

    public function logout()
    {
        $user = $this->getLoginUser();

        $this->auth->clearAuthInfo();

        $this->eventsManager->fire('Account:afterLogout', $this, $user);
    }

    protected function handleLoginResult($user, $isNewUser, $token): array
    {
        $statusService = new StatusService();

        return [
            'user' => $user,
            'token' => $token,
            'is_new_user' => (int)$isNewUser,
            'status' => $statusService->handle($user),
        ];
    }

}
