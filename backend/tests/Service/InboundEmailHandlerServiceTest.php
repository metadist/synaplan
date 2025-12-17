<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\AI\Service\AiFacade;
use App\Entity\InboundEmailHandler;
use App\Repository\InboundEmailHandlerRepository;
use App\Repository\PromptRepository;
use App\Service\EncryptionService;
use App\Service\InboundEmailHandlerService;
use App\Service\ModelConfigService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class InboundEmailHandlerServiceTest extends TestCase
{
    private InboundEmailHandlerService $service;
    private InboundEmailHandlerRepository $handlerRepository;
    private PromptRepository $promptRepository;
    private AiFacade $aiFacade;
    private ModelConfigService $modelConfigService;
    private EncryptionService $encryptionService;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->handlerRepository = $this->createMock(InboundEmailHandlerRepository::class);
        $this->promptRepository = $this->createMock(PromptRepository::class);
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->encryptionService = $this->createMock(EncryptionService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new InboundEmailHandlerService(
            $this->handlerRepository,
            $this->promptRepository,
            $this->aiFacade,
            $this->modelConfigService,
            $this->encryptionService,
            $this->logger
        );
    }

    public function testProcessAllHandlersWithNoHandlers(): void
    {
        // @phpstan-ignore-next-line (PHPUnit mock method)
        $this->handlerRepository
            ->expects($this->once())
            ->method('findHandlersToCheck')
            ->willReturn([]);

        $result = $this->service->processAllHandlers();

        $this->assertEmpty($result);
    }

    public function testTestConnectionFailsWithInvalidCredentials(): void
    {
        $handler = new InboundEmailHandler();
        $handler->setMailServer('invalid.example.com');
        $handler->setPort(993);
        $handler->setProtocol('IMAP');
        $handler->setSecurity('SSL/TLS');
        $handler->setUsername('test@example.com');

        // Set encrypted password
        $reflection = new \ReflectionClass($handler);
        $property = $reflection->getProperty('password');
        $property->setAccessible(true);
        $property->setValue($handler, 'encrypted-password');

        // @phpstan-ignore-next-line (PHPUnit mock method)
        $this->encryptionService
            ->expects($this->once())
            ->method('decrypt')
            ->with('encrypted-password')
            ->willReturn('test-password');

        // Will fail to connect (expected in unit test)
        $result = $this->service->testConnection($handler);

        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertFalse($result['success']); // Should fail with invalid server
    }

    public function testBuildServerStringWithIMAP(): void
    {
        $handler = new InboundEmailHandler();
        $handler->setMailServer('imap.example.com');
        $handler->setPort(993);
        $handler->setProtocol('IMAP');
        $handler->setSecurity('SSL/TLS');

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('buildServerString');
        $method->setAccessible(true);

        $serverString = $method->invoke($this->service, $handler);

        $this->assertEquals('{imap.example.com:993/imap/ssl}INBOX', $serverString);
    }

    public function testBuildServerStringWithPOP3(): void
    {
        $handler = new InboundEmailHandler();
        $handler->setMailServer('pop3.example.com');
        $handler->setPort(995);
        $handler->setProtocol('POP3');
        $handler->setSecurity('SSL/TLS');

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('buildServerString');
        $method->setAccessible(true);

        $serverString = $method->invoke($this->service, $handler);

        $this->assertEquals('{pop3.example.com:995/pop3/ssl}INBOX', $serverString);
    }

    public function testBuildServerStringWithSTARTTLS(): void
    {
        $handler = new InboundEmailHandler();
        $handler->setMailServer('imap.example.com');
        $handler->setPort(143);
        $handler->setProtocol('IMAP');
        $handler->setSecurity('STARTTLS');

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('buildServerString');
        $method->setAccessible(true);

        $serverString = $method->invoke($this->service, $handler);

        $this->assertEquals('{imap.example.com:143/imap/tls}INBOX', $serverString);
    }

    public function testGetDefaultDepartment(): void
    {
        $departments = [
            ['email' => 'sales@example.com', 'isDefault' => false],
            ['email' => 'support@example.com', 'isDefault' => true],
            ['email' => 'info@example.com', 'isDefault' => false],
        ];

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getDefaultDepartment');
        $method->setAccessible(true);

        $defaultEmail = $method->invoke($this->service, $departments);

        $this->assertEquals('support@example.com', $defaultEmail);
    }

    public function testGetDefaultDepartmentWhenNoDefaultSet(): void
    {
        $departments = [
            ['email' => 'sales@example.com', 'isDefault' => false],
            ['email' => 'support@example.com', 'isDefault' => false],
        ];

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getDefaultDepartment');
        $method->setAccessible(true);

        $defaultEmail = $method->invoke($this->service, $departments);

        // Should return first department when no default is set
        $this->assertEquals('sales@example.com', $defaultEmail);
    }

    public function testIsValidDepartmentEmail(): void
    {
        $departments = [
            ['email' => 'sales@example.com'],
            ['email' => 'support@example.com'],
        ];

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('isValidDepartmentEmail');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($this->service, 'sales@example.com', $departments));
        $this->assertTrue($method->invoke($this->service, 'SUPPORT@example.com', $departments)); // Case insensitive
        $this->assertFalse($method->invoke($this->service, 'unknown@example.com', $departments));
    }

    public function testBuildTargetList(): void
    {
        $departments = [
            [
                'email' => 'sales@example.com',
                'rules' => 'Handle sales inquiries',
                'isDefault' => false,
            ],
            [
                'email' => 'support@example.com',
                'rules' => 'Handle support tickets',
                'isDefault' => true,
            ],
        ];

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('buildTargetList');
        $method->setAccessible(true);

        $targetList = $method->invoke($this->service, $departments);

        $this->assertStringContainsString('sales@example.com', $targetList);
        $this->assertStringContainsString('support@example.com', $targetList);
        $this->assertStringContainsString('Handle sales inquiries', $targetList);
        $this->assertStringContainsString('Handle support tickets', $targetList);
        $this->assertStringContainsString('Default: yes', $targetList);
        $this->assertStringContainsString('Default: no', $targetList);
    }
}
