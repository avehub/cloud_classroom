# ==============================================================================
# CTC Kubernetes (K8s) 云原生迁移清单与架构设计蓝图
# ==============================================================================

本蓝图为项目未来平滑从 Docker Compose 迁移至 Kubernetes / K3s 集群提供标准设计与资源模板。

## 1. 架构映射关系

| 组件 | Docker Compose 服务 | K8s Workload / 资源类型 | 存储 / 网络策略 |
| :--- | :--- | :--- | :--- |
| **Nginx (网关与静态资源)** | `ctc-nginx` | `Deployment` (2+ Replicas) + `Ingress` | 静态资源可挂载只读共享 PVC 或集成 CDN |
| **PHP-FPM (业务处理)** | `ctc-php` | `Deployment` (无状态水平伸缩 HPA) | 挂载共享 PVC (`storage/upload`) |
| **WebSocket / Worker** | `ctc-php` (内部进程) | 独立 `Deployment` (避免与 Web 抢占资源) | ClusterIP Service 或通过 Ingress 反代 |
| **定时任务 (Cron)** | `cron` (Supervisor 管理) | `CronJob` 或常驻 Worker Pod | 执行 `php console.php` |
| **MySQL (关系型数据库)** | `ctc-mysql` | `StatefulSet` + Local/Ceph/NFS PVC (或云托管 RDS) | 挂载持久卷，配置 Headless Service |
| **Redis (缓存与会话)** | `ctc-redis` | `StatefulSet` + PVC (或云托管 Redis) | ClusterIP Service: 6379 |
| **XunSearch (全文搜索)** | `ctc-xunsearch` | `StatefulSet` + PersistentVolumeClaim | 持久化索引目录 `/usr/local/xunsearch/data` |

---

## 2. K8s 资源定义样例

### 2.1 ConfigMap & Secret (配置解耦)

```yaml
apiVersion: v1
kind: ConfigMap
metadata:
  name: ctc-config
  namespace: ctc
data:
  APP_ENV: "pro"
  APP_TIMEZONE: "Asia/Shanghai"
  APP_BASE_URI: "/"
  APP_STATIC_BASE_URI: "/static/"
  SITE_DOMAIN: "ctc.example.com"
  MYSQL_HOST: "ctc-mysql"
  MYSQL_PORT: "3306"
  MYSQL_DATABASE: "ctc"
  REDIS_HOST: "ctc-redis"
  REDIS_PORT: "6379"
  REDIS_INDEX: "0"
---
apiVersion: v1
kind: Secret
metadata:
  name: ctc-secret
  namespace: ctc
type: Opaque
stringData:
  APP_KEY: "your_random_production_secret_key"
  MYSQL_ROOT_PASSWORD: "secure_root_password"
  MYSQL_PASSWORD: "secure_db_password"
  REDIS_PASSWORD: "secure_redis_password"
  SMS_ACCESS_KEY_ID: "your_aliyun_sms_ak"
  SMS_ACCESS_KEY_SECRET: "your_aliyun_sms_sk"
```

### 2.2 PHP-FPM / Web Deployment (业务无状态部署)

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: ctc-php-web
  namespace: ctc
  labels:
    app: ctc-php-web
spec:
  replicas: 2
  selector:
    matchLabels:
      app: ctc-php-web
  template:
    metadata:
      labels:
        app: ctc-php-web
    spec:
      containers:
        - name: php-fpm
          image: ctc/php:latest
          command: ["php-fpm", "-F"]
          envFrom:
            - configMapRef:
                name: ctc-config
            - secretRef:
                name: ctc-secret
          ports:
            - containerPort: 9000
          volumeMounts:
            - name: storage-upload-pvc
              mountPath: /var/www/html/ctc/storage/upload
          resources:
            requests:
              cpu: 200m
              memory: 256Mi
            limits:
              cpu: 1000m
              memory: 1Gi
          readinessProbe:
            tcpSocket:
              port: 9000
            initialDelaySeconds: 5
            periodSeconds: 10
          livenessProbe:
            tcpSocket:
              port: 9000
            initialDelaySeconds: 15
            periodSeconds: 20
      volumes:
        - name: storage-upload-pvc
          persistentVolumeClaim:
            claimName: ctc-storage-upload-pvc
```

### 2.3 Ingress 路由与 SSL 终止

```yaml
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: ctc-ingress
  namespace: ctc
  annotations:
    kubernetes.io/ingress.class: nginx
    nginx.ingress.kubernetes.io/proxy-body-size: "100m"
    nginx.ingress.kubernetes.io/proxy-read-timeout: "600"
    nginx.ingress.kubernetes.io/proxy-send-timeout: "600"
spec:
  tls:
    - hosts:
        - ctc.example.com
      secretName: ctc-tls-cert
  rules:
    - host: ctc.example.com
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: ctc-nginx-svc
                port:
                  number: 80
          - path: /ws
            pathType: Prefix
            backend:
              service:
                name: ctc-websocket-svc
                port:
                  number: 8282
```
