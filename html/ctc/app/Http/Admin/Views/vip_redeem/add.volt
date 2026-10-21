{% extends 'templates/main.volt' %}

{% block content %}

    <form class="layui-form kg-form" method="POST" action="{{ url({'for':'admin.vip_redeem.create'}) }}">
        <fieldset class="layui-elem-field layui-field-title">
            <legend>生成永久会员兑换码</legend>
        </fieldset>
        <div class="layui-form-item">
            <label class="layui-form-label">批次名称</label>
            <div class="layui-input-block">
                <input class="layui-input" type="text" name="name" lay-verify="required" maxlength="50" placeholder="例如：2026年企业客户赠礼">
            </div>
        </div>
        <div class="layui-form-item">
            <label class="layui-form-label">生成数量</label>
            <div class="layui-input-block">
                <input class="layui-input" type="number" name="code_count" lay-verify="required|number" min="1" max="1000" value="100">
                <div class="layui-form-mid layui-word-aux">每批1-1000个，每个兑换码仅可成功兑换一次</div>
            </div>
        </div>
        <div class="layui-form-item">
            <label class="layui-form-label">兑换截止</label>
            <div class="layui-input-block">
                <input class="layui-input" type="text" name="expire_time" autocomplete="off" placeholder="留空表示长期有效">
                <div class="layui-form-mid layui-word-aux">此处限制兑换时间，兑换成功后的会员权益均为永久</div>
            </div>
        </div>
        <div class="layui-form-item">
            <label class="layui-form-label">备注</label>
            <div class="layui-input-block">
                <textarea class="layui-textarea" name="remark" maxlength="255" placeholder="用途、发放渠道等"></textarea>
            </div>
        </div>
        <div class="layui-form-item">
            <label class="layui-form-label"></label>
            <div class="layui-input-block">
                <button class="layui-btn" lay-submit="true" lay-filter="go">生成</button>
                <button type="button" class="kg-back layui-btn layui-btn-primary">返回</button>
            </div>
        </div>
    </form>

{% endblock %}

{% block inline_js %}

    <script>
        layui.use(['laydate'], function () {
            layui.laydate.render({
                elem: 'input[name=expire_time]',
                type: 'datetime'
            });
        });
    </script>

{% endblock %}
