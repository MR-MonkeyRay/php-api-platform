# Operations Guide

本指南用于生产部署后的运维自检，重点覆盖管理端访问控制、审计日志和
基础故障排查。

## Required Environment Variables

以下变量必须在生产环境明确配置：

- `DB_CONNECTION`：数据库类型（sqlite/mysql/pgsql）
- `DB_PATH`：SQLite 文件路径（仅 SQLite 使用）
- `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASSWORD`：
  MySQL/PostgreSQL 连接参数
- `ADMIN_USERNAME`：管理后台用户名
- `ADMIN_PASSWORD_HASH`：管理后台密码哈希（bcrypt）
- `API_KEY_PEPPER`：API Key 哈希 pepper，必须高强度随机值
- `API_KEY_VERSION_FILE`：API Key 版本文件路径
- `POLICY_DIR`：策略快照目录
- `ADMIN_AUDIT_LOG_FILE`：管理端 JSONL 审计日志路径（默认
  `var/audit/admin.log`）

## Security Checklist

上线前建议逐项检查：

1. 管理端仅允许内网或受信 IP 访问（网关 + Nginx 双层限制）。
2. `ADMIN_PASSWORD_HASH` 使用 bcrypt，明文密码长度 ≥ 14。
3. `API_KEY_PEPPER` 与数据库备份分离存储，定期轮转。
4. 审计日志文件权限限制为应用用户可写、其他用户不可读。
5. 每日检查 `api_key.create` / `api_key.revoke` / `policy.upsert`
   是否存在异常频率。
6. 在 WAF/Nginx 对 `/admin` 路径启用速率限制与失败告警。

## Nginx Hardening Example (allowlist + limit_req)

```nginx
limit_req_zone $binary_remote_addr zone=admin_limit:10m rate=5r/m;

server {
    listen 443 ssl;
    server_name api.example.com;

    location /admin/ {
        allow 10.10.0.0/16;
        allow 192.168.0.0/16;
        deny all;

        limit_req zone=admin_limit burst=10 nodelay;

        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Host $host;
        proxy_pass http://php-api-platform;
    }
}
```

## Audit Log Operations

管理端关键操作会写入 JSONL 文件，默认位置：
`var/audit/admin.log`。

单行示例：

```json
{"timestamp":"2026-02-28T03:12:45+00:00","action":"api_key.create","actor":"admin-user","details":{"kid":"ab12..."},"ip":"10.10.1.25"}
```

常用排查命令：

```bash
tail -n 100 var/audit/admin.log
rg '"action":"policy.upsert"' var/audit/admin.log
```

## Common Troubleshooting

- 管理接口返回 401：
  - 检查 Basic Auth 与 API Key 是否同时满足策略。
  - 检查 `ADMIN_USERNAME`、`ADMIN_PASSWORD_HASH` 是否正确加载。
- 管理接口返回 403：
  - 检查 API Key scope 是否包含 `admin`。
- 无法写入审计日志：
  - 检查 `ADMIN_AUDIT_LOG_FILE` 的目录是否存在、权限是否可写。
- 策略更新后未生效：
  - 检查 `POLICY_DIR/snapshot.json` 是否更新。

