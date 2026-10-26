<form class="layui-form password-setup-form" method="POST" action="{{ url({'for':'home.account.do_password_setup'}) }}">
    {% if account.need_bind_phone %}
        <div class="setup-notice">
            <i class="layui-icon layui-icon-cellphone"></i>
            <div>
                <strong>首次登录</strong>
                <span>当前账号还未绑定手机号，绑定后即可使用手机号和密码登录。</span>
            </div>
        </div>
        <div class="setup-field">
            <label for="cv-phone">手机号</label>
            <div class="layui-input-wrap">
                <input id="cv-phone" class="layui-input" type="text" name="phone" autocomplete="off" placeholder="请输入手机号" lay-verify="required|phone">
            </div>
        </div>
        <div class="setup-field">
            <label for="cv-phone-code">短信验证码</label>
            <div class="setup-code-row">
                <div class="layui-input-wrap">
                    <input id="cv-phone-code" class="layui-input" type="text" name="verify_code" autocomplete="off" placeholder="请输入验证码" lay-verify="required">
                </div>
                <button id="cv-phone-emit-btn" class="layui-btn layui-btn-primary layui-btn-disabled" type="button" disabled="disabled">获取验证码</button>
            </div>
        </div>
        <input id="cv-phone-captcha-ticket" type="hidden" name="captcha[ticket]">
        <input id="cv-phone-captcha-rand" type="hidden" name="captcha[rand]">
    {% endif %}
    <div class="setup-field">
        <label for="cv-new-password">新设密码</label>
        <div class="layui-input-wrap">
            <input id="cv-new-password" class="layui-input" type="password" name="new_password" autocomplete="off" placeholder="6-16位字母、数字或符号" lay-affix="eye" lay-verify="required">
        </div>
    </div>
    <div class="setup-field">
        <label for="cv-confirm-password">确认密码</label>
        <div class="layui-input-wrap">
            <input id="cv-confirm-password" class="layui-input" type="password" name="confirm_password" autocomplete="off" placeholder="请再次输入新密码" lay-affix="eye" lay-verify="required">
        </div>
    </div>
    <div class="setup-actions">
        <button id="cv-phone-submit-btn" class="layui-btn layui-btn-fluid{% if account.need_bind_phone %} layui-btn-disabled{% endif %}"{% if account.need_bind_phone %} disabled="disabled"{% endif %} lay-submit="true" lay-filter="go">保存密码</button>
    </div>
</form>
