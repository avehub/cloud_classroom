layui.use(['jquery', 'helper', 'layer'], function () {

    var $ = layui.jquery;
    var helper = layui.helper;
    var layer = layui.layer;

    var interval = null;
    var intervalTime = 15000;
    var userId = window.user.id;
    var requestId = helper.getRequestId();
    var chapterId = $('input[name="chapter.id"]').val();
    var cover = $('input[name="chapter.cover"]').val();
    var planId = $('input[name="chapter.me.plan_id"]').val();
    var position = parseInt($('input[name="chapter.me.position"]').val() || 0, 10);
    var learningUrl = $('input[name="chapter.learning_url"]').val();
    var playTicketUrl = $('input[name="chapter.play_ticket_url"]').val();

    var player = null;
    var ticketLoading = false;
    var retryCount = 0;
    var lastRetryAt = 0;
    var lastTicketAt = 0;
    var watermarkTimer = null;
    var lastPosition = getLastPosition();

    var rates = [
        {name: 'hd', label: '高清'},
        {name: 'sd', label: '标清'},
        {name: 'fd', label: '极速'},
    ];

    var $playMask = $('#play-mask');

    function requestPlayInfo() {
        return $.ajax({
            type: 'POST',
            url: playTicketUrl,
            dataType: 'json'
        });
    }

    function buildVideoOptions(data) {
        var playUrls = data.play_urls || {};
        var quality = [];

        $.each(rates, function (k, rate) {
            if (playUrls.hasOwnProperty(rate.name) && playUrls[rate.name].url) {
                quality.push({
                    name: rate.label,
                    url: playUrls[rate.name].url
                });
            }
        });

        if (quality.length > 0) {
            return {
                pic: cover,
                quality: quality,
                defaultQuality: 0
            };
        }

        return {
            pic: cover,
            url: data.url || '',
            type: data.type || 'auto'
        };
    }

    function initPlayer(data) {
        if (player) {
            try {
                lastPosition = player.video.currentTime || lastPosition;
                player.destroy();
            } catch (e) {
                // ignore
            }
            player = null;
        }

        var container = document.getElementById('player');

        if (!container) {
            container = document.createElement('div');
            container.id = 'player';
            $('.player-wrap').prepend(container);
        }

        player = new DPlayer({
            container: container,
            video: buildVideoOptions(data)
        });

        player.on('play', function () {
            retryCount = 0;
            play();
        });

        player.on('pause', function () {
            pause();
        });

        player.on('ended', function () {
            ended();
        });

        player.on('error', function () {
            var now = Date.now();

            if (retryCount < 1 && now - lastRetryAt > 10000) {
                retryCount++;
                lastRetryAt = now;
                layer.msg('播放授权已刷新，正在重试', {icon: 0, time: 1500});
                loadPlayInfo();
                return;
            }

            layer.msg('播放失败，请刷新页面后重试', {icon: 2});
        });

        if (lastPosition > 0) {
            setTimeout(function () {
                try {
                    player.seek(lastPosition);
                } catch (e) {
                    // ignore
                }
            }, 300);
        }

        startWatermark(data.watermark || {});
    }

    function loadPlayInfo() {
        if (ticketLoading) return;

        var now = Date.now();

        // 防止错误事件或连续点击在短时间内刷爆 play-ticket
        if (now - lastTicketAt < 1500) return;

        lastTicketAt = now;
        ticketLoading = true;
        $playMask.show();

        requestPlayInfo().done(function (res) {
            if (!res || res.code !== 0) {
                layer.msg((res && res.msg) || '播放授权失败', {icon: 2});
                return;
            }

            initPlayer(res.data || {});
        }).fail(function (xhr) {
            var apiMsg = xhr.responseJSON && xhr.responseJSON.msg;

            if (apiMsg) {
                layer.msg(apiMsg, {icon: 2});
            } else if (xhr.status === 401) {
                layer.msg('登录状态已失效，请重新登录', {icon: 2});
            } else if (xhr.status === 403) {
                layer.msg('暂无播放权限', {icon: 2});
            } else {
                layer.msg('播放授权请求失败，请稍后重试', {icon: 2});
            }
        }).always(function () {
            ticketLoading = false;
        });
    }

    function startWatermark(info) {
        var $watermark = $('#player-watermark');

        if (!$watermark.length) return;

        if (watermarkTimer) {
            clearInterval(watermarkTimer);
            watermarkTimer = null;
        }

        var name = info.name || '用户';
        var uid = info.uid || userId;

        function move() {
            var $wrap = $('.player-wrap');
            var width = $wrap.width();
            var height = $wrap.height();
            var wmWidth = $watermark.outerWidth() || 120;
            var wmHeight = $watermark.outerHeight() || 24;

            var maxLeft = Math.max(10, width - wmWidth - 18);
            var maxTop = Math.max(10, height - wmHeight - 18);

            var left = 18 + Math.random() * Math.max(0, maxLeft - 18);
            var top = 18 + Math.random() * Math.max(0, maxTop - 18);

            var time = new Date().toLocaleString('zh-CN', {hour12: false});

            $watermark.text(uid + ' · ' + name + ' · ' + time).css({
                left: left + 'px',
                top: top + 'px'
            });
        }

        move();
        watermarkTimer = setInterval(move, 30000);
    }

    function getPositionKey() {
        return 'chapter:' + chapterId + ':position';
    }

    function getLastPosition() {
        var key = getPositionKey();
        var value = localStorage.getItem(key);
        return value != null ? parseInt(value, 10) : position;
    }

    function setLastPosition(value) {
        var key = getPositionKey();
        localStorage.setItem(key, value);
    }

    function clearLearningInterval() {
        if (interval != null) {
            clearInterval(interval);
            interval = null;
        }
    }

    function setLearningInterval() {
        interval = setInterval(learning, intervalTime);
    }

    function play() {
        $playMask.hide();
        clearLearningInterval();
        setLearningInterval();
    }

    function pause() {
        $playMask.show();
        clearLearningInterval();
    }

    function ended() {
        learning();
        setLastPosition(0);
        clearLearningInterval();
    }

    function learning() {
        if (!player || !player.video) return;

        setLastPosition(player.video.currentTime);

        if (userId !== '0' && planId !== '0') {
            $.ajax({
                type: 'POST',
                url: learningUrl,
                data: {
                    plan_id: planId,
                    request_id: requestId,
                    interval_time: intervalTime,
                    position: player.video.currentTime
                }
            });
        }
    }

    $playMask.on('click', function () {
        if (!player) {
            loadPlayInfo();
            return;
        }

        $(this).hide();
        player.toggle();
    });

    $(window).on('beforeunload', function () {
        clearLearningInterval();
        if (watermarkTimer) clearInterval(watermarkTimer);
    });

    loadPlayInfo();

});
