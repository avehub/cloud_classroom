/**
 * @desc 课件资料上传（支持腾讯云COS直传 / 本地存储服务器中转）
 * @link https://cloud.tencent.com/document/product/436/64960
 */

layui.use(['jquery', 'element', 'layer'], function () {

    var $ = layui.jquery;
    var element = layui.element;
    var layer = layui.layer;

    var $uploadBtn = $('#res-upload-btn');
    var $resFile = $('input[name=res_file]');
    var $uploadBlock = $('#res-upload-block');
    var $progressBlock = $('#res-progress-block');
    var courseId = $('input[name=course_id]').val();
    var storageDriver = $('input[name=storage_driver]').val() || 'cos';

    var myConfig = {
        bucket: $('input[name=bucket]').val(),
        region: $('input[name=region]').val(),
    };

    var cos = null;

    if (storageDriver === 'cos') {

        cos = new COS({
            getAuthorization: function (options, callback) {
                $.post('/admin/upload/credentials', {
                    bucket: options.Bucket,
                    region: options.Region,
                }, function (data) {
                    var credentials = data && data.credentials;
                    if (!data || !credentials) {
                        layer.msg('获取临时凭证失败', {icon: 2});
                        return console.error('invalid credentials');
                    }
                    callback({
                        TmpSecretId: credentials.TmpSecretId,
                        TmpSecretKey: credentials.TmpSecretKey,
                        XCosSecurityToken: credentials.Token,
                        ExpiredTime: data.expiredTime,
                        StartTime: data.startTime
                    });
                });
            }
        });
    }

    loadResourceList();

    $uploadBtn.on('click', function () {
        $resFile.trigger('click');
    });

    $resFile.on('change', function (e) {
        var file = this.files[0];

        $uploadBlock.addClass('layui-hide');
        $progressBlock.removeClass('layui-hide');

        if (storageDriver === 'local') {
            uploadResourceByServer(file);
        } else {
            uploadResourceByCos(file);
        }
    });

    /**
     * 本地存储：通过服务器中转上传
     */
    function uploadResourceByServer(file) {

        var formData = new FormData();

        formData.append('file', file);

        $.ajax({
            url: '/admin/upload/resource',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            xhr: function () {
                var xhr = $.ajaxSettings.xhr();
                if (xhr.upload) {
                    xhr.upload.onprogress = function (info) {
                        if (info.lengthComputable) {
                            var percent = Math.ceil(100 * info.loaded / info.total);
                            element.progress('res-upload-progress', percent + '%');
                        }
                    };
                }
                return xhr;
            },
            success: function (res) {
                if (!res || !res.data || !res.data.path) {
                    layer.msg('上传文件失败', {icon: 2});
                    $uploadBlock.removeClass('layui-hide');
                    $progressBlock.addClass('layui-hide');
                    return;
                }
                var data = res.data;
                $.post('/admin/resource/create', {
                    upload: {
                        name: data.name,
                        mime: data.mime,
                        size: data.size,
                        path: data.path,
                        md5: data.md5
                    },
                    course_id: courseId,
                }, function () {
                    $uploadBlock.removeClass('layui-hide');
                    $progressBlock.addClass('layui-hide');
                    loadResourceList();
                });
            },
            error: function (xhr, status, err) {
                layer.msg('上传文件失败', {icon: 2});
                $uploadBlock.removeClass('layui-hide');
                $progressBlock.addClass('layui-hide');
                console.error(err);
            }
        });
    }

    /**
     * 腾讯云COS直传
     */
    function uploadResourceByCos(file) {

        var keyName = getKeyName(file.name);

        cos.putObject({
            ContentDisposition: 'attachment',
            Bucket: myConfig.bucket,
            Region: myConfig.region,
            Key: keyName,
            Body: file,
            onProgress: function (info) {
                if (!isNaN(info.percent)) {
                    var percent = Math.ceil(100 * info.percent);
                    element.progress('res-upload-progress', percent + '%');
                }
            }
        }, function (err, data) {
            if (data && data.statusCode === 200) {
                $.post('/admin/resource/create', {
                    upload: {
                        name: file.name,
                        mime: file.type,
                        size: file.size,
                        path: keyName,
                        md5: data.ETag ? data.ETag.replace(/"/g, '') : ''
                    },
                    course_id: courseId,
                }, function () {
                    $uploadBlock.removeClass('layui-hide');
                    $progressBlock.addClass('layui-hide');
                    loadResourceList();
                });
            }
            console.log(err || data);
        });
    }

    $('body').on('change', '.res-name', function () {
        var url = $(this).data('url');
        $.post(url, {
            name: $(this).val()
        }, function (res) {
            layer.msg(res.msg, {icon: 1});
        });
    });

    $('body').on('click', '.res-btn-delete', function () {
        var url = $(this).data('url');
        layer.confirm('确定要删除吗？', function () {
            $.post(url, function (res) {
                layer.msg(res.msg, {icon: 1});
                loadResourceList();
            });
        });
    });

    function getKeyName(filename) {
        var ext = getFileExtension(filename);
        var date = new Date();
        var name = [
            date.getDate(),
            date.getHours(),
            date.getMinutes(),
            date.getSeconds(),
            Math.round(10000 * Math.random())
        ].join('');
        return '/resource/' + name + '.' + ext;
    }

    function getFileExtension(filename) {
        var index = filename.lastIndexOf('.');
        if (index === -1) return '';
        return filename.slice(index + 1);
    }

    function loadResourceList() {
        var url = $('#res-list').data('url');
        $.get(url, function (html) {
            $('#res-list').html(html);
        });
    }

});
