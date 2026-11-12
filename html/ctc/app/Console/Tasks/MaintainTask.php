<?php


namespace App\Console\Tasks;

use App\Http\Admin\Services\Setting as SettingService;
use App\Library\Utils\Password as PasswordUtil;
use App\Models\ChapterVod as ChapterVodModel;
use App\Models\Course as CourseModel;
use App\Repos\Course as CourseRepo;
use App\Repos\CourseRating as CourseRatingRepo;
use App\Services\Utils\IndexPageCache as IndexPageCacheUtil;
use App\Validators\Account as AccountValidator;

class MaintainTask extends Task
{

    /**
     * 重建首页课程缓存
     *
     * @param array $params
     * @command: php console.php maintain rebuild_index_course_cache
     */
    public function rebuildIndexCourseCacheAction($params = [])
    {
        $section = $params[0] ?? null;

        $util = new IndexPageCacheUtil();

        $util->rebuild($section);

        echo '------ rebuild index course cache success ------' . PHP_EOL;
    }

    /**
     * 修改密码
     *
     * @param array $params
     * @command: php console.php maintain reset_password 13507083515 123456
     */
    public function resetPasswordAction($params)
    {
        if (empty($params[0])) {
            echo 'account is required' . PHP_EOL;
        }

        if (empty($params[1])) {
            echo 'password is required' . PHP_EOL;
        }

        $validator = new AccountValidator();

        $account = $validator->checkAccount($params[0]);

        $salt = PasswordUtil::salt();
        $hash = PasswordUtil::hash($params[1], $salt);

        $account->salt = $salt;
        $account->password = $hash;

        $account->update();

        echo '------ reset password success ------' . PHP_EOL;
    }

    /**
     * 关闭站点
     *
     * @command: php console.php maintain disable_site
     */
    public function disableSiteAction()
    {
        $service = new SettingService();

        $service->updateSettings('site', ['status' => 'closed']);

        echo '------ disable site success ------' . PHP_EOL;
    }

    /**
     * 开启站点
     *
     * @command: php console.php maintain enable_site
     */
    public function enableSiteAction()
    {
        $service = new SettingService();

        $service->updateSettings('site', ['status' => 'normal']);

        echo '------ enable site success ------' . PHP_EOL;
    }

    /**
     * 补齐缺失的课程评分记录
     *
     * 课程评分记录应在 Course::afterCreate() 中同步创建；历史数据（例如
     * Phalcon 5 自增主键未回填期间创建的课程）可能缺失，导致前台评分显示 0.0。
     *
     * @command: php console.php maintain repair_course_rating
     */
    public function repairCourseRatingAction()
    {
        $courseRepo = new CourseRepo();
        $courseRatingRepo = new CourseRatingRepo();

        $courses = CourseModel::find([
            'conditions' => 'deleted = 0',
            'columns' => 'id',
            'order' => 'id ASC',
        ]);

        $repaired = [];

        foreach ($courses as $course) {

            if ($courseRatingRepo->findByCourseId($course->id)) continue;

            if ($courseRatingRepo->findOrCreateByCourseId($course->id)) {
                $repaired[] = $course->id;
            }
        }

        /**
         * 清理主键回填异常期间产生的 course_id=0 脏数据
         */
        $invalid = $courseRatingRepo->findByCourseId(0);

        if ($invalid) {
            $invalid->delete();
        }

        if (empty($repaired)) {
            echo '------ 所有课程评分记录均存在，无需修复 ------' . PHP_EOL;
            return;
        }

        echo sprintf('------ 已补齐课程评分记录: %s ------', implode(',', $repaired)) . PHP_EOL;
    }

    /**
     * 清理点播转码缓存
     *
     * @command: php console.php maintain clear_file_transcode
     */
    public function clearFileTranscodeAction()
    {
        $chapterVodModel = new ChapterVodModel();

        $tableName = $chapterVodModel->getSource();

        $data = ['file_transcode' => '[]'];

        $fields = array_keys($data);

        $values = array_values($data);

        $where = ['conditions' => 'file_id > 0'];

        $this->db->update($tableName, $fields, $values, $where);

        echo '------ clear file transcode success ------' . PHP_EOL;
    }

}
