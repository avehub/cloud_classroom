{% extends 'templates/main.volt' %}

{% block content %}

    <div class="layui-card">
        <div class="layui-card-header">兑换会员</div>
        <div class="layui-card-body">
            {% if user.vip == 1 and user.vip_permanent == 1 %}
                <p>当前会员状态：<span class="layui-badge layui-bg-orange">永久会员</span></p>
                <p>您已永久享有会员权益，无需再次兑换或购买限时会员套餐。</p>
            {% else %}
                <p>
                    当前会员状态：
                    {% if user.vip == 1 %}
                        <span class="layui-badge layui-bg-orange">限时会员</span>
                        到期时间：{{ date('Y-m-d H:i:s',user.vip_expiry_time) }}
                    {% else %}
                        <span class="layui-badge">未开通会员</span>
                    {% endif %}
                </p>
                <p>输入一次性兑换码，成功兑换后即可成为永久会员。兑换码仅可成功使用一次，请妥善保管。</p>
                <form class="layui-form profile-form" method="post" action="{{ url({'for':'home.vip_redeem.create'}) }}">
                    <div class="layui-form-item">
                        <label class="layui-form-label">兑换码</label>
                        <div class="layui-input-block">
                            <input class="layui-input" type="text" name="code" autocomplete="off" placeholder="请输入20位会员兑换码" lay-verify="required" maxlength="24">
                        </div>
                    </div>
                    <div class="layui-form-item">
                        <label class="layui-form-label"></label>
                        <div class="layui-input-block">
                            <button class="layui-btn layui-bg-red" lay-submit="true" lay-filter="go">立即兑换</button>
                        </div>
                    </div>
                </form>
            {% endif %}
        </div>
    </div>

    {% if redeems %}
        <div class="layui-card">
            <div class="layui-card-header">我的兑换记录</div>
            <div class="layui-card-body">
                <table class="layui-table">
                    <thead>
                    <tr>
                        <th>兑换码</th>
                        <th>会员类型</th>
                        <th>兑换时间</th>
                    </tr>
                    </thead>
                    <tbody>
                    {% for item in redeems %}
                        <tr>
                            <td>{{ item.code }}</td>
                            <td>永久会员</td>
                            <td>{{ date('Y-m-d H:i:s',item.redeem_time) }}</td>
                        </tr>
                    {% endfor %}
                    </tbody>
                </table>
            </div>
        </div>
    {% endif %}

{% endblock %}
