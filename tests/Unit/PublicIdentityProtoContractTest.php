<?php

declare(strict_types=1);

namespace Tests\Unit;

use Contracts\Gen\Chat\V1\PublicConversation;
use Contracts\Gen\Chat\V1\PublicConversationMember;
use Contracts\Gen\Chat\V1\PublicChatTarget;
use Contracts\Gen\Content\V1\PublicComment;
use Contracts\Gen\Content\V1\PublicPost;
use Contracts\Gen\Discovery\V1\CapsuleItem;
use Contracts\Gen\Feed\V1\FeedItem;
use Contracts\Gen\Message\V1\PublicMessage;
use Contracts\Gen\Message\V1\PublicSendMessageRequest;
use Contracts\Gen\User\V1\PersonaOperation as GeneratedPersonaOperation;
use Packages\Identity\PersonaOperation;
use Tests\TestCase;

final class PublicIdentityProtoContractTest extends TestCase
{
    public function testPublicContentAndMessageDtosAreIndependentGeneratedTypes(): void
    {
        $this->assertTrue(class_exists(PublicPost::class));
        $this->assertTrue(class_exists(PublicComment::class));
        $this->assertTrue(class_exists(PublicConversation::class));
        $this->assertTrue(class_exists(PublicMessage::class));

        $content = file_get_contents(__DIR__ . '/../../contracts/proto/content/v1/content.proto');
        $message = file_get_contents(__DIR__ . '/../../contracts/proto/message/v1/message.proto');
        $this->assertIsString($content);
        $this->assertIsString($message);
        $this->assertStringContainsString('message PublicPost', $content);
        $this->assertStringContainsString('message PublicComment', $content);
        $this->assertStringContainsString('PublicPost post = 1;', $content);
        $this->assertStringContainsString('PublicComment comment = 1;', $content);
        $this->assertStringContainsString('message PublicMessage', $message);
        $this->assertStringContainsString('PublicMessage message = 1;', $message);
    }

    public function testChatTargetAndMessageRequestCannotCarryPeerOrPerMessageIdentity(): void
    {
        $chat = file_get_contents(__DIR__ . '/../../contracts/proto/chat/v1/chat.proto');
        $message = file_get_contents(__DIR__ . '/../../contracts/proto/message/v1/message.proto');
        $this->assertIsString($chat);
        $this->assertIsString($message);
        $this->assertStringContainsString('message PublicChatTarget', $chat);
        $this->assertStringContainsString('main_persona_id = 1;', $chat);
        $this->assertStringContainsString('AnonymousChatTarget anonymous = 2;', $chat);
        $this->assertStringContainsString('reserved 4, 5;', $chat);
        $this->assertStringNotContainsString('peer_user_id', $chat);
        $this->assertStringNotContainsString('peer_persona_id', $chat);
        $this->assertStringContainsString('reserved 8, 9;', $message);
        $this->assertStringNotContainsString('identity_mode = 8', $message);
        $this->assertStringNotContainsString('alias_display_name = 9', $message);
    }

    public function testGeneratedPublicShapesKeepInternalOwnershipOutOfPublicDtos(): void
    {
        foreach ([PublicPost::class, PublicComment::class, PublicConversationMember::class, PublicMessage::class] as $class) {
            $methods = get_class_methods($class);
            $this->assertContains('getAuthor', $methods, $class);
            $this->assertNotContains('getUserId', $methods, $class);
            $this->assertNotContains('getPersonaId', $methods, $class);
            $this->assertNotContains('getSenderUserId', $methods, $class);
            $this->assertNotContains('getSenderPersonaId', $methods, $class);
        }
        $conversationMethods = get_class_methods(PublicConversation::class);
        $this->assertContains('getMembers', $conversationMethods);
        $this->assertNotContains('getUserId', $conversationMethods);
        $this->assertNotContains('getPersonaId', $conversationMethods);

        $requestMethods = get_class_methods(PublicSendMessageRequest::class);
        $this->assertContains('getClientMessageId', $requestMethods);
        $this->assertContains('getConversationId', $requestMethods);
        $this->assertContains('getType', $requestMethods);
        $this->assertContains('getContent', $requestMethods);
        $this->assertNotContains('getIdentityMode', $requestMethods);
        $this->assertNotContains('getAliasDisplayName', $requestMethods);
        $this->assertNotContains('getSenderUserId', $requestMethods);
        $this->assertNotContains('getSenderPersonaId', $requestMethods);

        $targetMethods = get_class_methods(PublicChatTarget::class);
        $this->assertContains('getMainPersonaId', $targetMethods);
        $this->assertContains('getAnonymous', $targetMethods);
        $this->assertContains('getTarget', $targetMethods);

        foreach (PersonaOperation::cases() as $operation) {
            $constant = GeneratedPersonaOperation::class . '::PERSONA_OPERATION_' . $operation->name;
            $this->assertTrue(defined($constant), $constant);
            $this->assertSame($operation->value, constant($constant), $constant);
        }
    }

    public function testNestedFeedAndDiscoveryDtosUsePublicPost(): void
    {
        $publicPost = new PublicPost();

        $feed = new FeedItem();
        $feed->setPost($publicPost);
        $this->assertInstanceOf(PublicPost::class, $feed->getPost());
        $this->assertNotContains('getPersonaId', get_class_methods(FeedItem::class));

        $capsule = new CapsuleItem();
        $capsule->setPost($publicPost);
        $this->assertInstanceOf(PublicPost::class, $capsule->getPost());
        $this->assertNotContains('getPersonaId', get_class_methods(CapsuleItem::class));
    }
}
