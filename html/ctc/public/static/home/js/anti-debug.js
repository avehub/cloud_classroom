/**
 * 前端开发者工具威慑层
 *
 * 注意：这只能提高普通用户的操作门槛，不能替代服务端播放鉴权和视频加密。
 */
(function () {
    'use strict';

    var options = window.antiDebugOptions || {};
    var detect = options.detect !== false;
    var contextmenu = options.contextmenu !== false;

    function isDevtoolsShortcut(e) {
        var key = (e.key || '').toUpperCase();
        var code = e.code || '';
        var mod = e.ctrlKey || e.metaKey;

        if (code === 'F12') return true;

        if (mod && e.shiftKey && (key === 'I' || key === 'J' || key === 'C')) {
            return true;
        }

        if (e.metaKey && e.altKey && (key === 'I' || key === 'J' || key === 'C')) {
            return true;
        }

        return false;
    }

    document.addEventListener('keydown', function (e) {
        if (isDevtoolsShortcut(e)) {
            e.preventDefault();
            e.stopPropagation();
        }
    }, true);

    if (contextmenu) {
        document.addEventListener('contextmenu', function (e) {
            e.preventDefault();
        }, true);
    }

    if (!detect) return;

    var threshold = 160;

    function check() {
        var widthGap = Math.abs(window.outerWidth - window.innerWidth);
        var heightGap = Math.abs(window.outerHeight - window.innerHeight);
        var opened = widthGap > threshold || heightGap > threshold;

        document.documentElement.classList.toggle('devtools-open', opened);
    }

    window.addEventListener('resize', check);
    setInterval(check, 1500);
})();
