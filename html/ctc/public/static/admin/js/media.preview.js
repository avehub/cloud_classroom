layui.use(['jquery', 'layer'], function () {

    var $ = layui.jquery;
    var layer = layui.layer;

    $('.kg-preview').on('click', function () {

        var chapterId = $(this).data('chapter-id');

        if (!chapterId) return;

        var frameUrl = '/admin/vod/player?chapter_id=' + encodeURIComponent(chapterId);

        layer.open({
            id: 'player',
            type: 2,
            title: '媒体播放器',
            resize: false,
            area: ['720px', '456px'],
            content: [frameUrl, 'no']
        });

    });

});