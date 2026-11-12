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
                <li class="layui-this">账号登录</li>
                {% if oauth_provider.weixin.enabled == 1 %}
                    <li data-login-tab="weixin">微信登录</li>
                {% endif %}
            </ul>
            <div class="layui-tabs-body">
                <div class="layui-tabs-item layui-show">
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
                {% if oauth_provider.weixin.enabled == 1 %}
                    <div class="layui-tabs-item">
                        <div class="wechat-login-panel">
                            <iframe id="weixin-qrcode-frame" class="qrcode-frame" data-src="{{ weixin_authorize_url }}" frameborder="0" scrolling="no"></iframe>
                            <div class="fallback">
                                <a href="{{ url({'for':'home.oauth.weixin'}) }}">二维码无法显示？点击前往微信登录</a>
                            </div>
                        </div>
                    </div>
                {% endif %}
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
            // 微信登录不再是默认 Tab，二维码 iframe 延迟到首次切换时加载，避免展示已过期的二维码
            var $qrcodeFrame = $('#weixin-qrcode-frame');
            $('.login-tab .layui-tabs-header li[data-login-tab="weixin"]').on('click', function () {
                if ($qrcodeFrame.length === 0 || $qrcodeFrame.attr('src')) {
                    return;
                }
                var src = $qrcodeFrame.attr('data-src');
                if (src) {
                    $qrcodeFrame.attr('src', src);
                }
            });
        });
    </script>

{% endblock %}
