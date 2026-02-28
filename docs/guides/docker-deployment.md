# Docker Deployment Guide

本指南用于生产环境 Docker 部署与加固，覆盖环境变量、健康检查、
资源限制和故障排查。

## Build and Run

```bash
docker compose build
docker compose up -d
```

应用迁移：

```bash
docker compose exec app php bin/migrate
```

## Production Recommendations

- 使用固定镜像标签，避免漂移版本。
- 通过 CI 构建并推送镜像，不在生产机临时构建。
- 敏感变量通过 secret 管理（不要写入镜像层）。
- 定期轮换 `API_KEY_PEPPER` 与数据库凭据。

## Multi-stage Build and Image Size

当前 `docker/app/Dockerfile` 已使用多阶段构建：

- `composer:2` 阶段提供 composer 二进制
- `php:8.4-fpm-alpine` 运行时阶段承载应用

该策略用于减少运行时镜像内不必要内容，并维持镜像体积目标（<200MB）。

## Health Checks

当前 compose 已提供 healthcheck：

- app: `php -v`
- mysql: `mysqladmin ping`
- pgsql: `pg_isready`
- nginx: `GET /health`

注意：`app` 的 `php -v` 只代表 PHP 进程可运行，不等同业务可用性。
业务健康应以 `nginx -> /health` 以及关键 API 冒烟验证为准。

建议：

1. 发布后先观察健康状态稳定。
2. 健康检查失败时先看容器日志，再看依赖容器。

## Resource Limits and Restart Strategy

- app: `mem_limit=512m`, `cpus=1.0`
- mysql/pgsql: `mem_limit=1g`, `cpus=1.0`, `pids_limit=256`
- nginx: `mem_limit=256m`, `cpus=0.50`, `pids_limit=128`
- 重启策略：`restart: unless-stopped`

## Non-root Runtime

`docker/app/Dockerfile` 已使用非 root 运行：

- 创建 `app` 用户组和用户
- `USER ${APP_USER}`

这可降低容器逃逸后危害面。

## Troubleshooting

### 1) app unhealthy

- 执行：`docker compose logs app`
- 检查：PHP 扩展是否安装齐全，配置是否加载。

### 2) nginx unhealthy

- 执行：`docker compose logs nginx`
- 检查：`/health` 路由是否可达，app 是否先于 nginx 就绪。

### 3) db startup failed

- mysql: `docker compose logs mysql`
- pgsql: `docker compose logs pgsql`
- 检查卷权限、端口占用、密码配置。

### 4) migration failed

- 执行：`docker compose exec app php bin/migrate`
- 检查数据库连接变量与目标库权限。
