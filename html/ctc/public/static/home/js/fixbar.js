layui.use(['jquery'], function () {

    var $ = layui.jquery;

    var showQrcode = function (img) {
        var content = '<div class="qrcode"><img src="' + img + '" alt="扫码关注"></div>';
        layer.open({
            type: 1,
            title: false,
            closeBtn: 0,
            shadeClose: true,
            content: content,
        });
    }

    var showWechatCode = function () {
        showQrcode(window.contact.wechat);
    }

    var showQQCode = function () {
        showQrcode(window.contact.qq);
    }

    var showTouTiaoCode = function () {
        showQrcode(window.contact.toutiao);
    }

    var showDouYinCode = function () {
        showQrcode(window.contact.douyin);
    }

    var showPhoneCode = function () {
        var content = '<div class="layui-font-32 layui-font-red layui-padding-5">';
        content += '<i class="iconfont icon-phone layui-padding-1 layui-font-28"></i>' + window.contact.phone;
        content += '</div>';
        layer.open({
            type: 1,
            title: false,
            closeBtn: 0,
            shadeClose: true,
            content: content,
        });
    }

    var buildSideContact = function () {
        if (window.contact.enabled != 1) {
            return;
        }

        var html = '';

        if (window.contact.wechat) {
            html += '<div class="kg-sc-item">';
            html += '<i class="layui-icon layui-icon-login-wechat"></i><span>微信咨询</span>';
            html += '<div class="kg-sc-panel"><div class="kg-sc-panel-title">微信扫码咨询</div>';
            html += '<img src="' + window.contact.wechat + '" alt="微信二维码"></div>';
            html += '</div>';
        }

        if (window.contact.qq) {
            html += '<div class="kg-sc-item">';
            html += '<i class="layui-icon layui-icon-login-qq"></i><span>QQ咨询</span>';
            html += '<div class="kg-sc-panel"><div class="kg-sc-panel-title">QQ扫码咨询</div>';
            html += '<img src="' + window.contact.qq + '" alt="QQ二维码"></div>';
            html += '</div>';
        }

        if (window.contact.phone) {
            html += '<div class="kg-sc-item">';
            html += '<i class="iconfont icon-phone"></i><span>联系电话</span>';
            html += '<div class="kg-sc-panel kg-sc-panel-phone"><div class="kg-sc-panel-title">咨询电话</div>';
            html += '<a class="kg-sc-phone" href="tel:' + window.contact.phone + '">' + window.contact.phone + '</a></div>';
            html += '</div>';
        }

        html += '<div class="kg-sc-item kg-sc-top"><i class="layui-icon layui-icon-top"></i><span>顶部</span></div>';

        var $bar = $('<div class="kg-side-contact">' + html + '</div>');

        $('body').append($bar);

        var $top = $bar.find('.kg-sc-top');

        $(window).on('scroll', function () {
            if ($(this).scrollTop() > 200) {
                $top.fadeIn(200);
            } else {
                $top.fadeOut(200);
            }
        });

        $top.on('click', function () {
            $('html, body').animate({scrollTop: 0}, 300);
        });
    }

    buildSideContact();

    $('.contact > .wechat').on('click', function () {
        showWechatCode();
    });

    $('.contact > .qq').on('click', function () {
        showQQCode();
    });

    $('.contact > .toutiao').on('click', function () {
        showTouTiaoCode();
    });

    $('.contact > .douyin').on('click', function () {
        showDouYinCode();
    });

    $('.contact > .phone').on('click', function () {
        showPhoneCode();
    });

});
