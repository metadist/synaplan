<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\InboundEmail;

use App\AI\Service\AiFacade;
use App\Entity\InboundEmailHandler;
use App\Repository\InboundEmailHandlerRepository;
use App\Repository\PromptRepository;
use App\Repository\UserRepository;
use App\Service\EncryptionService;
use App\Service\InboundEmailHandlerService;
use App\Service\MailHandlerLogService;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class InboundEmailDepartmentAgentTest extends TestCase
{
    public function testReadsAgentIdFromMatchingDepartment(): void
    {
        $handler = new InboundEmailHandler();
        $handler->setDepartments([
            ['email' => 'legal@acme.com', 'rules' => 'contracts', 'isDefault' => false, 'agentId' => 12],
            ['email' => 'info@acme.com', 'rules' => 'other', 'isDefault' => true],
        ]);

        $service = new InboundEmailHandlerService(
            $this->createMock(InboundEmailHandlerRepository::class),
            $this->createMock(PromptRepository::class),
            $this->createMock(UserRepository::class),
            $this->createMock(AiFacade::class),
            $this->createMock(ModelConfigService::class),
            $this->createMock(RateLimitService::class),
            $this->createMock(EncryptionService::class),
            $this->createMock(MailHandlerLogService::class),
            $this->createMock(LoggerInterface::class),
        );

        self::assertSame(12, $service->departmentAgentId($handler, 'legal@acme.com'));
        self::assertNull($service->departmentAgentId($handler, 'info@acme.com'));
        self::assertNull($service->departmentAgentId($handler, 'nobody@acme.com'));
    }
}
