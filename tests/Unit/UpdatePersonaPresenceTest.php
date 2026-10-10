<?php

declare(strict_types=1);

namespace Tests\Unit;

use Contracts\Gen\User\V1\UpdatePersonaRequest;
use Tests\TestCase;

/**
 * 固定 Persona PATCH 流程依赖的 proto3 `optional` 字段存在性语义：网关客户端须省略未指定字段，不能转换为空字符串，
 * 因为服务端将字段存在视为用户要求修改，会用空字符串覆盖已有值。
 *
 * 回归背景：UserServiceClient::updatePersona() 此前发送 `'nickname' => $nickname ?? ''`，会清空 PATCH 请求体未包
 * 含的所有字段。
 */
final class UpdatePersonaPresenceTest extends TestCase
{
    public function testOmittedFieldsHaveNoPresence(): void
    {
        $request = new UpdatePersonaRequest([
            'persona_id' => 30001,
            'user_id'    => 20001,
        ]);

        $this->assertFalse($request->hasNickname());
        $this->assertFalse($request->hasAvatarKey());
        $this->assertFalse($request->hasBio());
    }

    public function testSetFieldsHavePresenceEvenWhenEmptyString(): void
    {
        $request = new UpdatePersonaRequest([
            'persona_id' => 30001,
            'user_id'    => 20001,
            'nickname'   => '',
        ]);

        $this->assertTrue($request->hasNickname());
        $this->assertSame('', $request->getNickname());
        $this->assertFalse($request->hasAvatarKey());
        $this->assertFalse($request->hasBio());
    }
}
