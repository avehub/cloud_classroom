<?php


require_once 'SettingTrait.php';

use Phinx\Migration\AbstractMigration;

final class V20260921000001 extends AbstractMigration
{

    use SettingTrait;

    public function up()
    {
        $this->handleVodSettings();
    }

    /**
     * 火山云点播 H.264 转码接入自建 1080P 模板
     *
     * 官方预设的 H.264 MP4 转码模板最高只有 720P，1080P 需在点播控制台自建后登记于此，
     * 配置格式为 "分辨率:模板ID"，多档以逗号分隔，如 1080:xxx,720:yyy
     *
     * 注意: 模板 ID 与火山云点播空间绑定，若目标环境使用的不是 gdk-cloud-class 空间，
     * 需替换为该空间下自建 1080P H.264 模板的 ID，否则转码任务会因模板不存在而失败。
     * 未登记该配置时，Vod::detectH264Presets() 仍可从空间自动探测到 1080P 模板作为兜底。
     *
     * insertSettings 仅在配置项不存在时写入，不会覆盖运维在后台自行调整的档位。
     * 执行迁移后需重建配置缓存: php console.php --task=upgrade --action=resetSetting
     */
    protected function handleVodSettings()
    {
        $rows = [
            [
                'section' => 'vod',
                'item_key' => 'volc_h264_templates',
                'item_value' => '1080:5c446e0d244e4df79372379ea083c2c0',
            ],
        ];

        $this->insertSettings($rows);
    }

}
