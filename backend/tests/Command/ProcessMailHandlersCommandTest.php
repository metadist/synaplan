<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ProcessMailHandlersCommand;
use App\Service\InboundEmailHandlerService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

class ProcessMailHandlersCommandTest extends TestCase
{
    private CommandTester $commandTester;
    private InboundEmailHandlerService $handlerService;
    private EntityManagerInterface $em;
    private LoggerInterface $logger;
    private LockFactory $lockFactory;

    protected function setUp(): void
    {
        $this->handlerService = $this->createMock(InboundEmailHandlerService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Create real lock factory with temporary directory
        $tempDir = sys_get_temp_dir().'/synaplan-test-locks-'.uniqid();
        @mkdir($tempDir, 0777, true);
        $this->lockFactory = new LockFactory(new FlockStore($tempDir));

        $command = new ProcessMailHandlersCommand(
            $this->handlerService,
            $this->em,
            $this->logger,
            $this->lockFactory
        );

        $application = new Application();
        $application->add($command);

        $this->commandTester = new CommandTester($application->find('app:process-mail-handlers'));
    }

    public function testExecuteWithNoHandlers(): void
    {
        // @phpstan-ignore-next-line (PHPUnit mock method)
        $this->handlerService
            ->expects($this->once())
            ->method('processAllHandlers')
            ->willReturn([]);

        // Flush is not called when there are no handlers
        // @phpstan-ignore-next-line (PHPUnit mock method)
        $this->em
            ->expects($this->never())
            ->method('flush');

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('No active handlers to process', $output);
    }

    public function testExecuteWithSuccessfulHandlers(): void
    {
        // @phpstan-ignore-next-line (PHPUnit mock method)
        $this->handlerService
            ->expects($this->once())
            ->method('processAllHandlers')
            ->willReturn([
                1 => [
                    'success' => true,
                    'processed' => 3,
                    'errors' => [],
                ],
                2 => [
                    'success' => true,
                    'processed' => 0,
                    'errors' => [],
                ],
            ]);

        // @phpstan-ignore-next-line (PHPUnit mock method)
        $this->em
            ->expects($this->once())
            ->method('flush');

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Handler #1', $output);
        $this->assertStringContainsString('Processed 3 email(s)', $output);
        $this->assertStringContainsString('Handler #2', $output);
        $this->assertStringContainsString('No new emails', $output);
    }

    public function testExecuteWithFailedHandler(): void
    {
        // @phpstan-ignore-next-line (PHPUnit mock method)
        $this->handlerService
            ->expects($this->once())
            ->method('processAllHandlers')
            ->willReturn([
                1 => [
                    'success' => false,
                    'processed' => 0,
                    'errors' => ['Connection failed', 'Invalid credentials'],
                ],
            ]);

        // @phpstan-ignore-next-line (PHPUnit mock method)
        $this->em
            ->expects($this->once())
            ->method('flush');

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Handler #1', $output);
        $this->assertStringContainsString('Failed to process', $output);
        $this->assertStringContainsString('Connection failed', $output);
        $this->assertStringContainsString('Invalid credentials', $output);
    }

    public function testExecuteWithPartialErrors(): void
    {
        // @phpstan-ignore-next-line (PHPUnit mock method)
        $this->handlerService
            ->expects($this->once())
            ->method('processAllHandlers')
            ->willReturn([
                1 => [
                    'success' => true,
                    'processed' => 5,
                    'errors' => ['Email 3 failed', 'Email 7 failed'],
                ],
            ]);

        // @phpstan-ignore-next-line (PHPUnit mock method)
        $this->em
            ->expects($this->once())
            ->method('flush');

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Handler #1', $output);
        $this->assertStringContainsString('5 processed', $output);
        $this->assertStringContainsString('2 failed', $output);
    }

    public function testLockPreventsOverlapping(): void
    {
        // Acquire lock manually to simulate running process
        $lock = $this->lockFactory->createLock('mail-handler-process', 900);
        $lock->acquire();

        // @phpstan-ignore-next-line (PHPUnit mock method)
        $this->handlerService
            ->expects($this->never())
            ->method('processAllHandlers');

        // @phpstan-ignore-next-line (PHPUnit mock method)
        $this->em
            ->expects($this->never())
            ->method('flush');

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Previous mail handler process is still running', $output);
        $this->assertStringContainsString('Skipping this run', $output);

        $lock->release();
    }

    public function testCommandHandlesException(): void
    {
        // @phpstan-ignore-next-line (PHPUnit mock method)
        $this->handlerService
            ->expects($this->once())
            ->method('processAllHandlers')
            ->willThrowException(new \Exception('Database connection failed'));

        // @phpstan-ignore-next-line (PHPUnit mock method)
        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with(
                'Mail handler processing command failed',
                $this->callback(function ($context) {
                    return isset($context['error']) && str_contains($context['error'], 'Database connection');
                })
            );

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Failed to process handlers', $output);
        $this->assertStringContainsString('Database connection failed', $output);
    }
}
