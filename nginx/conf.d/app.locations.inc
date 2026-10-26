# 站点公共配置：由 default.conf 在 HTTP / HTTPS server 块内 include。
# 注意：本文件不以 .conf 结尾，避免被 nginx.conf 的 conf.d/*.conf 自动加载。

set $base_dir /var/www;

root $base_dir/html/ctc/public;
index index.php index.html index.htm;
charset utf-8;

access_log /var/log/nginx/ctc.access.log;
error_log /var/log/nginx/ctc.error.log;

client_max_body_size 100M;
fastcgi_read_timeout 600;
fastcgi_send_timeout 600;

add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "SAMEORIGIN" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;

location / {
    try_files $uri $uri/ /index.php?_url=$uri&$args;
}

location ^~ /storage/upload/ {
    alias $base_dir/html/ctc/storage/upload/;
    expires max;
    log_not_found off;
    access_log off;
}

# WebSocket 反向代理（wss://域名/wss -> php 容器 GatewayWorker:8282）
location /wss {
    proxy_pass http://php:8282;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_read_timeout 3600s;
    proxy_send_timeout 3600s;
}

location ~ \.php$ {
    include /etc/nginx/fastcgi_params;

    fastcgi_pass php:9000;
    fastcgi_index /index.php;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_param HTTPS $ctc_https;
    fastcgi_param SERVER_PORT $server_port;
    fastcgi_param HTTP_X_FORWARDED_FOR $http_x_forwarded_for;
    fastcgi_param HTTP_X_REAL_IP $http_x_real_ip;
    fastcgi_param HTTP_HOST $http_host;
}

location ~ /\.(ht|git) {
    deny all;
    return 403;
}

location ~* \.(js|css|png|jpg|jpeg|webp|gif|ico)$ {
    expires max;
    log_not_found off;
    access_log off;
}
