# PHP API Platform（Slim4）

一个基于 **Slim 4 + 插件系统 + 策略引擎** 的轻量级 API 平台，
目标是提供可扩展、可部署、可治理的后端服务基座。

## Features

- **插件化架构**：按插件注册 API，支持动态扩展。
- **策略引擎**：运行时控制 API 启用状态、可见性、权限范围。
- **多数据库支持**：统一抽象 SQLite / MySQL / PostgreSQL。
- **API Key 管理**：支持签发、校验、吊销与作用域管理。
- **容器化交付**：基于 Docker Compose 的最小可运行部署。

> 当前仓库按阶段渐进落地，README 的快速开始与配置项以计划文档为基线，
> 后续会随各阶段代码实现同步更新。

## Quick Start

### 1) 环境要求

- Docker 24+
- Docker Compose v2+
- Git

### 2) 启动步骤

```bash
git clone <your-repo-url>
cd php-api-platform
cp .env.example .env

docker compose up -d --build
# 如果你的环境仍使用旧命令：docker-compose up -d --build

docker compose exec app composer install
docker compose exec app php bin/migrate

curl -i http://localhost:8080/health
```

### 3) 预期结果

- `docker compose ps` 中 `app` 与 `nginx` 容器处于 running
- `/health` 返回 200（JSON）
- 首次迁移成功创建 `schema_version` 与核心业务表

## Configuration

以下为核心环境变量（以 `.env.example` 与 `docker-compose.yml` 为准）：

| 变量名 | 示例值 | 说明 |
| --- | --- | --- |
| `APP_ENV` | `development` | 运行环境（如 `development` / `production`） |
| `APP_DEBUG` | `true` | 是否开启调试输出 |
| `APP_PORT` | `8080` | 对外暴露端口 |
| `DB_CONNECTION` | `sqlite` | 数据库类型：`sqlite` / `mysql` / `pgsql` |
| `DB_PATH` | `var/database/app.sqlite` | SQLite 文件路径（仅 `sqlite` 生效） |
| `DB_HOST` | `127.0.0.1` | MySQL / PostgreSQL 主机 |
| `DB_PORT` | `3306` | MySQL / PostgreSQL 端口 |
| `DB_NAME` | `app` | 数据库名 |
| `DB_USER` | `app` | 数据库用户 |
| `DB_PASSWORD` | `app` | 数据库密码 |
| `DB_CHARSET` | `utf8mb4` | MySQL 字符集 |
| `LOG_LEVEL` | `debug` | 日志级别 |
| `LOG_JSON` | `true` | 是否输出 JSON 日志 |
| `TZ` | `UTC` | 容器时区 |

## FAQ

**Q1：`docker compose up` 失败，提示端口占用怎么办？**  
A：修改 `.env` 中端口（如 `APP_PORT=18080`）并重启容器。

**Q2：访问根路径返回 404 JSON 是不是异常？**  
A：不是。最小骨架阶段默认仅保证基础路由（如 `/health`）可用。

**Q3：`bin/migrate` 失败提示数据库连接错误？**  
A：先确认 `DB_CONNECTION` 与对应连接参数是否成对配置（例如 `sqlite` 对应 `DB_PATH`）。

**Q4：如何验证 SQLite 已生效？**  
A：确认 `DB_CONNECTION=sqlite` 且 `DB_PATH` 对应文件可写，执行迁移后应生成库文件。

**Q5：日志在哪里看？**  
A：容器模式下优先看 `docker compose logs -f app`，日志输出为 JSON。

## Contributing

欢迎通过 Issue / PR 参与改进。提交前请至少完成：

1. 对变更范围执行测试或等效验证；
2. 保持文档与代码同步；
3. 参考并遵循 `docs/plans/` 下的阶段计划与验收标准。

## License

本项目采用 **GNU Affero General Public License v3.0 (AGPL-3.0)**，
详见 `LICENSE` 文件。
