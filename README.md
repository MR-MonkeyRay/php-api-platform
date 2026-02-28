# PHP API Platform（Slim4）

一个基于 Slim 4 的插件化 API 平台，提供策略治理、插件安装与示例插件能力。

## Features

- 插件化 API 扩展：通过 `plugin.json` + `bootstrap.php` 注册 API。
- 策略治理：支持 API 启用/禁用、可见性与作用域控制。
- 插件安装器：支持 Git 仓库校验、下载、依赖处理与回滚。
- 管理能力：支持 API Key 管理与管理端接口。
- 多数据库兼容：SQLite / MySQL / PostgreSQL。

## Quick Start

### 1) Prerequisites

- Docker 24+
- Docker Compose v2+
- Git

### 2) Start services

```bash
git clone <your-repo-url>
cd php-api-platform
cp .env.example .env

docker compose up -d --build
docker compose exec app composer install
docker compose exec app php bin/migrate

curl -i http://127.0.0.1:8080/health
```

### 3) Verify

- `docker compose ps` 显示 `app` 与 `nginx` healthy/running
- `/health` 返回 `200` 且 JSON

## Config

以下变量来自 `.env.example`，也是部署时最常用配置。

| Variable | Example | Description |
| --- | --- | --- |
| `APP_NAME` | `php-api-platform` | 应用名称 |
| `APP_ENV` | `development` | 运行环境 |
| `APP_DEBUG` | `true` | 调试开关 |
| `APP_URL` | `http://localhost:8080` | 对外访问地址 |
| `APP_PORT` | `8080` | Nginx 暴露端口 |
| `TZ` | `UTC` | 时区 |
| `LOG_CHANNEL` | `stderr` | 日志输出通道 |
| `LOG_LEVEL` | `debug` | 日志级别 |
| `LOG_JSON` | `true` | JSON 日志开关 |
| `DB_CONNECTION` | `sqlite` | 数据库类型（sqlite/mysql/pgsql） |
| `DB_PATH` | `var/database/app.sqlite` | SQLite 文件路径 |
| `DB_CHARSET` | `utf8mb4` | 字符集 |
| `ADMIN_USERNAME` | `admin` | 管理员用户名 |
| `ADMIN_PASSWORD_HASH` | `$2y$...` | 管理员 bcrypt 密码哈希 |
| `ADMIN_AUDIT_LOG_FILE` | `var/audit/admin.log` | 管理审计日志路径 |
| `API_KEY_PEPPER` | `change-this...` | API Key Pepper |
| `API_KEY_CACHE_TTL` | `30` | API Key 缓存秒数 |
| `API_KEY_VERSION_FILE` | `var/apikey.version` | API Key 版本文件 |
| `POLICY_DIR` | `var/policy` | 策略快照目录 |
| `DB_HOST` | `127.0.0.1` | MySQL/PG 主机 |
| `DB_PORT` | `3306` | MySQL/PG 端口 |
| `DB_NAME` | `app` | 数据库名 |
| `DB_USER` | `app` | 数据库用户 |
| `DB_PASSWORD` | `app` | 数据库密码 |
| `COMPOSE_PROJECT_NAME` | `php_api_platform` | Compose 项目名 |

## FAQ

### Q1: 启动后容器反复重启怎么办？

先看日志：`docker compose logs -f app nginx`，常见原因是 `.env` 缺失关键变量。

### Q2: 为什么 API 返回 401/403？

检查策略与鉴权：私有 API 需要有效 API Key，且 scope 必须匹配。

### Q3: 插件安装失败如何排查？

先检查仓库白名单、ref 是否固定（tag/commit），再查看 installer 测试与日志。
生产部署的安全加固和 Nginx 限流建议见 `docs/guides/operations.md`。

### Q4: 数据库切换到 MySQL/PG 要改哪些变量？

设置 `DB_CONNECTION` 为 `mysql`/`pgsql`，并同时配置 `DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASSWORD`。

### Q5: README 里的命令能直接跑吗？

除 `git clone` 占位符外，其余命令按当前仓库默认配置可直接执行。

## Contributing

欢迎提交 Issue/PR。建议先运行测试，再提交变更。

## License

GNU Affero General Public License v3.0 (AGPL-3.0)，详见 `LICENSE`。
