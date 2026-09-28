<?php

declare(strict_types=1);

namespace Tests\Unit;

use Contracts\Gen\User\V1\UpdatePersonaRequest;
use Tests\TestCase;

/**
 * Locks the proto3 `optional` presence semantics that the Persona PATCH flow
 * depends on: the gateway client must OMIT unspecified fields (not coerce
 * them to ''), because the server treats presence as "user wants to change
 * this field" and would otherwise overwrite stored values with empty strings.
 *
 * Regression context: UserServiceClient::updatePersona() used to send
 * `'nickname' => $nickname ?? ''`, which cleared every field the PATCH body
 * did not include.
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
