# Plugin Installation Security Guide

本指南聚焦插件安装链路的安全基线，适用于生产环境与 CI/CD。
目标是降低供应链攻击、错误升级和回滚失败的风险。

## Security Principles

1. **最小信任面**：仅允许来自可信组织/仓库的插件。
2. **可审计变更**：每次安装必须有固定来源、固定版本、可追溯记录。
3. **失败可回滚**：任一步骤失败都要恢复到安装前状态。
4. **默认保守**：默认拒绝漂移 ref、默认禁用不必要脚本执行。

## Whitelist Configuration

安装前应配置仓库白名单，仅允许可信来源。

推荐环境变量：`PLUGIN_WHITELIST`

```dotenv
# 逗号分隔，支持 owner/repo 或 owner/*
PLUGIN_WHITELIST=trusted-org/*,security-team/audit-plugin,https://github.com/platform/ecdict-plugin
```

策略建议：

- 优先使用 `owner/repo` 精确匹配；
- 仅在必要时使用 `owner/*`；
- 变更白名单需走评审流程并留存记录。

### Integration Note (Important)

`PLUGIN_WHITELIST` 只是配置入口，必须由安装器装配层显式注入
`GitRepositoryValidator`（`whitelistPatterns` + `enforceWhitelist=true`）后才会生效。
如果未接入装配层，白名单策略不会自动启用。

## Fixed ref Policy

必须使用**固定 ref**，禁止漂移分支。

允许：

- 固定语义化标签（如 `v1.2.3`）
- 40 位 commit hash（如 `4f9c...`）

禁止：

- `master` / `main` / `head` / `latest`

原因：固定 ref 可确保部署可重复、可追溯，避免上游仓库被篡改后自动带入生产。

## Why `--no-scripts`

安装依赖时建议强制使用 `--no-scripts`，避免第三方包在安装阶段执行任意脚本。

示例：

```bash
composer install --no-scripts
composer update vendor/plugin-dependency --no-scripts
```

如果业务确实需要脚本：

1. 在隔离环境先审计脚本内容；
2. 完成签名与审批后再在受控环境启用；
3. 启用后保留安装日志与审计记录。

## Dependency Conflict Handling

遇到依赖冲突时，不要直接覆盖线上依赖：

1. 导出当前 `composer.json` 与 `composer.lock` 快照；
2. 在临时环境试算冲突解并记录变更；
3. 评估是否影响已有插件与核心 API；
4. 必要时拆分为分阶段升级。

## Rollback Playbook

当安装验证失败或运行异常时，执行 rollback：

1. 停止当前安装流程，冻结后续变更；
2. 恢复备份的 `composer.json` 与 `composer.lock`；
3. 重新执行 `composer install --no-scripts`；
4. 删除本次下载/安装的插件目录；
5. 重新生成策略快照并校验关键 API；
6. 记录故障原因、影响范围与修复建议。

## Best Practices

- 使用独立机器人账号拉取插件仓库，最小化 token 权限；
- 对插件仓库启用签名 tag 与发布说明审查；
- 建立“安装前检查清单”（whitelist / fixed ref / hash 校验）；
- 生产环境安装窗口应具备回滚负责人和观测面板；
- 定期回放一次“安装失败 + rollback”演练，验证流程可靠性。
