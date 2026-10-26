{% extends 'templates/main.volt' %}

{% block content %}

    <div class="layout-main">
        <div class="my-sidebar">{{ partial('user/console/menu') }}</div>
        <div class="my-content">
            <div class="wrap">
                <div class="my-nav">
                    <span class="title">账号安全 - {% if account.has_password %}修改密码{% else %}设置登录密码{% endif %}</span>
                </div>
                {% if account.has_password %}
                    <form class="layui-form security-form" method="POST" action="{{ url({'for':'home.account.update_pwd'}) }}">
                        <div class="layui-form-item">
                            <label class="layui-form-label">原始密码</label>
                            <div class="layui-input-block">
                                <input class="layui-input" type="password" name="origin_password" autocomplete="off" lay-affix="eye" lay-verify="required">
                            </div>
                        </div>
                        <div class="layui-form-item">
                            <label class="layui-form-label">新设密码</label>
                            <div class="layui-input-block">
                                <input class="layui-input" type="password" name="new_password" autocomplete="off" placeholder="字母数字特殊字符6-16位" lay-affix="eye" lay-verify="required">
                            </div>
                        </div>
                        <div class="layui-form-item">
                            <label class="layui-form-label">确认密码</label>
                            <div class="layui-input-block">
                                <input class="layui-input" type="password" name="confirm_password" autocomplete="off" placeholder="字母数字特殊字符6-16位" lay-affix="eye" lay-verify="required">
                            </div>
                        </div>
                        <div class="layui-form-item">
                            <label class="layui-form-label"></label>
                            <div class="layui-input-block">
                                <button class="layui-btn" lay-submit="true" lay-filter="go">提交</button>
                                <button class="layui-btn layui-btn-primary" type="reset">重置</button>
                            </div>
                        </div>
                    </form>
                {% else %}
                    {{ partial('account/password_setup_form') }}
                {% endif %}
            </div>
        </div>
    </div>

{% endblock %}

{% block include_js %}

    {% if account.need_bind_phone and account.has_password == false %}
        {{ js_include('home/js/captcha.verify.phone.js') }}
    {% endif %}

{% endblock %}
