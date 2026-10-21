{% extends 'templates/main.volt' %}

{% block content %}

    <form class="layui-form kg-form" method="GET" action="{{ url({'for':'admin.vip_redeem.codes'}) }}">
        <fieldset class="layui-elem-field layui-field-title">
            <legend>搜索兑换码</legend>
        </fieldset>
        <div class="layui-form-item">
            <label class="layui-form-label">批次编号</label>
            <div class="layui-input-block"><input class="layui-input" type="number" name="batch_id" min="1"></div>
        </div>
        <div class="layui-form-item">
            <label class="layui-form-label">兑换码</label>
            <div class="layui-input-block"><input class="layui-input" type="text" name="code" autocomplete="off" placeholder="完整兑换码精确匹配"></div>
        </div>
        <div class="layui-form-item">
            <label class="layui-form-label">用户编号</label>
            <div class="layui-input-block"><input class="layui-input" type="number" name="user_id" min="1"></div>
        </div>
        <div class="layui-form-item">
            <label class="layui-form-label">状态</label>
            <div class="layui-input-block">
                <select name="status">
                    <option value="">全部</option>
                    {% for value,title in status_types %}
                        <option value="{{ value }}">{{ title }}</option>
                    {% endfor %}
                </select>
            </div>
        </div>
        <div class="layui-form-item">
            <label class="layui-form-label"></label>
            <div class="layui-input-block">
                <button class="layui-btn" lay-submit="true">提交</button>
                <button type="button" class="kg-back layui-btn layui-btn-primary">返回</button>
            </div>
        </div>
    </form>

{% endblock %}
