{% extends 'templates/main.volt' %}

{% block content %}

    <div id="player"></div>

    <div class="layui-hide">
        <input type="hidden" name="chapter.id" value="{{ chapter_id }}">
        <input type="hidden" name="preview.ticket_url" value="{{ url({'for':'admin.vod_preview_ticket'},{'chapter_id':chapter_id}) }}">
    </div>

{% endblock %}

{% block inline_css %}

    <style>
        .kg-body {
            padding: 0;
        }

        #player {
            width: 720px;
            height: 405px;
        }
    </style>

{% endblock %}

{% block include_js %}

    {{ js_include('lib/dplayer/hls.min.js') }}
    {{ js_include('lib/dplayer/DPlayer.min.js') }}

{% endblock %}

{% block inline_js %}

    <script>

        layui.use(['jquery', 'layer'], function () {

            var $ = layui.jquery;
            var layer = layui.layer;

            var ticketUrl = $('input[name="preview.ticket_url"]').val();
            var rates = [
                {name: 'hd', label: '高清'},
                {name: 'sd', label: '标清'},
                {name: 'fd', label: '极速'},
            ];

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
                        quality: quality,
                        defaultQuality: 0
                    };
                }

                return {
                    url: data.url || '',
                    type: data.type || 'auto'
                };
            }

            $.getJSON(ticketUrl).done(function (res) {

                if (!res || res.code !== 0) {
                    layer.msg((res && res.msg) || '预览授权失败', {icon: 2});
                    return;
                }

                new DPlayer({
                    container: document.getElementById('player'),
                    video: buildVideoOptions(res.data || {})
                });

            }).fail(function (xhr) {
                var msg = xhr.responseJSON && xhr.responseJSON.msg;
                layer.msg(msg || '预览授权失败', {icon: 2});
            });

        });

    </script>

{% endblock %}
