{% extends 'templates/main.volt' %}

{% block content %}

    {% set audit.req_data = audit.req_data ? audit.req_data : '{}' %}

    <pre class="layui-code" id="kg-code">{{ audit.req_data|e }}</pre>

{% endblock %}

{% block inline_js %}

    <script>

        layui.use(['jquery'], function () {

            var $ = layui.jquery;
            var $code = $('#kg-code');

            try {
                var obj = JSON.parse($code.text());
                var str = JSON.stringify(obj, undefined, 2);

                $code.text(str);
            } catch (e) {
                // Keep the original text when the stored payload is not valid JSON.
            }
        });

    </script>

{% endblock %}