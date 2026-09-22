layui.use(['carousel', 'flow'], function () {

    var carousel = layui.carousel;
    var flow = layui.flow;
    var $ = layui.$;

    var $carousel = $('#carousel');
    var ratio = 336 / 1120;

    function carouselHeight() {
        return Math.round($carousel.width() * ratio) + 'px';
    }

    var ins = carousel.render({
        elem: '#carousel',
        width: '100%',
        height: carouselHeight()
    });

    if (ins) {
        var timer = null;
        $(window).on('resize', function () {
            clearTimeout(timer);
            timer = setTimeout(function () {
                ins.reload({width: '100%', height: carouselHeight()});
            }, 200);
        });
    }

    flow.lazyimg();
});
