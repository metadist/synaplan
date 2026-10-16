<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Credential\UserProviderKeyResolver;
use App\AI\Messages\AppChatCredential;
use App\AI\Messages\DesktopOmittedModel;
use App\Entity\Model;
use App\Entity\User;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use PHPUnit\Framework\TestCase;

final class AppChatCredentialTest extends TestCase
{
    public function testLocalChatModelNeedsNoProviderKey(): void
    {
        $credential = $this->credential($this->model('ollama'), null);

        self::assertSame(AppChatCredential::READY, $credential->forUser($this->user()));
    }

    public function testCustomEndpointNeedsNoProviderKey(): void
    {
        $credential = $this->credential($this->model('OpenAICompatible'), null);

        self::assertSame(AppChatCredential::READY, $credential->forUser($this->user()));
    }

    public function testGroqIsReadyWhenItsOwnKeyResolves(): void
    {
        $keys = $this->createStub(UserProviderKeyResolver::class);
        $keys->method('resolve')->willReturnCallback(
            static function (string $provider, ?int $userId, bool $allowOperator): array {
                TestCase::assertSame('groq', $provider);
                TestCase::assertSame(7, $userId);
                TestCase::assertTrue($allowOperator);

                return ['key' => 'gsk-test', 'source' => 'operator'];
            },
        );

        $credential = $this->credential($this->model('groq'), $keys, allowOperator: true);

        self::assertSame(AppChatCredential::READY, $credential->forUser($this->user()));
    }

    public function testGroqIsMissingWhenAnthropicWouldNotBeAsked(): void
    {
        $keys = $this->createStub(UserProviderKeyResolver::class);
        $keys->method('resolve')->willReturn(null);

        $credential = $this->credential($this->model('groq'), $keys);

        self::assertSame(AppChatCredential::MISSING, $credential->forUser($this->user()));
    }

    public function testNoDefaultChatModelDoesNotClaimAKeyIsMissing(): void
    {
        $credential = $this->credential(null, $this->createStub(UserProviderKeyResolver::class));

        self::assertSame(AppChatCredential::UNSET, $credential->forUser($this->user()));
    }

    private function credential(?Model $model, ?UserProviderKeyResolver $keys, bool $allowOperator = false): AppChatCredential
    {
        $desktop = $this->createStub(DesktopOmittedModel::class);
        $desktop->method('selectedModel')->willReturn($model);

        $config = $this->createStub(MessagesGatewayConfig::class);
        $config->method('allowOperatorKey')->willReturn($allowOperator);

        return new AppChatCredential(
            $desktop,
            $keys ?? $this->createStub(UserProviderKeyResolver::class),
            $config,
        );
    }

    private function model(string $service): Model
    {
        $model = $this->createStub(Model::class);
        $model->method('getService')->willReturn($service);

        return $model;
    }

    private function user(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);

        return $user;
    }
}
