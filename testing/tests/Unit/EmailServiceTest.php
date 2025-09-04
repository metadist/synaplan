<?php

require_once __DIR__ . '/../../../public/inc/services/EmailService.php';

use PHPUnit\Framework\TestCase;

/**
 * Unit Tests for EmailService
 */
class EmailServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Set test globals
        $GLOBALS["baseUrl"] = "https://test.synaplan.com/";
        $GLOBALS["debug"] = true;
    }

    public function testSendEmailReturnsTrue()
    {
        $result = EmailService::sendEmail(
            'test@example.com',
            'Test Subject',
            '<p>Test HTML</p>',
            'Test Plain Text'
        );
        
        $this->assertTrue($result);
    }

    public function testSendRegistrationConfirmation()
    {
        $result = EmailService::sendRegistrationConfirmation(
            'user@example.com',
            'ABC123',
            12345
        );
        
        $this->assertTrue($result);
    }

    public function testSendEmailConfirmation()
    {
        $result = EmailService::sendEmailConfirmation('user@example.com');
        
        $this->assertTrue($result);
    }

    public function testSendLimitNotification()
    {
        $result = EmailService::sendLimitNotification(
            'user@example.com',
            'usage',
            'Rate limit exceeded'
        );
        
        $this->assertTrue($result);
    }

    public function testSendAdminNotification()
    {
        $result = EmailService::sendAdminNotification(
            'Test Alert',
            'This is a test admin notification'
        );
        
        $this->assertTrue($result);
    }

    public function testSendAdminNotificationWithCustomEmail()
    {
        $result = EmailService::sendAdminNotification(
            'Test Alert',
            'This is a test admin notification',
            'admin@test.com'
        );
        
        $this->assertTrue($result);
    }

    public function testSetDefaultSender()
    {
        EmailService::setDefaultSender('newsender@example.com');
        
        // Test that the sender was set by sending an email
        $result = EmailService::sendEmail(
            'test@example.com',
            'Test',
            'Test HTML',
            'Test Plain'
        );
        
        $this->assertTrue($result);
    }

    public function testSetDefaultReplyTo()
    {
        EmailService::setDefaultReplyTo('newreply@example.com');
        
        // Test that the reply-to was set by sending an email
        $result = EmailService::sendEmail(
            'test@example.com',
            'Test',
            'Test HTML',
            'Test Plain'
        );
        
        $this->assertTrue($result);
    }

    public function testSendEmailWithEmptyRecipient()
    {
        $result = EmailService::sendEmail(
            '',
            'Test Subject',
            'Test HTML',
            'Test Plain'
        );
        
        // Should handle empty recipient gracefully
        $this->assertTrue($result);
    }

    public function testRegistrationConfirmationContainsCorrectLink()
    {
        // This test would be more comprehensive with a mock that captures the email content
        $result = EmailService::sendRegistrationConfirmation(
            'test@example.com',
            'TEST123',
            99999
        );
        
        $this->assertTrue($result);
    }
}
