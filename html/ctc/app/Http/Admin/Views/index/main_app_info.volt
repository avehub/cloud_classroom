
<div class="layui-card layui-text">
    <div class="layui-card-header">应用信息</div>
    <div class="layui-card-body">
        <table class="layui-table">
            <colgroup>
                <col width="25%">
                <col>
            </colgroup>
            <tbody>
            <tr>
                <td>当前版本</td>
                <td><a href="{{ gitee_url ~ '/releases/v' ~ app_info.version }}" target="_blank">{{ app_info.alias }} {{ app_info.version }}</a></td>
            </tr>
            </tbody>
        </table>
    </div>
</div>
