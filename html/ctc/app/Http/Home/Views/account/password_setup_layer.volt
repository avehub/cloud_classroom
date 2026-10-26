{% extends 'templates/layer.volt' %}

{% block content %}

    <div class="password-setup-layer">
        {% if account.has_password %}
            <div class="tips">登录密码已设置，无需重复设置。</div>
            <div class="layui-form-item">
                <div class="layui-input-block">
                    <button id="setup-close-btn" class="layui-btn layui-btn-primary" type="button">关闭</button>
                </div>
            </div>
        {% else %}
            {{ partial('account/password_setup_form') }}
            <div class="layui-form-item">
                <label class="layui-form-label"></label>
                <div class="layui-input-block">
                    <button id="setup-later-btn" class="layui-btn layui-btn-primary" type="button">稍后设置</button>
                </div>
            </div>
        {% endif %}
    </div>

{% endblock %}

{% block include_js %}

    {% if account.need_bind_phone and account.has_password == false %}
        {{ js_include('home/js/captcha.verify.phone.js') }}
    {% endif %}

{% endblock %}

{% block inline_js %}

    <script>
        layui.use(['jquery', 'layer'], function () {
            var $ = layui.jquery;
            var index = parent.layer.getFrameIndex(window.name);

            var closeLayer = function () {
                var url = new URL(parent.location.href);
                url.searchParams.delete('set_password');
                if (parent.history.replaceState) {
                    parent.history.replaceState(null, '', url.pathname + url.search + url.hash);
                }
                parent.layer.close(index);
            };

            $('#setup-close-btn, #setup-later-btn').on('click', closeLayer);
        });
    </script>

{% endblock %}
