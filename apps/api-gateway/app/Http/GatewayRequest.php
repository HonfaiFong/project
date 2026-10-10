<?php

declare(strict_types=1);

namespace Apps\ApiGateway\Http;

use Exception;
use Packages\Tracing\TraceContext;
use Webman\Http\Request;

final class GatewayRequest extends Request
{
    public ?TraceContext $traceContext = null;

    /** @var array{user_id: string, device_id: string, requested_persona_id: ?string} */
    public array $authContext = [
        'user_id' => '',
        'device_id' => '',
        'requested_persona_id' => null,
    ];

    public function bearerToken(): ?string
    {
        $authorization = trim((string)$this->header('authorization', ''));
        if (!str_starts_with($authorization, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($authorization, 7));

        return $token === '' ? null : $token;
    }

    /**
     * @return array<string, mixed>
     * @throws Exception If the secure random source is unavailable.
     */
    public function loginDevice(): array
    {
        $device = (array)$this->post('device', []);
        if (empty($device['device_uuid'])) {
            $device['device_uuid'] = $this->header('x-device-id') ?: 'device-' . bin2hex(random_bytes(8));
        }
        $device['platform'] = $device['platform'] ?? $this->header('x-platform', 'unknown');
        $device['app_version'] = $device['app_version'] ?? $this->header('x-app-version', '1.0.0');

        return $device;
    }
}
