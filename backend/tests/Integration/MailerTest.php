<?php

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class MailerTest extends KernelTestCase
{
    public function testEmailCanBeSentInTestEnvironment(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var MailerInterface $mailer */
        $mailer = $container->get(MailerInterface::class);

        $email = (new Email())
            ->from('test@synaplan.com')
            ->to('user@example.com')
            ->subject('Test Email')
            ->text('This is a test email');

        // In test environment with null:// transport, this should not throw
        $mailer->send($email);

        $this->assertTrue(true, 'Email sent successfully in test environment');
    }
}
