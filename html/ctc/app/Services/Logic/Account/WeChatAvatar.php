<?php


namespace App\Services\Logic\Account;

use App\Library\Validators\Common as CommonValidator;
use App\Models\Connect as ConnectModel;
use App\Models\User as UserModel;
use App\Services\Logic\Service as LogicService;

class WeChatAvatar extends LogicService
{

    public function sync(UserModel $user, $provider, $avatarUrl)
    {
        $provider = (int)$provider;

        $wechatProviders = [
            ConnectModel::PROVIDER_WEIXIN,
            ConnectModel::PROVIDER_WECHAT_OA,
            ConnectModel::PROVIDER_WECHAT_MINI,
        ];

        if (!in_array($provider, $wechatProviders, true)) {
            return $user;
        }

        $avatar = trim((string)$avatarUrl);

        if (empty($avatar) || !UserModel::isWeChatAvatarUrl($avatar)) {
            return $user;
        }

        $avatar = UserModel::normalizeWeChatAvatarUrl($avatar);

        if (!CommonValidator::url($avatar) || strlen($avatar) > 255) {
            return $user;
        }

        $current = (string)$user->avatar;
        $currentPath = UserModel::getAvatarPath($current);
        $isDefault = $current === '' || $currentPath === kg_default_user_avatar_path();
        $isWeChat = UserModel::isWeChatAvatarUrl($current);

        /**
         * 保留用户主动上传或后台设置的头像，只同步默认头像和既有微信头像。
         */
        if (!$isDefault && !$isWeChat) {
            return $user;
        }

        if ($current === $avatar) {
            return $user;
        }

        try {

            $user->avatar = $avatar;
            $user->update();

        } catch (\Exception $e) {

            $logger = $this->getLogger();

            $logger->error('Sync WeChat Avatar Exception: ' . kg_json_encode([
                    'user_id' => $user->id,
                    'provider' => $provider,
                    'line' => $e->getLine(),
                    'message' => $e->getMessage(),
                ]));
        }

        return $user;
    }

}
