<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\GuestSession;
use PHPUnit\Framework\TestCase;

class GuestSessionTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $session = new GuestSession();

        $this->assertNull($session->getId());
        $this->assertSame(0, $session->getMessageCount());
        $this->assertSame(5, $session->getMaxMessages());
        $this->assertNull($session->getChatId());
        $this->assertNull($session->getIpAddress());
        $this->assertNull($session->getCountry());
        $this->assertSame(5, $session->getRemainingMessages());
        $this->assertFalse($session->isLimitReached());
        $this->assertFalse($session->isExpired());
    }

    public function testSettersAndGetters(): void
    {
        $session = new GuestSession();
        $session->setSessionId('test-session-123')
            ->setMessageCount(3)
            ->setMaxMessages(10)
            ->setChatId(42)
            ->setIpAddress('192.168.1.1')
            ->setCountry('DE');

        $this->assertSame('test-session-123', $session->getSessionId());
        $this->assertSame(3, $session->getMessageCount());
        $this->assertSame(10, $session->getMaxMessages());
        $this->assertSame(42, $session->getChatId());
        $this->assertSame('192.168.1.1', $session->getIpAddress());
        $this->assertSame('DE', $session->getCountry());
    }

    public function testIncrementMessageCount(): void
    {
        $session = new GuestSession();
        $this->assertSame(0, $session->getMessageCount());

        $session->incrementMessageCount();
        $this->assertSame(1, $session->getMessageCount());

        $session->incrementMessageCount();
        $this->assertSame(2, $session->getMessageCount());
    }

    public function testRemainingMessages(): void
    {
        $session = new GuestSession();
        $session->setMaxMessages(5);

        $this->assertSame(5, $session->getRemainingMessages());

        $session->setMessageCount(3);
        $this->assertSame(2, $session->getRemainingMessages());

        $session->setMessageCount(5);
        $this->assertSame(0, $session->getRemainingMessages());

        $session->setMessageCount(7);
        $this->assertSame(0, $session->getRemainingMessages());
    }

    public function testIsLimitReached(): void
    {
        $session = new GuestSession();
        $session->setMaxMessages(5);

        $this->assertFalse($session->isLimitReached());

        $session->setMessageCount(4);
        $this->assertFalse($session->isLimitReached());

        $session->setMessageCount(5);
        $this->assertTrue($session->isLimitReached());

        $session->setMessageCount(6);
        $this->assertTrue($session->isLimitReached());
    }

    public function testIsExpired(): void
    {
        $session = new GuestSession();
        $this->assertFalse($session->isExpired());

        $session->setExpires(time() - 1);
        $this->assertTrue($session->isExpired());

        $session->setExpires(time() + 3600);
        $this->assertFalse($session->isExpired());
    }

    public function testCountryFiltersCloudflareCodes(): void
    {
        $session = new GuestSession();

        $session->setCountry('DE');
        $this->assertSame('DE', $session->getCountry());

        $session->setCountry('us');
        $this->assertSame('US', $session->getCountry());

        $session->setCountry('XX');
        $this->assertNull($session->getCountry());

        $session->setCountry('T1');
        $this->assertNull($session->getCountry());

        $session->setCountry('');
        $this->assertNull($session->getCountry());

        $session->setCountry(null);
        $this->assertNull($session->getCountry());
    }

    public function testFluentInterface(): void
    {
        $session = new GuestSession();

        $this->assertSame($session, $session->setSessionId('abc'));
        $this->assertSame($session, $session->setMessageCount(1));
        $this->assertSame($session, $session->setMaxMessages(10));
        $this->assertSame($session, $session->setChatId(5));
        $this->assertSame($session, $session->setIpAddress('1.2.3.4'));
        $this->assertSame($session, $session->setCountry('FR'));
        $this->assertSame($session, $session->setCreated(time()));
        $this->assertSame($session, $session->setExpires(time()));
        $this->assertSame($session, $session->incrementMessageCount());
    }

    public function testExpiresDefaultIs24Hours(): void
    {
        $before = time();
        $session = new GuestSession();
        $after = time();

        $expectedMin = $before + (24 * 3600);
        $expectedMax = $after + (24 * 3600);

        $this->assertGreaterThanOrEqual($expectedMin, $session->getExpires());
        $this->assertLessThanOrEqual($expectedMax, $session->getExpires());
    }
}
