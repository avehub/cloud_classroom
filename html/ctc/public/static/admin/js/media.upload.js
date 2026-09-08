layui.use(['jquery', 'element', 'layer'], function () {

    var $ = layui.jquery;
    var element = layui.element;
    var layer = layui.layer;

    // 标准 CRC32 表生成与计算 (IEEE 802.3 标准，火山点播官方直传标准)
    var CRC32_TABLE = null;

    function getCrc32Table() {
        var table = new Uint32Array(256);
        for (var i = 0; i < 256; i++) {
            var c = i;
            for (var j = 0; j < 8; j++) {
                c = (c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1);
            }
            table[i] = c >>> 0;
        }
        return table;
    }

    function calculateCRC32(arrayBuffer) {
        if (!CRC32_TABLE) CRC32_TABLE = getCrc32Table();
        var bytes = new Uint8Array(arrayBuffer);
        var crc = 0xFFFFFFFF;
        for (var i = 0; i < bytes.length; i++) {
            crc = (crc >>> 8) ^ CRC32_TABLE[(crc ^ bytes[i]) & 0xFF];
        }
        var finalCrc = ((crc ^ 0xFFFFFFFF) >>> 0);
        // 转为 8 位 16 进制字符串
        var hex = finalCrc.toString(16);
        while (hex.length < 8) {
            hex = '0' + hex;
        }
        return hex;
    }

    $('body').on('click', '#upload-btn', function () {
        $('input[name=file]').trigger('click');
    });

    $('body').on('change', 'input[name=file]', function (e) {
        var file = this.files[0];
        if (!file) return;

        $('#upload-block').addClass('layui-hide');
        $('#upload-progress-block').removeClass('layui-hide');
        element.progress('upload-progress', '5%');

        var reader = new FileReader();
        reader.onload = function (ev) {
            var crc32Hex = '';
            try {
                crc32Hex = calculateCRC32(ev.target.result);
            } catch (err) {
                console.warn('CRC32 calculation error:', err);
            }

            // 1. 获取火山引擎点播直传凭据
            $.ajax({
                type: 'POST',
                url: '/admin/upload/vod/auth',
                data: {
                    file_name: file.name,
                    file_type: 'video'
                },
                dataType: 'json',
                success: function (res) {
                    if (res.code !== 0 && res.code !== 200 && !res.data) {
                        layer.msg(res.msg || '获取上传凭证失败，请检查点播配置');
                        $('#upload-block').removeClass('layui-hide');
                        $('#upload-progress-block').addClass('layui-hide');
                        return;
                    }

                    var authData = res.data;

                    // 使用标准 PUT 直传
                    var uploadHost = authData.upload_host;
                    var storeUri = authData.store_uri;
                    var auth = authData.auth;
                    var sessionKey = authData.session_key;

                    if (!uploadHost || !storeUri) {
                        layer.msg('点播凭证数据异常');
                        $('#upload-block').removeClass('layui-hide');
                        $('#upload-progress-block').addClass('layui-hide');
                        return;
                    }

                    var uploadUrl = 'https://' + uploadHost + '/' + storeUri;

                    var xhr = new XMLHttpRequest();
                    xhr.open('PUT', uploadUrl, true);
                    xhr.setRequestHeader('Authorization', auth);
                    xhr.setRequestHeader('Content-Type', 'application/octet-stream');
                    if (crc32Hex) {
                        xhr.setRequestHeader('Content-CRC32', crc32Hex);
                    }

                    xhr.upload.onprogress = function (event) {
                        if (event.lengthComputable) {
                            var percent = Math.min(95, Math.ceil((event.loaded / event.total) * 90) + 5);
                            element.progress('upload-progress', percent + '%');
                        }
                    };

                    xhr.onload = function () {
                        if (xhr.status >= 200 && xhr.status < 300) {
                            element.progress('upload-progress', '96%');

                            $.ajax({
                                type: 'POST',
                                url: '/admin/upload/vod/commit',
                                data: {
                                    session_key: sessionKey,
                                    title: file.name
                                },
                                dataType: 'json',
                                success: function (commitRes) {
                                    var vid = commitRes.data;
                                    if (!vid) {
                                        layer.msg(commitRes.msg || '确认上传失败');
                                        $('#upload-block').removeClass('layui-hide');
                                        $('#upload-progress-block').addClass('layui-hide');
                                        return;
                                    }

                                    element.progress('upload-progress', '100%');
                                    $('input[name=file_id]').val(vid);
                                    $('#vod-submit').removeAttr('disabled').removeClass('layui-btn-disabled');

                                    $.ajax({
                                        type: 'POST',
                                        url: $('#vod-form').attr('action'),
                                        data: {file_id: vid},
                                        success: function () {
                                            layer.msg('视频上传成功并已关联课时');
                                        }
                                    });
                                },
                                error: function () {
                                    layer.msg('确认上传接口网络异常');
                                    $('#upload-block').removeClass('layui-hide');
                                    $('#upload-progress-block').addClass('layui-hide');
                                }
                            });

                        } else {
                            var errInfo = '';
                            try {
                                var respObj = JSON.parse(xhr.responseText);
                                errInfo = respObj.Message || respObj.message || (respObj.error ? respObj.error.message : xhr.responseText);
                            } catch (e) {
                                errInfo = xhr.statusText || ('HTTP ' + xhr.status);
                            }
                            layer.msg('直传火山点播存储失败: ' + errInfo);
                            $('#upload-block').removeClass('layui-hide');
                            $('#upload-progress-block').addClass('layui-hide');
                        }
                    };

                    xhr.onerror = function () {
                        layer.msg('直传火山存储网络中断，请检查网络连接');
                        $('#upload-block').removeClass('layui-hide');
                        $('#upload-progress-block').addClass('layui-hide');
                    };

                    xhr.send(file);
                },
                error: function () {
                    layer.msg('请求上传凭证失败');
                    $('#upload-block').removeClass('layui-hide');
                    $('#upload-progress-block').addClass('layui-hide');
                }
            });
        };

        reader.readAsArrayBuffer(file);
    });

});
