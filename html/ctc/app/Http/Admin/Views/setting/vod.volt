{% extends 'templates/main.volt' %}

{% block content %}

    {% set key_anti_display = vod.key_anti_enabled == 1 ? 'display:block': 'display:none' %}

    <form class="layui-form kg-form" method="POST" action="{{ url({'for':'admin.setting.vod'}) }}">
        <fieldset class="layui-elem-field layui-field-title">
            <legend>火山引擎点播基础配置</legend>
        </fieldset>
        <div class="layui-form-item">
            <label class="layui-form-label">AccessKey (AK)</label>
            <div class="layui-input-block">
                <input class="layui-input" type="text" name="volc_ak" value="{{ vod.volc_ak }}" placeholder="火山引擎访问密钥 AK" lay-verify="required">
            </div>
        </div>
        <div class="layui-form-item">
            <label class="layui-form-label">SecretKey (SK)</label>
            <div class="layui-input-block">
                <input class="layui-input" type="password" name="volc_sk" value="{{ vod.volc_sk }}" placeholder="火山引擎访问密钥 SK" lay-verify="required">
            </div>
        </div>
        <div class="layui-form-item">
            <label class="layui-form-label">点播空间名</label>
            <div class="layui-input-block">
                <input class="layui-input" type="text" name="volc_space_name" value="{{ vod.volc_space_name }}" placeholder="例如 2106720489-gdk-cloud-class" lay-verify="required">
            </div>
        </div>
        <div class="layui-form-item">
            <label class="layui-form-label">服务区域</label>
            <div class="layui-input-block">
                <input class="layui-input" type="text" name="volc_region" value="{{ vod.volc_region ? vod.volc_region : 'cn-north-1' }}" placeholder="华北2（北京）填 cn-north-1">
            </div>
        </div>

        <fieldset class="layui-elem-field layui-field-title">
            <legend>工作流与转码配置</legend>
        </fieldset>
        <div class="layui-form-item">
            <label class="layui-form-label">工作流模板ID</label>
            <div class="layui-input-block">
                <input class="layui-input" type="text" name="volc_trans_template" value="{{ vod.volc_trans_template }}" placeholder="火山引擎点播工作流模板 ID（可选，上传完成后自动触发）">
            </div>
        </div>
        <div class="layui-form-item">
            <label class="layui-form-label">H.264 转码</label>
            <div class="layui-input-block">
                <button type="button" class="layui-btn layui-btn-primary" id="h264-check-btn">检测编码配置</button>
                <button type="button" class="layui-btn" id="h264-apply-btn">切换为 H.264 转码</button>
                <div class="layui-font-gray" style="margin-top:8px;line-height:20px;">
                    上传的视频（含 HEVC/H.265 源片）将自动转码为 H.264 编码的 MP4（720P/480P/360P，只降不升），保障浏览器与移动端直接播放；
                    原工作流的封面截图、水印等配置会自动保留，切换前会备份原配置。
                </div>
            </div>
        </div>

        <fieldset class="layui-elem-field layui-field-title">
            <legend>主分发配置</legend>
        </fieldset>
        <div class="layui-form-item">
            <label class="layui-form-label">分发协议</label>
            <div class="layui-input-block">
                <input type="radio" name="protocol" value="https" title="HTTPS" {% if vod.protocol == "https" or vod.protocol is empty %}checked="checked"{% endif %}>
                <input type="radio" name="protocol" value="http" title="HTTP" {% if vod.protocol == "http" %}checked="checked"{% endif %}>
            </div>
        </div>
        <div class="layui-form-item">
            <label class="layui-form-label">分发域名</label>
            <div class="layui-input-block">
                <input class="layui-input" type="text" name="domain" value="{{ vod.domain }}" placeholder="点播加速分发域名 (如 vod.example.com)">
            </div>
        </div>

        <fieldset class="layui-elem-field layui-field-title">
            <legend>URL鉴权 / 防盗链</legend>
        </fieldset>
        <div class="layui-form-item">
            <label class="layui-form-label">开启防盗链</label>
            <div class="layui-input-block">
                <input type="radio" name="key_anti_enabled" value="1" title="是" lay-filter="key_anti_enabled" {% if vod.key_anti_enabled == 1 %}checked="checked"{% endif %}>
                <input type="radio" name="key_anti_enabled" value="0" title="否" lay-filter="key_anti_enabled" {% if vod.key_anti_enabled == 0 or vod.key_anti_enabled is empty %}checked="checked"{% endif %}>
            </div>
        </div>
        <div id="key-anti-block" style="{{ key_anti_display }}">
            <div class="layui-form-item">
                <label class="layui-form-label">防盗链Key</label>
                <div class="layui-input-block">
                    <input class="layui-input" type="text" name="key_anti_key" value="{{ vod.key_anti_key }}">
                </div>
            </div>
            <div class="layui-form-item">
                <label class="layui-form-label">有效时间（秒）</label>
                <div class="layui-input-block">
                    <input class="layui-input" type="text" name="key_anti_expiry" value="{{ vod.key_anti_expiry ? vod.key_anti_expiry : 7200 }}">
                </div>
            </div>
        </div>
        <div class="layui-form-item">
            <label class="layui-form-label"></label>
            <div class="layui-input-block">
                <button class="layui-btn" lay-submit="true" lay-filter="go">提交</button>
                <button type="button" class="kg-back layui-btn layui-btn-primary">返回</button>
            </div>
        </div>
    </form>

    <form class="layui-form kg-form" method="POST" action="{{ url({'for':'admin.test.vod'}) }}">
        <fieldset class="layui-elem-field layui-field-title">
            <legend>接口测试</legend>
        </fieldset>
        <div class="layui-form-item">
            <label class="layui-form-label"></label>
            <div class="layui-input-block">
                <button class="layui-btn" lay-submit="true" lay-filter="go">测试火山云点播配置</button>
                <span class="layui-font-gray" style="margin-left: 10px;">通过调用火山引擎点播空间媒体列表接口验证 AK/SK 及空间连通性</span>
            </div>
        </div>
    </form>

{% endblock %}

{% block inline_js %}

    <script>

        layui.use(['jquery', 'form', 'layer'], function () {

            var $ = layui.jquery;
            var form = layui.form;
            var layer = layui.layer;

            form.on('radio(key_anti_enabled)', function (data) {
                var block = $('#key-anti-block');
                if (data.value === '1') {
                    block.show();
                } else {
                    block.hide();
                }
            });

            $('#h264-check-btn').on('click', function () {
                var btn = $(this);
                btn.attr('disabled', 'disabled');
                $.ajax({
                    type: 'POST',
                    url: '{{ url({'for':'admin.setting.vod_h264_check'}) }}',
                    dataType: 'json',
                    success: function (res) {
                        layer.alert(res.msg || '检测完成', {title: '编码检测结果'});
                    },
                    error: function () {
                        layer.msg('检测请求失败');
                    },
                    complete: function () {
                        btn.removeAttr('disabled');
                    }
                });
            });

            $('#h264-apply-btn').on('click', function () {
                var btn = $(this);
                layer.confirm('确定将上传转码工作流切换为 H.264 编码？切换后新上传的视频将转码为 H.264 MP4。', {icon: 3, title: '提示'}, function (index) {
                    layer.close(index);
                    btn.attr('disabled', 'disabled').text('提交中···');
                    $.ajax({
                        type: 'POST',
                        url: '{{ url({'for':'admin.setting.vod_h264'}) }}',
                        dataType: 'json',
                        success: function (res) {
                            layer.alert(res.msg || '操作完成', {title: res.code === 0 ? '切换成功' : '切换失败'});
                        },
                        error: function () {
                            layer.msg('请求失败，请稍后重试');
                        },
                        complete: function () {
                            btn.removeAttr('disabled').text('切换为 H.264 转码');
                        }
                    });
                });
            });

        });

    </script>

{% endblock %}
