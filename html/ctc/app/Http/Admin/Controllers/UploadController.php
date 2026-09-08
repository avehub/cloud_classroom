<?php


namespace App\Http\Admin\Controllers;

use App\Http\Admin\Services\Upload as UploadService;
use App\Services\MyStorage as StorageService;
use App\Services\Vod as VodService;
use App\Validators\Validator as AppValidator;

/**
 * @RoutePrefix("/admin/upload")
 */
class UploadController extends Controller
{

    public function initialize()
    {
        $authUser = $this->getAuthUser();

        $validator = new AppValidator();

        $validator->checkAuthUser($authUser->id);
    }

    /**
     * @Post("/avatar/img", name="admin.upload.avatar_img")
     */
    public function uploadAvatarImageAction()
    {
        $service = new StorageService();

        $file = $service->uploadAvatarImage();

        if (!$file) {
            return $this->jsonError(['msg' => '上传文件失败']);
        }

        $data = [
            'id' => $file->id,
            'name' => $file->name,
            'url' => $service->getImageUrl($file->path),
        ];

        return $this->jsonSuccess(['data' => $data]);
    }

    /**
     * @Post("/icon/img", name="admin.upload.icon_img")
     */
    public function uploadIconImageAction()
    {
        $service = new StorageService();

        $file = $service->uploadIconImage();

        if (!$file) {
            return $this->jsonError(['msg' => '上传文件失败']);
        }

        $data = [
            'id' => $file->id,
            'name' => $file->name,
            'url' => $service->getImageUrl($file->path),
        ];

        return $this->jsonSuccess(['data' => $data]);
    }

    /**
     * @Post("/cover/img", name="admin.upload.cover_img")
     */
    public function uploadCoverImageAction()
    {
        $service = new StorageService();

        $file = $service->uploadCoverImage();

        if (!$file) {
            return $this->jsonError(['msg' => '上传文件失败']);
        }

        $data = [
            'id' => $file->id,
            'name' => $file->name,
            'url' => $service->getImageUrl($file->path),
        ];

        return $this->jsonSuccess(['data' => $data]);
    }

    /**
     * @Post("/content/img", name="admin.upload.content_img")
     */
    public function uploadContentImageAction()
    {
        $service = new StorageService();

        $file = $service->uploadContentImage();

        if (!$file) {
            return $this->jsonError([
                'message' => '上传文件失败',
                'error' => 1,
            ]);
        }

        return $this->jsonSuccess([
            'url' => $service->getImageUrl($file->path),
            'error' => 0,
        ]);
    }

    /**
     * @Post("/resource", name="admin.upload.resource")
     */
    public function uploadResourceAction()
    {
        $service = new StorageService();

        $file = $service->uploadResource();

        if (!$file) {
            return $this->jsonError(['msg' => '上传文件失败']);
        }

        return $this->jsonSuccess(['data' => $file]);
    }

    /**
     * @Post("/default/img", name="admin.upload.default_img")
     */
    public function uploadDefaultImageAction()
    {
        $service = new UploadService();

        $items = [];

        $items['category_icon'] = $service->uploadDefaultCategoryIcon();
        $items['user_avatar'] = $service->uploadDefaultUserAvatar();
        $items['article_cover'] = $service->uploadDefaultArticleCover();
        $items['course_cover'] = $service->uploadDefaultCourseCover();
        $items['package_cover'] = $service->uploadDefaultPackageCover();
        $items['topic_cover'] = $service->uploadDefaultTopicCover();
        $items['slide_cover'] = $service->uploadDefaultSlideCover();
        $items['gift_cover'] = $service->uploadDefaultGiftCover();
        $items['vip_cover'] = $service->uploadDefaultVipCover();

        foreach ($items as $key => $item) {
            $msg = sprintf('上传文件失败: %s', $key);
            if (!$item) {
                return $this->jsonError(['msg' => $msg]);
            }
        }

        return $this->jsonSuccess(['msg' => '上传文件成功']);
    }

    /**
     * @Post("/test", name="admin.upload.test")
     */
    public function uploadTestAction()
    {
        $service = new StorageService();

        $result = $service->uploadTestFile();

        if (!$result) {
            $isLocal = $service->isLocalStorage();
            $msg = $isLocal ? '本地存储上传测试失败' : '腾讯云COS上传测试失败，请检查配置';
            return $this->jsonError(['msg' => $msg]);
        }

        return $this->jsonSuccess([
            'msg' => '上传测试成功',
            'path' => $result,
        ]);
    }

    /**
     * @Post("/credentials", name="admin.upload.credentials")
     */
    public function credentialsAction()
    {
        $service = new StorageService();

        $token = $service->getFederationToken();

        if (!$token) {
            return $this->jsonError(['msg' => '当前为本地存储模式，不支持获取临时凭证']);
        }

        $data = [
            'credentials' => $token->getCredentials(),
            'expiredTime' => $token->getExpiredTime(),
            'startTime' => time(),
        ];

        return $this->jsonSuccess($data);
    }

    /**
     * @Post("/vod/auth", name="admin.upload.volc_auth")
     */
    public function volcUploadAuthAction()
    {
        $fileName = $this->request->getPost('file_name', 'string');
        $fileType = $this->request->getPost('file_type', 'string', 'video');

        $service = new VodService();

        $result = $service->getVolcUploadAuth($fileName, $fileType);

        return $this->jsonSuccess(['data' => $result]);
    }

    /**
     * 兼容旧版路由请求
     *
     * @Post("/vod/sign", name="admin.upload.vod_sign")
     */
    public function vodSignAction()
    {
        return $this->volcUploadAuthAction();
    }

    /**
     * @Post("/vod/commit", name="admin.upload.volc_commit")
     */
    public function volcCommitUploadAction()
    {
        $sessionKey = $this->request->getPost('session_key', 'string');
        $spaceName = $this->request->getPost('space_name', 'string');
        $title = $this->request->getPost('title', 'string');
        $tags = $this->request->getPost('tags', 'string');
        $description = $this->request->getPost('description', 'string');
        $templateId = $this->request->getPost('template_id', 'string');

        $service = new VodService();

        $result = $service->commitVolcUpload($sessionKey, $spaceName, [
            'title' => $title,
            'tags' => $tags,
            'description' => $description,
            'template_id' => $templateId,
        ]);

        return $this->jsonSuccess(['data' => $result]);
    }

}
