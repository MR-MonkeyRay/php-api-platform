# ECDICT Plugin

最小可运行示例插件，提供：

- `GET /plugin/ecdict/ping`
- `POST /plugin/ecdict/import`（模拟命令 `ecdict import`）

## plugin.json

见 `plugin.json`，主类为 `EcdictPlugin`（`bootstrap.php`）。

## Migrations

- `migrations/sqlite/001_plugin_ecdict.sql`
- `migrations/mysql/001_plugin_ecdict.sql`
- `migrations/pgsql/001_plugin_ecdict.sql`

## ecdict import

请求示例：

```bash
curl -X POST http://localhost:8080/plugin/ecdict/import \
  -H 'Content-Type: application/json' \
  --data '{"entries":[{"word":"hello","definition":"你好"}]}'
```

返回示例：

```json
{"plugin":"ecdict","command":"import","imported":1}
```
