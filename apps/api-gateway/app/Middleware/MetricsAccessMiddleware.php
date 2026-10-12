<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Middleware;

use Symfony\Component\HttpFoundation\IpUtils;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

final readonly class MetricsAccessMiddleware implements MiddlewareInterface
{
    /** @param list<string> $allowedCidrs */
    public function __construct(private array $allowedCidrs = [])
    {
    }

    public function process(Request $request, callable $handler): Response
    {
        // 监控授权只认 socket 对端，转发头不能授予访问权限。
        if (!IpUtils::checkIp($request->getRemoteIp(), $this->allowedCidrs)) {
            return new Response(403, ['Content-Type' => 'text/plain'], "forbidden\n");
        }

        return $handler($request);
    }
}
