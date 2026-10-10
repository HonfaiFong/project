<?php

declare(strict_types=1);

namespace Tests\Unit;

use Packages\Identity\AnonymousAuthorProjector;
use Packages\Identity\AnonymousAuthorSnapshotFactory;
use Packages\Identity\AnonymousReferenceFactory;
use Tests\TestCase;

final class AnonymousIdentitySnapshotTest extends TestCase
{
    private AnonymousAuthorSnapshotFactory $factory;
    private AnonymousAuthorProjector $projector;

    protected function setUp(): void
    {
        $references = new AnonymousReferenceFactory(str_repeat('k', 32));
        $this->factory = new AnonymousAuthorSnapshotFactory($references);
        $this->projector = new AnonymousAuthorProjector($references);
    }

    public function testMainProjectionUsesWriteTimeProfileValues(): void
    {
        $snapshot = $this->factory->forMain('123', '写入时昵称', 'avatar-before');
        $stored = $snapshot->toArray();
        $stored['display_name'] = '资料修改后的昵称';
        $stored['avatar_key'] = 'avatar-after';

        $projected = $this->projector->project($snapshot, 'post', 'post-1');
        $this->assertSame('写入时昵称', $projected['display_name']);
        $this->assertSame('avatar-before', $projected['avatar_key']);
        $this->assertTrue($projected['profile_accessible']);
    }

    public function testAliasProjectionIsImmutableAndBoundToItsScope(): void
    {
        $snapshot = $this->factory->forAlias('123', 'post', 'post-1', '匿名昵称');
        $projected = $this->projector->project($snapshot, 'post', 'post-1');

        $this->assertSame('匿名昵称', $projected['display_name']);
        $this->assertSame('system:anonymous', $projected['avatar_key']);
        $this->assertNotSame('123', $projected['anonymous_ref']);
        $this->assertArrayNotHasKey('persona_id', $projected);

        $wrongScope = $this->projector->project($snapshot, 'post', 'post-2');
        $this->assertNull($wrongScope['anonymous_ref']);
    }

    public function testAliasReferenceIsStableWithinScopeAndDifferentAcrossScopes(): void
    {
        $postSnapshot = $this->factory->forAlias('123', 'post', 'post-1');
        $samePostSnapshot = $this->factory->forAlias('123', 'post', 'post-1');
        $otherPostSnapshot = $this->factory->forAlias('123', 'post', 'post-2');

        $this->assertSame($postSnapshot->anonymousRef, $samePostSnapshot->anonymousRef);
        $this->assertNotSame($postSnapshot->anonymousRef, $otherPostSnapshot->anonymousRef);
        $this->assertNotSame(
            $this->projector->project($postSnapshot, 'post', 'post-1')['anonymous_ref'],
            $this->projector->project($postSnapshot, 'thread', 'post-1')['anonymous_ref'],
        );
    }

    public function testConversationSnapshotIsReusedAfterCurrentProfileChanges(): void
    {
        $snapshot = $this->factory->forAlias('123', 'conversation', 'conversation-1', 'Before profile edit');
        $first = $this->projector->project($snapshot, 'conversation', 'conversation-1');
        $currentProfile = ['nickname' => 'After profile edit', 'avatar_key' => 'profile-avatar'];
        $second = $this->projector->project($snapshot, 'conversation', 'conversation-1');

        $this->assertSame('Before profile edit', $first['display_name']);
        $this->assertSame($first, $second);
        $this->assertNotSame($currentProfile['nickname'], $second['display_name']);
    }

    public function testUnknownSnapshotCannotReuseAnAnonymousReference(): void
    {
        $unknown = $this->factory->unknown();
        $projected = $this->projector->project([
            'identity_mode' => 0,
            'persona_id' => '123',
            'display_name' => 'Current profile name',
            'anonymous_ref' => 'anon_should-not-be-used',
            'scope_type' => 'post',
            'scope_id' => 'post-1',
        ], 'post', 'post-1');

        $this->assertSame('匿名用户', $projected['display_name']);
        $this->assertNull($projected['anonymous_ref']);
        $this->assertSame(
            $projected,
            $this->projector->project($unknown, 'post', 'post-1'),
        );
    }

    public function testModeParsingDoesNotCoerceNumericStringsOrFloats(): void
    {
        $projected = $this->projector->project([
            'identity_mode' => '1e0',
            'persona_id' => '123',
            'display_name' => 'Must stay anonymous',
        ], 'post', 'post-1');

        $this->assertSame('alias', $projected['identity_mode']);
        $this->assertFalse($projected['profile_accessible']);
        $this->assertNull($projected['anonymous_ref']);
    }

    public function testMainSnapshotWithoutIdAndFractionalModeFailClosed(): void
    {
        $missingId = $this->projector->project([
            'identity_mode' => 1,
            'display_name' => 'Must stay anonymous',
        ], 'post', 'post-1');
       $fractionalMode = $this->projector->project([
           'identity_mode' => 1.5,
           'persona_id' => '123',
           'display_name' => 'Must stay anonymous',
       ], 'post', 'post-1');
        $overflowId = $this->projector->project([
            'identity_mode' => 1,
            'persona_id' => '18446744073709551616',
            'display_name' => 'Must stay anonymous',
        ], 'post', 'post-1');

       $this->assertSame('alias', $missingId['identity_mode']);
       $this->assertFalse($missingId['profile_accessible']);
       $this->assertNull($missingId['anonymous_ref']);
       $this->assertSame('alias', $fractionalMode['identity_mode']);
       $this->assertFalse($fractionalMode['profile_accessible']);
       $this->assertNull($fractionalMode['anonymous_ref']);
        $this->assertSame('alias', $overflowId['identity_mode']);
        $this->assertFalse($overflowId['profile_accessible']);
        $this->assertNull($overflowId['anonymous_ref']);
   }

    public function testAnonymousReferenceKeyMustBeAtLeast32Bytes(): void
    {
        $this->expectException(\RuntimeException::class);
        new AnonymousReferenceFactory(str_repeat('k', 31));
    }
}
