<form class="layui-form security-form" method="POST" action="{{ url({'for':'home.account.do_password_setup'}) }}">
    {% if account.need_bind_phone %}
        <div class="tips tips-alert">当前账号还没有可用于密码登录的手机号，请先绑定手机号。</div>
        <div class="layui-form-item">
            <label class="layui-form-label">手机号码</label>
            <div class="layui-input-block">
                <input id="cv-phone" class="layui-input" type="text" name="phone" placeholder="请输入手机号码" lay-verify="required|phone">
            </div>
        </div>
        <div class="layui-form-item">
            <label class="layui-form-label">验证码</label>
            <div class="layui-input-inline verify-input-inline">
                <input class="layui-input" type="text" name="verify_code" lay-verify="required">
            </div>
            <div class="layui-input-inline verify-btn-inline">
                <button id="cv-phone-emit-btn" class="layui-btn layui-btn-primary layui-btn-disabled" type="button" disabled="disabled">获取验证码</button>
            </div>
        </div>
        <input id="cv-phone-captcha-ticket" type="hidden" name="captcha[ticket]">
        <input id="cv-phone-captcha-rand" type="hidden" name="captcha[rand]">
    {% else %}
        <div class="tips">设置密码后即可使用手机号或邮箱进行密码登录。</div>
    {% endif %}
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
            <button id="cv-phone-submit-btn" class="layui-btn" lay-submit="true" lay-filter="go">保存密码</button>
        </div>
    </div>
</form>
