# 插件开发最小指南

本文档提供插件开发的最小可运行路径，覆盖接口实现、元数据声明、
路由注册、API 元数据与策略覆盖。

## 1. 插件系统概览

平台会从 `plugins/*` 目录扫描插件，每个插件目录至少包含：

- `plugin.json`（元数据）
- `bootstrap.php`（类加载入口）
- 插件实现类（需实现 `PluginInterface`）

加载后，平台会：

1. 调用插件 `routes()` 完成路由注册；
2. 调用插件 `apis()` 收集 `ApiDefinition` 元数据；
3. 结合数据库策略，生成 `var/policy/snapshot.json` 快照。

## 2. PluginInterface 详解

`PluginInterface` 定义如下能力：

- `getId()`：插件唯一标识
- `getName()`：插件显示名
- `getVersion()`：插件版本
- `routes(App $app)`：注册 routes
- `apis()`：返回 `ApiDefinition` 列表

```php
use App\Core\Plugin\ApiDefinition;
use App\Core\Plugin\PluginInterface;
use Slim\App;

final class DemoPlugin implements PluginInterface
{
    public function getId(): string
    {
        return 'demo-plugin';
    }

    public function getName(): string
    {
        return 'Demo Plugin';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function routes(App $app): void
    {
        $app->get('/api/demo/v1/ping', static function ($request, $response) {
            $response->getBody()->write('{"data":{"pong":true}}');

            return $response->withHeader('Content-Type', 'application/json');
        })->setName('demo:ping:get');
    }

    public function apis(): array
    {
        return [
            new ApiDefinition('demo:ping:get', 'public', []),
        ];
    }
}
```

## 3. plugin.json 规范

`plugin.json` 用于描述插件元数据。

必填字段：

- `id`：小写字母/数字/中划线（如 `demo-plugin`）
- `name`：展示名称
- `version`：语义化版本（如 `1.0.0`）
- `mainClass`：插件主类名

```json
{
  "id": "demo-plugin",
  "name": "Demo Plugin",
  "version": "1.0.0",
  "mainClass": "DemoPlugin"
}
```

## 4. routes 注册建议

在 `routes()` 中仅注册与插件相关的路由，建议：

- 路径使用统一前缀（如 `/api/demo/v1`）
- 路由名与 `ApiDefinition::apiId` 保持一致
- 响应统一为 JSON

```php
use Slim\App;

final class RouteRegistrationExample
{
    public static function register(App $app): void
    {
        $app->post('/api/demo/v1/echo', static function ($request, $response) {
            $response->getBody()->write('{"data":{"ok":true}}');

            return $response->withHeader('Content-Type', 'application/json');
        })->setName('demo:echo:post');
    }
}
```

## 5. ApiDefinition 元数据声明

`ApiDefinition` 用于声明 API 的默认策略信息：

- `apiId`：API 唯一标识（通常等于 route name）
- `visibilityDefault`：`public` 或 `private`
- `requiredScopesDefault`：默认需要的 scopes

```php
use App\Core\Plugin\ApiDefinition;

final class ApiMetadataExample
{
    public static function definitions(): array
    {
        return [
            new ApiDefinition('demo:ping:get', 'public', []),
            new ApiDefinition('demo:admin:get', 'private', ['admin']),
        ];
    }
}
```

## 6. 策略覆盖（Plugin 默认 + DB 覆盖）

策略来源分两层：

1. 插件默认策略（`ApiDefinition`）
2. 数据库策略（`api_policy`）

构建快照时，数据库策略会覆盖插件默认值。

示例：

- 插件默认：`demo:admin:get` 为 `private` + `['admin']`
- DB 可将其改为 `enabled=0` 或修改 scopes

最终以 `var/policy/snapshot.json` 与 `var/policy/version` 为运行时准。

## 7. 最小目录示例

```text
plugins/
└── demo-plugin/
    ├── plugin.json
    ├── bootstrap.php
    ├── README.md
    └── src/
        └── DemoPlugin.php
```

## 8. 开发检查清单

- [ ] `plugin.json` 字段完整且格式正确
- [ ] 主类实现 `PluginInterface`
- [ ] routes 与 `ApiDefinition` 的 `apiId` 对齐
- [ ] 默认策略符合预期（public/private + scopes）
- [ ] 插件可被 `PluginManager` 正常加载
