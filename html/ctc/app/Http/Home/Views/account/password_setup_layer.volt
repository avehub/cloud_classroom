{% extends 'templates/layer.volt' %}

{% block inline_css %}
    <style>
        body.layer { padding: 0; }
    </style>
{% endblock %}

{% block content %}

    <div class="password-setup-layer">
        <div class="setup-header{% if account.has_password %} setup-header-success{% endif %}">
            <div class="setup-icon">
                <i class="layui-icon {% if account.has_password %}layui-icon-success{% else %}layui-icon-password{% endif %}"></i>
            </div>
            <h2>{% if account.has_password %}登录密码已设置{% else %}设置登录密码{% endif %}</h2>
            <p>{% if account.has_password %}无需重复设置，可直接使用手机号和密码登录{% else %}设置后可使用手机号和密码登录，更安全便捷{% endif %}</p>
        </div>
        {% if account.has_password %}
            <div class="setup-actions">
                <button id="setup-close-btn" class="layui-btn layui-btn-fluid" type="button">知道了</button>
            </div>
        {% else %}
            {{ partial('account/password_setup_form') }}
            <button id="setup-later-btn" class="setup-skip" type="button">暂时跳过，稍后设置</button>
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

            var notifyHeight = function () {
                if (parent === window) return;
                var height = Math.ceil(document.body.scrollHeight);
                parent.postMessage({type: 'ctc-password-setup-height', height: height}, window.location.origin);
            };

            var closeLayer = function () {
                var url = new URL(parent.location.href);
                url.searchParams.delete('set_password');
                if (parent.history.replaceState) {
                    parent.history.replaceState(null, '', url.pathname + url.search + url.hash);
                }
                parent.layer.close(index);
            };

            $('#setup-close-btn, #setup-later-btn').on('click', closeLayer);

            window.addEventListener('load', notifyHeight);
            window.addEventListener('resize', notifyHeight);
            setTimeout(notifyHeight, 50);
            setTimeout(notifyHeight, 300);
        });
    </script>

{% endblock %}
