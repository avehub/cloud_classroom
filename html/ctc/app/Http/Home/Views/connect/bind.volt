{% extends 'templates/main.volt' %}

{% block content %}

    {% set terms_url = url({'for':'home.page.show','id':'terms'}) %}
    {% set privacy_url = url({'for':'home.page.show','id':'privacy'}) %}

    <div class="layui-breadcrumb breadcrumb">
        <a href="/">首页</a>
        <a><cite>登录绑定</cite></a>
    </div>

    <div class="login-wrap wrap">
        <div class="layui-tabs login-tab">
            <ul class="layui-tabs-header">
                <li class="layui-this">登录并绑定已有帐号</li>
            </ul>
            <div class="layui-tabs-body">
                <div class="layui-tabs-item layui-show">
                    {{ partial('connect/bind_login') }}
                </div>
            </div>
        </div>
    </div>

{% endblock %}

{% block include_js %}

    {{ js_include('home/js/connect.bind.js') }}

{% endblock %}
