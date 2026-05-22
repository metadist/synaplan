<?php

declare(strict_types=1);

namespace App\Tests\Service;

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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class InboundEmailHandlerServiceTest extends TestCase
{
    private InboundEmailHandlerService $service;
    private InboundEmailHandlerRepository&MockObject $handlerRepository;
    private PromptRepository $promptRepository;
    private AiFacade $aiFacade;
    private ModelConfigService $modelConfigService;
    private EncryptionService&MockObject $encryptionService;
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
            $this->createMock(UserRepository::class),
            $this->aiFacade,
            $this->modelConfigService,
            $this->createMock(RateLimitService::class),
            $this->encryptionService,
            $this->createMock(MailHandlerLogService::class),
            $this->logger
        );
    }

    public function testIsMaskedPasswordPlaceholder(): void
    {
        $this->assertTrue(InboundEmailHandlerService::isMaskedPasswordPlaceholder(''));
        $this->assertTrue(InboundEmailHandlerService::isMaskedPasswordPlaceholder('••••••••'));
        $this->assertFalse(InboundEmailHandlerService::isMaskedPasswordPlaceholder('secret'));
    }

    public function testProcessAllHandlersWithNoHandlers(): void
    {
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
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('buildMailboxServerString');
        $method->setAccessible(true);

        $serverString = $method->invoke($this->service, 'imap.example.com', 993, 'IMAP', 'SSL/TLS');

        $this->assertEquals('{imap.example.com:993/imap/ssl}INBOX', $serverString);
    }

    public function testBuildServerStringWithPOP3(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('buildMailboxServerString');
        $method->setAccessible(true);

        $serverString = $method->invoke($this->service, 'pop3.example.com', 995, 'POP3', 'SSL/TLS');

        $this->assertEquals('{pop3.example.com:995/pop3/ssl}INBOX', $serverString);
    }

    public function testBuildServerStringWithSTARTTLS(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('buildMailboxServerString');
        $method->setAccessible(true);

        $serverString = $method->invoke($this->service, 'imap.example.com', 143, 'IMAP', 'STARTTLS');

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

    /**
     * Regression: 8BIT-encoded bodies were being passed through `imap_8bit()`
     * which is an *encoder* (UTF-8 → quoted-printable), corrupting the text.
     */
    public function testDecodeEmailBodyPassesThrough8BitContent(): void
    {
        $method = $this->getDecodeEmailBodyMethod();

        // Encoding 1 = 8BIT — must be passthrough (same as 7BIT).
        $body = "Hello World\r\nThis is a plain 8-bit body with Umlauts: äöü";
        $decoded = $method->invoke($this->service, $body, 1, null);

        $this->assertSame($body, $decoded);
    }

    public function testDecodeEmailBodyPassesThroughBinaryContent(): void
    {
        $method = $this->getDecodeEmailBodyMethod();

        // Encoding 2 = BINARY — must be passthrough.
        $body = 'Some raw binary-ish text without re-encoding';
        $decoded = $method->invoke($this->service, $body, 2, null);

        $this->assertSame($body, $decoded);
    }

    public function testDecodeEmailBodyDecodesBase64(): void
    {
        $method = $this->getDecodeEmailBodyMethod();

        $body = base64_encode('Hello World');
        $decoded = $method->invoke($this->service, $body, 3, null);

        $this->assertSame('Hello World', $decoded);
    }

    public function testDecodeEmailBodyDecodesQuotedPrintable(): void
    {
        $method = $this->getDecodeEmailBodyMethod();

        $body = 'Hello=20World=0AUmlauts:=20=C3=A4=C3=B6=C3=BC';
        $decoded = $method->invoke($this->service, $body, 4, 'UTF-8');

        $this->assertSame("Hello World\nUmlauts: äöü", $decoded);
    }

    /**
     * Regression: the original code never converted non-UTF-8 charsets,
     * so umlauts from German senders ended up garbled in the AI prompt.
     */
    public function testDecodeEmailBodyConvertsLatin1ToUtf8(): void
    {
        $method = $this->getDecodeEmailBodyMethod();

        $latin1 = mb_convert_encoding('Bestellung über 12€ für Müller', 'ISO-8859-15', 'UTF-8');
        $this->assertIsString($latin1);

        $decoded = $method->invoke($this->service, $latin1, 0, 'iso-8859-15');

        $this->assertSame('Bestellung über 12€ für Müller', $decoded);
    }

    public function testDecodeEmailBodyLeavesUtf8Unchanged(): void
    {
        $method = $this->getDecodeEmailBodyMethod();

        $body = 'Already UTF-8 with emoji 🎉 and ümlauts';
        $decoded = $method->invoke($this->service, $body, 0, 'UTF-8');

        $this->assertSame($body, $decoded);
    }

    public function testDecodeMimeHeaderDecodesEncodedWord(): void
    {
        if (!function_exists('imap_mime_header_decode')) {
            $this->markTestSkipped('IMAP extension is not installed.');
        }

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('decodeMimeHeader');
        $method->setAccessible(true);

        $encoded = '=?UTF-8?B?'.base64_encode('Bestellung Nr. 123 — Müller').'?=';
        $decoded = $method->invoke($this->service, $encoded);

        $this->assertSame('Bestellung Nr. 123 — Müller', $decoded);
    }

    public function testDecodeMimeHeaderHandlesPlainAscii(): void
    {
        if (!function_exists('imap_mime_header_decode')) {
            $this->markTestSkipped('IMAP extension is not installed.');
        }

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('decodeMimeHeader');
        $method->setAccessible(true);

        $this->assertSame(
            'Plain ASCII subject line',
            $method->invoke($this->service, 'Plain ASCII subject line')
        );
    }

    public function testGetMimeTypeReadsTypeAndSubtype(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getMimeType');
        $method->setAccessible(true);

        $textPlain = (object) ['type' => 0, 'subtype' => 'PLAIN'];
        $multipartAlternative = (object) ['type' => 1, 'subtype' => 'ALTERNATIVE'];
        $applicationPdf = (object) ['type' => 3, 'subtype' => 'pdf'];

        $this->assertSame('text/plain', $method->invoke($this->service, $textPlain));
        $this->assertSame('multipart/alternative', $method->invoke($this->service, $multipartAlternative));
        $this->assertSame('application/pdf', $method->invoke($this->service, $applicationPdf));
    }

    public function testIsAttachmentRecognisesAttachmentDisposition(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('isAttachment');
        $method->setAccessible(true);

        $attachment = (object) ['ifdisposition' => 1, 'disposition' => 'ATTACHMENT'];
        $inline = (object) ['ifdisposition' => 1, 'disposition' => 'inline'];
        $noDisposition = (object) ['ifdisposition' => 0];

        $this->assertTrue($method->invoke($this->service, $attachment));
        $this->assertFalse($method->invoke($this->service, $inline));
        $this->assertFalse($method->invoke($this->service, $noDisposition));
    }

    public function testGetCharsetReadsContentTypeParameter(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getCharset');
        $method->setAccessible(true);

        $part = (object) [
            'ifparameters' => 1,
            'parameters' => [
                (object) ['attribute' => 'CHARSET', 'value' => 'ISO-8859-1'],
                (object) ['attribute' => 'name', 'value' => 'whatever.txt'],
            ],
        ];

        $this->assertSame('ISO-8859-1', $method->invoke($this->service, $part));

        $partWithoutCharset = (object) ['ifparameters' => 0, 'parameters' => []];
        $this->assertNull($method->invoke($this->service, $partWithoutCharset));
    }

    private function getDecodeEmailBodyMethod(): \ReflectionMethod
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('decodeEmailBody');
        $method->setAccessible(true);

        return $method;
    }
}
