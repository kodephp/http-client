# 文档目录

适用版本：**2.5.0**

## 目录结构

- [使用指南](USAGE.md) - 详细说明如何使用客户端的所有功能
- [API 文档](API.md) - 完整的 API 参考

## 使用指南

[使用指南](USAGE.md) 提供了关于如何使用 `kode/http-client` 的详细说明，包括：

- 基本用法与请求选项
- 响应处理
- 工厂配置（认证 / 重试 / 缓存 / 限流 / 熔断 / 日志 / 链路追踪）
- 中间件系统与自定义中间件
- 上下文管理
- 并发请求（sendConcurrent / pool）
- 驱动选择
- 错误处理

## API 文档

[API 文档](API.md) 提供了完整的 API 参考，包括：

- `HttpClient` / `Factory` / `TransportOptions` / `Context` / `HttpResponse`
- 9 个中间件：`AuthMiddleware`、`RetryMiddleware`、`CacheMiddleware`、`RateLimitMiddleware`、`CircuitBreakerMiddleware`、`TimeoutMiddleware`、`HeadersMiddleware`、`LoggingMiddleware`、`TracingMiddleware`
- 驱动与异常层次