<?php

declare(strict_types=1);

namespace Tests\Unit;

use Apps\ApiGateway\Http\GatewayRequest;
use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class GatewayRequestTest extends TestCase
{
    #[DataProvider('authorizationHeaders')]
    public function testBearerTokenPreservesControllerHeaderSemantics(string $header, ?string $expected): void
    {
        $request = new GatewayRequest("POST / HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $request->setHeader('authorization', $header);

        $this->assertSame($expected, $request->bearerToken());
    }

    public static function authorizationHeaders(): array
    {
        return [
            'missing' => ['', null],
            'wrong scheme' => ['Basic token', null],
            'lowercase scheme' => ['bearer token', null],
            'empty token' => ['Bearer ', null],
            'token' => ['Bearer access-token', 'access-token'],
            'whitespace' => ['  Bearer   access-token  ', 'access-token'],
        ];
    }

    /** @throws Exception 安全随机源不可用时抛出。 */
    public function testLoginDevicePrefersBodyValuesAndRetainsAdditionalFields(): void
    {
        $request = new GatewayRequest("POST /?device=query HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $request->setHeader(['x-device-id' => 'header-device', 'x-platform' => 'ios', 'x-app-version' => '2.0']);
        $device = ['device_uuid' => 'body-device', 'platform' => '', 'app_version' => '3.0', 'push_token' => 'push'];
        $request->setPost(['device' => $device]);

        $this->assertSame($device, $request->loginDevice());
    }

    /** @throws Exception 安全随机源不可用时抛出。 */
    public function testLoginDeviceFallsBackToHeadersForEmptyAndNullValues(): void
    {
        $request = new GatewayRequest("POST / HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $request->setHeader(['x-device-id' => 'header-device', 'x-platform' => 'ios', 'x-app-version' => '2.0']);
        $request->setPost(['device' => ['device_uuid' => '0', 'platform' => null, 'app_version' => null]]);

        $this->assertSame([
            'device_uuid' => 'header-device',
            'platform' => 'ios',
            'app_version' => '2.0',
        ], $request->loginDevice());
    }

    /** @throws Exception 安全随机源不可用时抛出。 */
    public function testLoginDeviceGeneratesAnIdAndUsesExistingDefaultsWithoutHeaders(): void
    {
        $request = new GatewayRequest("POST / HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $device = $request->loginDevice();

        $this->assertMatchesRegularExpression('/^device-[a-f0-9]{16}$/', $device['device_uuid']);
        $this->assertSame('unknown', $device['platform']);
        $this->assertSame('1.0.0', $device['app_version']);
    }
}
