<?php

namespace App\Tests\Unit\Entity;

use App\Entity\InboundEmailHandler;
use App\Service\EncryptionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class InboundEmailHandlerTest extends TestCase
{
    private EncryptionService $encryptionService;

    protected function setUp(): void
    {
        // Use a test secret key with NullLogger
        $this->encryptionService = new EncryptionService(
            'test-secret-key-32-characters!!',
            new NullLogger()
        );
    }

    public function testSetAndGetDecryptedPassword(): void
    {
        $handler = new InboundEmailHandler();
        $plainPassword = 'my-secret-password';

        $handler->setDecryptedPassword($plainPassword, $this->encryptionService);

        // Password should be encrypted in entity
        $this->assertNotEquals($plainPassword, $handler->getPassword());
        $this->assertGreaterThan(20, strlen($handler->getPassword()));

        // Should be able to decrypt
        $decrypted = $this->encryptionService->decrypt($handler->getPassword());
        $this->assertEquals($plainPassword, $decrypted);
    }

    public function testSetAndGetSmtpCredentials(): void
    {
        $handler = new InboundEmailHandler();
        
        $handler->setSmtpCredentials(
            'smtp.gmail.com',
            587,
            'user@example.com',
            'smtp-password-123',
            $this->encryptionService,
            'STARTTLS'
        );

        $this->assertTrue($handler->hasSmtpCredentials());

        $credentials = $handler->getSmtpCredentials($this->encryptionService);

        $this->assertEquals('smtp.gmail.com', $credentials['server']);
        $this->assertEquals(587, $credentials['port']);
        $this->assertEquals('user@example.com', $credentials['username']);
        $this->assertEquals('smtp-password-123', $credentials['password']); // Decrypted
        $this->assertEquals('STARTTLS', $credentials['security']);
    }

    public function testEmailFilterConfiguration(): void
    {
        $handler = new InboundEmailHandler();

        // Test default (new mode)
        $this->assertEquals('new', $handler->getEmailFilter()['mode']);
        $this->assertFalse($handler->shouldProcessHistoricalEmails());

        // Test historical mode
        $handler->setEmailFilter('historical', '2025-01-01T00:00', '2025-12-31T23:59');
        
        $filter = $handler->getEmailFilter();
        $this->assertEquals('historical', $filter['mode']);
        $this->assertEquals('2025-01-01T00:00', $filter['from_date']);
        $this->assertEquals('2025-12-31T23:59', $filter['to_date']);
        $this->assertTrue($handler->shouldProcessHistoricalEmails());

        // Test back to new mode
        $handler->setEmailFilter('new', null, null);
        $this->assertEquals('new', $handler->getEmailFilter()['mode']);
        $this->assertFalse($handler->shouldProcessHistoricalEmails());
    }

    public function testEmptyPasswordHandling(): void
    {
        $handler = new InboundEmailHandler();
        
        $handler->setDecryptedPassword('', $this->encryptionService);
        $this->assertEquals('', $handler->getPassword());

        $handler->setSmtpCredentials(
            'smtp.test.com',
            587,
            'user@test.com',
            '', // Empty password
            $this->encryptionService
        );

        $credentials = $handler->getSmtpCredentials($this->encryptionService);
        $this->assertEquals('', $credentials['password']);
    }
}

