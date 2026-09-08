{% extends 'templates/main.volt' %}

{% block content %}

    {% set terms_url = url({'for':'home.page.show','id':'terms'}) %}
    {% set privacy_url = url({'for':'home.page.show','id':'privacy'}) %}
    {% set action_url = url({'for':'home.account.do_register'}) %}

    <div class="layui-breadcrumb breadcrumb">
        <a href="/">首页</a>
        <a><cite>用户注册</cite></a>
    </div>

    <div class="login-wrap wrap">
        {{ partial('account/register_by_phone') }}
        <div class="link">
            <a class="login-link" href="{{ url({'for':'home.account.login'}) }}">用户登录</a>
            <span class="separator">·</span>
            <a class="forget-link" href="{{ url({'for':'home.account.forget'}) }}">忘记密码</a>
        </div>
    </div>

{% endblock %}

{% block include_js %}

    {{ js_include('home/js/account.register.js') }}
    {{ js_include('home/js/captcha.verify.phone.js') }}

{% endblock %}
