# P0 Step 4 执行结果（2026-11-05）

## 结论

**已执行且成功。**

- 触发：2026-11-05 **02:00:11 CST**
- 重启：2026-11-05 **02:03:21 CST**
- Postcheck：2026-11-05 **02:03:48–02:04:19 CST**
- 结果：`Result=success`、`ExecMainStatus=0`
- 当前服务器时间：2026-11-05 10:21 CST，uptime 约 8 小时

## 版本与补丁

| 项目 | 结果 |
|---|---|
| 内核 | `6.8.0-147-generic` |
| `linux-image-generic` | `6.8.0-147.147` |
| Docker | `29.9.3` |
| containerd | `2.4.2` |
| 剩余可升级包 | **0** |
| 剩余 `noble-security` | **0** |
| `needrestart` | 仅显示当前/期望内核一致，无待重启服务 |
| `dpkg --audit` | 无输出 |
| `/var/run/reboot-required` | none |
| failed units | 0 |

说明：`6.8.0-147` 仍不修复 CVE-2026-98432；该 CVE 继续由已持久化的 nftables 规则 + MPTCP 关闭缓解。149 发布后可再安排一次重启。

## 业务验证

- `/health`：200，`{"status":"ok"}`
- `/course/list`：200
- 容器：
  - `ctc-mysql` healthy
  - `ctc-redis` healthy
  - `ctc-nginx` healthy
  - `ctc-php` running
  - `ctc-xunsearch` running（重启后由 `restart: always` 启动）
- Postcheck 日志：`health_ok=1 course_list_http=200 kernel=6.8.0-147-generic`

## 标记与日志

```text
/root/security-change-20261103/step4-patching-done
/root/security-change-20261103/step4-postcheck-ok
/var/log/ctc-p0-maintenance-20261105_020011.log
/var/log/ctc-p0-postcheck-20261105_020348.log
```

维护日志关键点：
- `KERNEL_BEFORE=6.8.0-139-generic`
- `APT UPGRADE (userland; Docker/kernel held)` 完成
- `DOCKER/CONTAINERD` 完成
- `KERNEL INSTALL` 完成
- `REBOOT 2026-11-05T02:03:21+08:00`
- Postcheck 中 `kernel=6.8.0-147-generic`

## SSH / fail2ban

- 当前 SSH 正常（FlClash 关闭后恢复）。
- `ssh.socket`：enabled/active
- `ssh.service`：active（socket 激活，显示 disabled 正常）
- `fail2ban`：enabled/active
- `sshd` jail：当前封禁 0，累计封禁 3

> 之前 `Connection closed by ... port 22` 是当前客户端 FlClash TUN/fake-IP 拦截 SSH；同网络下 GitHub SSH 也失败。关闭 FlClash 后 SSH 恢复，不是服务器 sshd 故障。

## 唯一剩余项

`unattended-upgrades` 尚未安装/配置。维护脚本未包含该项。

如果要启用，需要先确认策略：
- 只装安全更新；
- `Automatic-Reboot "false"`；
- 是否允许自动重启服务；
- 建议仍在维护窗口观察首次运行。

## 备注

- `ctc-xunsearch` 已随重启恢复运行；日志中仍可见既有的 `indexd ... failed to find db by pid` 周期日志，但 searchd/worker 正常启动。
- 这次没有再次轮换凭据；Redis/MySQL 凭据已在 2026-11-04 完成轮换。
