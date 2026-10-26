<?php


namespace App\Http\Home\Services;

use App\Exceptions\BadRequest as BadRequestException;
use App\Repos\User as UserRepo;
use App\Services\Auth\Home as AuthService;
use App\Services\Logic\Account\AutoRegister as AutoRegisterService;
use App\Services\Logic\Account\LoginFieldTrait as LoginFieldTrait;
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

    public function loginByPassword()
    {
        $post = $this->request->getPost();

        $post = $this->handleLoginFields($post);

        $validator = new AccountValidator();

        $user = $validator->checkUserLogin($post['account'], $post['password']);

        $validator->checkIfAllowLogin($user);

        $this->auth->saveAuthInfo($user);

        $this->eventsManager->fire('Account:afterLogin', $this, $user);

        return $this->handleLoginResult($user, false);
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

        $this->auth->saveAuthInfo($user);

        if ($isNewUser) {
            $this->eventsManager->fire('Account:afterRegister', $this, $user);
        } else {
            $this->eventsManager->fire('Account:afterLogin', $this, $user);
        }

        return $this->handleLoginResult($user, $isNewUser);
    }

    public function logout()
    {
        $user = $this->getLoginUser();

        $this->auth->clearAuthInfo();

        $this->eventsManager->fire('Account:afterLogout', $this, $user);
    }

    protected function handleLoginResult($user, $isNewUser): array
    {
        $statusService = new StatusService();

        return [
            'user' => $user,
            'is_new_user' => (int)$isNewUser,
            'status' => $statusService->handle($user),
        ];
    }

}
