# Database Operations Guide

本指南覆盖 SQLite、MySQL、PostgreSQL 三种数据库的运维要点、迁移、
备份恢复和常见问题。

## Quick DSN Examples

- SQLite DSN: `sqlite:/var/www/html/var/database/app.sqlite`
- MySQL DSN: `mysql:host=127.0.0.1;port=3306;dbname=app;charset=utf8mb4`
- PostgreSQL DSN: `pgsql:host=127.0.0.1;port=5432;dbname=app`

## SQLite

适用场景：单实例、低并发、开发/测试环境。

推荐配置：

- `DB_CONNECTION=sqlite`
- `DB_PATH=var/database/app.sqlite`

限制与注意：

- 高并发写入会受文件锁影响。
- 备份依赖文件级拷贝，建议停写后备份。
- 需要确保容器内目录可写。

## MySQL

适用场景：中高并发、主从复制、成熟运维体系。

推荐配置：

- `DB_CONNECTION=mysql`
- `DB_HOST=127.0.0.1`
- `DB_PORT=3306`
- `DB_NAME=app`
- `DB_USER=app`
- `DB_PASSWORD=***`
- `DB_CHARSET=utf8mb4`

优化建议：

- 开启慢查询日志并定期分析。
- 给高频过滤字段建立索引。
- 使用独立备份账号与最小权限。

## PostgreSQL

适用场景：复杂查询、事务一致性要求高的场景。

推荐配置：

- `DB_CONNECTION=pgsql`
- `DB_HOST=127.0.0.1`
- `DB_PORT=5432`
- `DB_NAME=app`
- `DB_USER=app`
- `DB_PASSWORD=***`

优化建议：

- 关注 autovacuum 与 bloat。
- 对 JSONB 查询建立 GIN 索引。
- 监控连接池与长事务。

## Migration Commands

应用迁移命令：

```bash
php bin/migrate
```

容器环境下：

```bash
docker compose exec app php bin/migrate
```

建议流程：

1. 发布前在 staging 先执行并验证。
2. 生产迁移前做备份。
3. 迁移后执行健康检查与关键 API 回归。

## Backup & Restore

### SQLite

备份：

```bash
cp var/database/app.sqlite backup/app-$(date +%F).sqlite
```

恢复：

```bash
cp backup/app-2026-02-28.sqlite var/database/app.sqlite
```

### MySQL

备份：

```bash
mysqldump -h 127.0.0.1 -P 3306 -u app -p app > backup/app.sql
```

恢复：

```bash
mysql -h 127.0.0.1 -P 3306 -u app -p app < backup/app.sql
```

### PostgreSQL

备份：

```bash
pg_dump -h 127.0.0.1 -p 5432 -U app -d app > backup/app.sql
```

恢复：

```bash
psql -h 127.0.0.1 -p 5432 -U app -d app < backup/app.sql
```

## Troubleshooting

- 迁移失败（权限不足）：检查数据库账号是否具备 DDL 权限。
- 连接失败（超时）：检查 DB_HOST/DB_PORT 与网络 ACL。
- 字符集异常：MySQL 确认 `utf8mb4`，连接参数带 charset。
- SQLite 文件损坏：优先恢复最近备份，并排查异常断电/磁盘问题。
