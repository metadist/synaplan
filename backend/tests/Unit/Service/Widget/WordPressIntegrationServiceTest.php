<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Widget;

use App\Entity\User;
use App\Repository\PromptRepository;
use App\Repository\UserRepository;
use App\Service\File\FileProcessor;
use App\Service\File\FileStorageService;
use App\Service\File\VectorizationService;
use App\Service\Prompt\PromptService;
use App\Service\Widget\WidgetService;
use App\Service\Widget\WordPressApiKeyIssuer;
use App\Service\Widget\WordPressIntegrationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WordPressIntegrationServiceTest extends TestCase
{
    public function testExistingAccountWithTheWrongPasswordIsNotDisclosedOrContacted(): void
    {
        $owner = (new User())->setMail('owner@example.com')->setPw(password_hash('correct-horse', PASSWORD_BCRYPT));
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects($this->never())->method('request');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');
        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())->method('findByEmail')->with('owner@example.com')->willReturn($owner);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WordPress site verification failed');

        $this->service($http, $em, $users)->step1VerifyAndCreateUser([
            'email' => 'owner@example.com',
            'password' => 'nope',
            'verification_token' => 'token',
            'verification_url' => 'https://example.com/verify',
            'site_url' => 'https://example.com',
        ]);
    }

    public function testAMissingAccountIsNotCreated(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects($this->never())->method('request');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $users = $this->createMock(UserRepository::class);
        $users->method('findByEmail')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WordPress site verification failed');

        $this->service($http, $em, $users)->step1VerifyAndCreateUser([
            'email' => 'stranger@example.com',
            'password' => 'correct-horse',
            'verification_token' => 'token',
            'verification_url' => 'https://example.com/verify',
            'site_url' => 'https://example.com',
        ]);
    }

    public function testVerificationTargetMustBeTheSamePublicHttpsHost(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects($this->never())->method('request');
        $users = $this->createMock(UserRepository::class);
        $users->expects($this->never())->method('findByEmail');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WordPress site verification failed');

        $this->service($http, $this->createStub(EntityManagerInterface::class), $users)->step1VerifyAndCreateUser([
            'email' => 'owner@example.com',
            'password' => 'correct-horse',
            'verification_token' => 'token',
            'verification_url' => 'https://evil.example/verify',
            'site_url' => 'https://example.com',
        ]);
    }

    public function testPrivateVerificationTargetIsRejected(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects($this->never())->method('request');
        $users = $this->createMock(UserRepository::class);
        $users->expects($this->never())->method('findByEmail');

        $this->expectException(\RuntimeException::class);

        $this->service($http, $this->createStub(EntityManagerInterface::class), $users)->step1VerifyAndCreateUser([
            'email' => 'owner@example.com',
            'password' => 'correct-horse',
            'verification_token' => 'token',
            'verification_url' => 'https://127.0.0.1/verify',
            'site_url' => 'https://127.0.0.1',
        ]);
    }

    private function service(HttpClientInterface $http, EntityManagerInterface $em, UserRepository $users): WordPressIntegrationService
    {
        return new WordPressIntegrationService(
            $http,
            $em,
            $users,
            $this->createStub(WordPressApiKeyIssuer::class),
            $this->createStub(PromptRepository::class),
            $this->createStub(PromptService::class),
            $this->createStub(FileStorageService::class),
            $this->createStub(FileProcessor::class),
            $this->createStub(VectorizationService::class),
            $this->createStub(WidgetService::class),
            $this->createStub(LoggerInterface::class),
        );
    }
}
