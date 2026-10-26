{% extends 'templates/main.volt' %}

{% block content %}

    {% set terms_url = url({'for':'home.page.show','id':'terms'}) %}
    {% set privacy_url = url({'for':'home.page.show','id':'privacy'}) %}

    <div class="layui-breadcrumb breadcrumb">
        <a href="/">首页</a>
        <a><cite>用户登录</cite></a>
    </div>

    <div class="login-wrap wrap">
        <div class="layui-tabs login-tab">
            <ul class="layui-tabs-header">
                {% if oauth_provider.weixin.enabled == 1 %}
                    <li class="layui-this">微信登录</li>
                    <li>账号登录</li>
                {% else %}
                    <li class="layui-this">账号登录</li>
                {% endif %}
            </ul>
            <div class="layui-tabs-body">
                {% if oauth_provider.weixin.enabled == 1 %}
                    <div class="layui-tabs-item layui-show">
                        <div class="wechat-login-panel">
                            <iframe class="qrcode-frame" src="{{ weixin_authorize_url }}" frameborder="0" scrolling="no"></iframe>
                            <div class="fallback">
                                <a href="{{ url({'for':'home.oauth.weixin'}) }}">二维码无法显示？点击前往微信登录</a>
                            </div>
                        </div>
                    </div>
                {% endif %}
                <div class="layui-tabs-item{% if oauth_provider.weixin.enabled != 1 %} layui-show{% endif %}">
                    <div id="verify-login-panel">
                        <div class="auto-register-note">
                            <i class="layui-icon layui-icon-tips"></i>
                            <span>未注册手机号，验证后将自动创建账号</span>
                        </div>
                        {{ partial('account/login_by_verify') }}
                        <div class="switch-link">
                            <a id="goto-pwd-login" href="javascript:;">密码登录</a>
                            <span class="separator">·</span>
                            <a class="forget-link" href="{{ url({'for':'home.account.forget'}) }}">忘记密码</a>
                        </div>
                    </div>
                    <div id="pwd-login-panel" style="display:none;">
                        {{ partial('account/login_by_password') }}
                        <div class="switch-link">
                            <a id="goto-verify-login" href="javascript:;">验证码登录</a>
                            <span class="separator">·</span>
                            <a class="forget-link" href="{{ url({'for':'home.account.forget'}) }}">忘记密码</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="agreement-tips">登录即表示同意<a href="{{ terms_url }}" target="_blank">《用户协议》</a>和<a href="{{ privacy_url }}" target="_blank">《隐私政策》</a></div>
        {% if oauth_provider.qq.enabled == 1 or oauth_provider.weibo.enabled == 1 %}
            <div class="oauth">
                <span class="oauth-tips">其他登录方式</span>
                {% if oauth_provider.qq.enabled == 1 %}
                    <a class="layui-icon layui-icon-login-qq login-qq" href="{{ url({'for':'home.oauth.qq'}) }}"></a>
                {% endif %}
                {% if oauth_provider.weibo.enabled == 1 %}
                    <a class="layui-icon layui-icon-login-weibo login-weibo" href="{{ url({'for':'home.oauth.weibo'}) }}"></a>
                {% endif %}
            </div>
        {% endif %}
    </div>

{% endblock %}

{% block include_js %}

    {{ js_include('home/js/captcha.verify.js') }}

{% endblock %}

{% block inline_js %}

    <script>
        if (window !== top) {
            top.location.href = window.location.href;
        }
        layui.use(['jquery'], function () {
            var $ = layui.jquery;
            $('#goto-verify-login').on('click', function () {
                $('#pwd-login-panel').hide();
                $('#verify-login-panel').show();
            });
            $('#goto-pwd-login').on('click', function () {
                $('#verify-login-panel').hide();
                $('#pwd-login-panel').show();
            });
        });
    </script>

{% endblock %}
