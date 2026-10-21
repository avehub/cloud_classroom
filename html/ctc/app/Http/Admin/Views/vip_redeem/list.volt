{% extends 'templates/main.volt' %}

{% block content %}

    <div class="kg-nav">
        <div class="kg-nav-left">
            <span class="layui-breadcrumb"><a><cite>会员兑换码批次</cite></a></span>
        </div>
        <div class="kg-nav-right">
            <a class="layui-btn layui-btn-sm" href="{{ url({'for':'admin.vip_redeem.codes'}) }}">兑换码列表</a>
            <a class="layui-btn layui-btn-sm" href="{{ url({'for':'admin.vip_redeem.add'}) }}">
                <i class="layui-icon layui-icon-add-1"></i>生成兑换码
            </a>
        </div>
    </div>

    <table class="layui-table layui-form kg-table">
        <thead>
        <tr>
            <th>批次编号</th>
            <th>批次名称</th>
            <th>数量</th>
            <th>兑换截止</th>
            <th>创建人</th>
            <th>创建时间</th>
            <th>启用</th>
            <th>操作</th>
        </tr>
        </thead>
        <tbody>
        {% for item in pager.items %}
            {% set codes_url = url({'for':'admin.vip_redeem.codes'}) ~ '?batch_id=' ~ item.id %}
            {% set update_url = url({'for':'admin.vip_redeem.update','id':item.id}) %}
            <tr>
                <td>{{ item.id }}</td>
                <td><a href="{{ codes_url }}">{{ item.name }}</a></td>
                <td>{{ item.code_count }}</td>
                <td>{{ item.expire_time > 0 ? date('Y-m-d H:i:s',item.expire_time) : '长期有效' }}</td>
                <td>{{ item.creator_name }}</td>
                <td>{{ date('Y-m-d H:i:s',item.create_time) }}</td>
                <td>
                    <input type="checkbox" name="status" value="1" lay-text="是|否" lay-skin="switch" lay-filter="go"
                           data-url="{{ update_url }}" data-on-tips="启用后，该批次未使用兑换码可以兑换，确定启用？"
                           data-off-tips="停用后，该批次未使用兑换码不能兑换，确定停用？"
                           {% if item.status == 1 %}checked="checked"{% endif %}>
                </td>
                <td><a class="layui-btn layui-btn-sm" href="{{ codes_url }}">查看兑换码</a></td>
            </tr>
        {% endfor %}
        </tbody>
    </table>

    {{ partial('partials/pager') }}

{% endblock %}
