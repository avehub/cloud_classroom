<div class="layui-card layui-text">
    <div class="layui-card-header">项目信息</div>
    <div class="layui-card-body">
        <table class="layui-table">
            <colgroup>
                <col width="25%">
                <col>
            </colgroup>
            <tbody>
            <tr>
                <td>项目名称</td>
                <td>{{ app_info.name }}</td>
            </tr>
            <tr>
                <td>项目主页</td>
                <td><a href="{{ app_info.link }}" target="_blank">{{ app_info.link }}</a></td>
            </tr>
            </tbody>
        </table>
    </div>
</div>
