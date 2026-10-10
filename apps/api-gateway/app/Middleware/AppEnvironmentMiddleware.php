<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Middleware;

use Contracts\Errors\ErrorCode;
use RuntimeException;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 将路由限制在开发环境。
 *
 * 手机号（短信）登录是仅限开发环境的能力：生产环境始终把本中间件保留在
 * 路由管线中，因此该路由无法通过运行时开关或请求载荷重新启用。环境在
 * Webman 加载 config/gateway.php 时解析一次并由容器注入；本中间件绝不会
 * 在每个请求里读取环境变量或配置文件。凡不是 development 的一律按生产
 * 处理并拒绝该路由。
 */
final readonly class AppEnvironmentMiddleware implements MiddlewareInterface
{
    /**
     * @param string $environment 'development'（开发）或 'production'（生产）
     */
    public function __construct(private string $environment = 'production')
    {
    }

    public function process(Request $request, callable $handler): Response
    {
        if ($this->environment !== 'development') {
            throw new RuntimeException(
                ErrorCode::AUTH_UNAUTHORIZED->message(),
                ErrorCode::AUTH_UNAUTHORIZED->value
            );
        }

        return $handler($request);
    }
}
