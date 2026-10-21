{% extends 'templates/main.volt' %}

{% block content %}

    <div class="kg-nav">
        <div class="kg-nav-left">
            <span class="layui-breadcrumb"><a><cite>会员兑换码</cite></a></span>
        </div>
        <div class="kg-nav-right">
            <a class="layui-btn layui-btn-sm" href="{{ url({'for':'admin.vip_redeem.list'}) }}">批次列表</a>
            <a class="layui-btn layui-btn-sm" href="{{ url({'for':'admin.vip_redeem.search'}) }}">
                <i class="layui-icon layui-icon-search"></i>搜索兑换码
            </a>
        </div>
    </div>

    <form class="layui-form" method="POST" action="{{ url({'for':'admin.vip_redeem.export'}) }}" style="margin-bottom:15px;">
        <input type="hidden" name="csrf_token" value="{{ csrfToken.getToken() }}">
        <input type="hidden" name="status" value="{{ filters.status }}">
        <input type="hidden" name="user_id" value="{{ filters.user_id }}">
        <div class="layui-input-inline">
            <input class="layui-input" type="number" name="batch_id" min="1" required value="{{ filters.batch_id }}" placeholder="输入批次编号以导出">
        </div>
        <button class="layui-btn layui-btn-sm" type="submit">导出CSV</button>
        <span class="layui-form-mid layui-word-aux">列表中的兑换码已脱敏，完整兑换码仅通过导出获取</span>
    </form>

    <table class="layui-table kg-table">
        <thead>
        <tr>
            <th>编号</th>
            <th>批次</th>
            <th>兑换码</th>
            <th>状态</th>
            <th>兑换用户</th>
            <th>兑换时间</th>
            <th>兑换IP</th>
            <th>操作</th>
        </tr>
        </thead>
        <tbody>
        {% for item in pager.items %}
            {% set disable_url = url({'for':'admin.vip_redeem.disable','id':item.id}) %}
            <tr>
                <td>{{ item.id }}</td>
                <td>{{ item.batch_name }}（{{ item.batch_id }}）</td>
                <td>{{ item.code }}</td>
                <td>{{ status_types[item.status] }}</td>
                <td>{{ item.user_id > 0 ? item.user_name ~ '（' ~ item.user_id ~ '）' : 'N/A' }}</td>
                <td>{{ item.redeem_time > 0 ? date('Y-m-d H:i:s',item.redeem_time) : 'N/A' }}</td>
                <td>{{ item.client_ip ? item.client_ip : 'N/A' }}</td>
                <td>
                    {% if item.status != 1 %}
                        <button class="layui-btn layui-btn-sm kg-disable-code" data-url="{{ disable_url }}">停用</button>
                    {% else %}
                        N/A
                    {% endif %}
                </td>
            </tr>
        {% endfor %}
        </tbody>
    </table>

    {{ partial('partials/pager') }}

{% endblock %}

{% block inline_js %}

    <script>
        layui.use(['jquery', 'layer'], function () {
            var $ = layui.jquery;
            var layer = layui.layer;

            $('.kg-disable-code').on('click', function () {
                var url = $(this).data('url');
                layer.confirm('停用后该兑换码将无法使用，且不影响已完成兑换，确定停用？', function () {
                    $.post(url, function (res) {
                        layer.msg(res.msg, {icon: 1});
                        setTimeout(function () {
                            window.location.reload();
                        }, 1000);
                    });
                });
            });
        });
    </script>

{% endblock %}
