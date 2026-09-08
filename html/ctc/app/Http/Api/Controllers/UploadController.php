<?php



namespace App\Http\Api\Controllers;

use App\Services\MyStorage as StorageService;

/**
 * @RoutePrefix("/api/upload")
 */
class UploadController extends Controller
{

    /**
     * @Post("/avatar/img", name="api.upload.avatar_img")
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

}