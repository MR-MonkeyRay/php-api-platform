# Audit Plugin

最小可运行示例插件，提供：

- `GET /plugin/audit/ping`
- `POST /plugin/audit/clean`（模拟命令 `audit clean`）

## plugin.json

见 `plugin.json`，主类为 `AuditPlugin`（`bootstrap.php`）。

## Migrations

- `migrations/sqlite/001_plugin_audit.sql`
- `migrations/mysql/001_plugin_audit.sql`
- `migrations/pgsql/001_plugin_audit.sql`

## audit clean

请求示例：

```bash
curl -X POST http://localhost:8080/plugin/audit/clean
```

返回示例：

```json
{"plugin":"audit","command":"clean","deleted":3}
```
