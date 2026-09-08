{% extends 'templates/main.volt' %}

{% block content %}

    <fieldset class="layui-elem-field layui-field-title">
        <legend>配置说明</legend>
    </fieldset>
    <div class="layui-form-item">
        <div class="layui-input-block">
            <p>短信服务已切换为<strong>阿里云短信</strong>，相关配置统一存放在项目根目录 <code>.env</code> 文件中（SMS_ 前缀配置项），修改后需重启 PHP 容器生效：</p>
            <pre>docker compose up -d php</pre>
        </div>
    </div>

    <fieldset class="layui-elem-field layui-field-title">
        <legend>账号配置</legend>
    </fieldset>
    <table class="layui-table kg-table">
        <colgroup>
            <col width="30%">
            <col>
        </colgroup>
        <tbody>
        <tr>
            <td>签名（SMS_SIGN_NAME）</td>
            <td>{% if sign_name %}{{ sign_name }}{% else %}<span style="color:#FF5722;">未配置</span>{% endif %}</td>
        </tr>
        <tr>
            <td>区域（SMS_REGION）</td>
            <td>{{ region }}</td>
        </tr>
        </tbody>
    </table>

    <fieldset class="layui-elem-field layui-field-title">
        <legend>模板配置</legend>
    </fieldset>
    <table class="layui-table kg-table">
            <colgroup>
                <col width="12%">
                <col width="12%">
                <col width="12%">
                <col>
                <col width="10%">
            </colgroup>
            <thead>
            <tr>
                <th>名称</th>
                <th>环境变量</th>
                <th>状态</th>
                <th>模板内容（到阿里云申请模板，通过后将模板 Code 填入 .env）</th>
                <th>操作</th>
            </tr>
            </thead>
            <tbody>
            {% for code, item in templates %}
            <tr>
                <td>{{ item.name }}</td>
                <td>{{ item.env }}</td>
                <td>{% if item.enabled == 1 %}已配置{% else %}<span style="color:#FF5722;">未配置</span>{% endif %}</td>
                <td><input id="tc-{{ code }}" class="layui-input" type="text" value="{{ item.content }}" readonly="readonly"></td>
                <td><span class="kg-copy layui-btn" data-clipboard-target="#tc-{{ code }}">复制</span></td>
            </tr>
            {% endfor %}
            </tbody>
        </table>

    <form class="layui-form kg-form" method="POST" action="{{ url({'for':'admin.test.sms'}) }}">
        <fieldset class="layui-elem-field layui-field-title">
            <legend>短信测试</legend>
        </fieldset>
        <div class="layui-form-item">
            <label class="layui-form-label">手机号码</label>
            <div class="layui-input-block">
                <input class="layui-input" type="text" name="phone" placeholder="请先配置 .env 并重启 PHP 容器，再进行短信测试哦！" lay-verify="phone">
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

{% endblock %}

{% block include_js %}

    {{ js_include('lib/clipboard.min.js') }}
    {{ js_include('admin/js/copy.js') }}

{% endblock %}