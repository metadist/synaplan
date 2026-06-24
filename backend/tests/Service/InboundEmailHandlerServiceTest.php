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
    private PromptRepository&MockObject $promptRepository;
    private AiFacade&MockObject $aiFacade;
    private ModelConfigService&MockObject $modelConfigService;
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

    /**
     * Regression for issue #985: emails with attachments wrap the body inside
     * a nested `multipart/alternative` under `multipart/mixed`, so the
     * pre-fix top-level-only walker missed text/plain entirely. Pinning the
     * recursive walker against the exact MIME shape from the bug report.
     */
    public function testCollectTextPartsWalksNestedMultipartFromBugReport(): void
    {
        $textPlain = (object) [
            'type' => 0, 'subtype' => 'PLAIN',
            'encoding' => 0,
            'ifdisposition' => 0,
            'ifparameters' => 1,
            'parameters' => [(object) ['attribute' => 'charset', 'value' => 'UTF-8']],
        ];
        $textHtml = (object) [
            'type' => 0, 'subtype' => 'HTML',
            'encoding' => 4, // QUOTED-PRINTABLE
            'ifdisposition' => 0,
            'ifparameters' => 1,
            'parameters' => [(object) ['attribute' => 'charset', 'value' => 'UTF-8']],
        ];
        $alternative = (object) [
            'type' => 1, 'subtype' => 'alternative',
            'ifdisposition' => 0,
            'ifparameters' => 0, 'parameters' => [],
            'parts' => [$textPlain, $textHtml],
        ];
        $pdfAttachment = (object) [
            'type' => 3, 'subtype' => 'pdf',
            'encoding' => 3,
            'ifdisposition' => 1, 'disposition' => 'attachment',
            'ifparameters' => 0, 'parameters' => [],
        ];

        // Sections we expect to be requested. PDF (top-level "2") must NOT
        // be fetched because it is an explicit attachment.
        $bodies = [
            '1.1' => 'Hello from the plain body',
            '1.2' => 'Hello=20from=20the=20HTML=20body',
        ];
        $requested = [];
        $fetchBody = function (string $section) use (&$bodies, &$requested): string {
            $requested[] = $section;

            return $bodies[$section] ?? '';
        };

        $result = $this->invokeCollectTextParts([$alternative, $pdfAttachment], '', $fetchBody);

        $this->assertSame('Hello from the plain body', $result['plain']);
        $this->assertSame('Hello from the HTML body', $result['html']);
        $this->assertNotContains('2', $requested, 'PDF attachment must not be fetched');
    }

    /**
     * Walks plain `multipart/alternative` (no enclosing mixed wrapper) and
     * still returns plain + html for the AI prompt fallback.
     */
    public function testCollectTextPartsHandlesTopLevelAlternative(): void
    {
        $plain = (object) [
            'type' => 0, 'subtype' => 'plain',
            'encoding' => 0,
            'ifdisposition' => 0,
            'ifparameters' => 0, 'parameters' => [],
        ];
        $html = (object) [
            'type' => 0, 'subtype' => 'html',
            'encoding' => 0,
            'ifdisposition' => 0,
            'ifparameters' => 0, 'parameters' => [],
        ];

        $fetchBody = fn (string $section): string => match ($section) {
            '1' => 'plain body',
            '2' => '<p>html body</p>',
            default => '',
        };

        $result = $this->invokeCollectTextParts([$plain, $html], '', $fetchBody);

        $this->assertSame('plain body', $result['plain']);
        $this->assertSame('<p>html body</p>', $result['html']);
    }

    /**
     * `Content-Disposition: attachment; text/plain` (an attached `.txt`
     * file) must NOT be picked up as the body — the actual body text/plain
     * sibling wins.
     */
    public function testCollectTextPartsSkipsAttachedTextPartsInFavourOfBody(): void
    {
        $bodyPlain = (object) [
            'type' => 0, 'subtype' => 'plain',
            'encoding' => 0,
            'ifdisposition' => 0,
            'ifparameters' => 0, 'parameters' => [],
        ];
        $attachedTxt = (object) [
            'type' => 0, 'subtype' => 'plain',
            'encoding' => 0,
            'ifdisposition' => 1, 'disposition' => 'attachment',
            'ifparameters' => 0, 'parameters' => [],
        ];

        $fetchBody = fn (string $section): string => '1' === $section
            ? 'real body content'
            : 'CONTENTS OF ATTACHMENT.TXT';

        $result = $this->invokeCollectTextParts([$bodyPlain, $attachedTxt], '', $fetchBody);

        $this->assertSame('real body content', $result['plain']);
    }

    /**
     * Latin-1-declared parts inside a nested tree must be decoded to UTF-8
     * during the walk so the routing prompt sees readable umlauts.
     */
    public function testCollectTextPartsConvertsCharsetForNestedParts(): void
    {
        $latin1Body = mb_convert_encoding('Bestellung über 12€', 'ISO-8859-15', 'UTF-8');
        $this->assertIsString($latin1Body);

        $textPlain = (object) [
            'type' => 0, 'subtype' => 'PLAIN',
            'encoding' => 0,
            'ifdisposition' => 0,
            'ifparameters' => 1,
            'parameters' => [(object) ['attribute' => 'charset', 'value' => 'iso-8859-15']],
        ];
        $alternative = (object) [
            'type' => 1, 'subtype' => 'alternative',
            'ifdisposition' => 0,
            'ifparameters' => 0, 'parameters' => [],
            'parts' => [$textPlain],
        ];

        $fetchBody = fn (string $section): string => '1.1' === $section ? $latin1Body : '';

        $result = $this->invokeCollectTextParts([$alternative], '', $fetchBody);

        $this->assertSame('Bestellung über 12€', $result['plain']);
    }

    // ────────────────────────────────────────────────────────────────
    // routeEmailToDepartment() — AI routing orchestration
    // ────────────────────────────────────────────────────────────────

    public function testRouteEmailRoutesToValidDepartment(): void
    {
        $handler = $this->createHandlerWithDepartments();
        $this->stubPromptAndModel();

        $this->aiFacade
            ->expects($this->once())
            ->method('chat')
            ->willReturn(['content' => 'sales@example.com']);

        $result = $this->service->routeEmailToDepartment($handler, 'New order inquiry', 'I want to buy...');

        $this->assertSame('sales@example.com', $result);
    }

    public function testRouteEmailAcceptsCaseInsensitiveDepartmentMatch(): void
    {
        $handler = $this->createHandlerWithDepartments();
        $this->stubPromptAndModel();

        $this->aiFacade->method('chat')
            ->willReturn(['content' => 'SUPPORT@EXAMPLE.COM']);

        $result = $this->service->routeEmailToDepartment($handler, 'Help', 'Broken widget');

        $this->assertSame('SUPPORT@EXAMPLE.COM', $result);
    }

    public function testRouteEmailDiscardsWhenAiReturnsDiscard(): void
    {
        $handler = $this->createHandlerWithDepartments();
        $this->stubPromptAndModel();

        $this->aiFacade->method('chat')
            ->willReturn(['content' => 'DISCARD']);

        $result = $this->service->routeEmailToDepartment($handler, 'Newsletter spam', 'Unsubscribe...');

        $this->assertNull($result);
    }

    public function testRouteEmailDiscardsIsCaseInsensitive(): void
    {
        $handler = $this->createHandlerWithDepartments();
        $this->stubPromptAndModel();

        $this->aiFacade->method('chat')
            ->willReturn(['content' => 'discard']);

        $result = $this->service->routeEmailToDepartment($handler, 'Spam', 'Buy pills...');

        $this->assertNull($result);
    }

    public function testRouteEmailFallsBackToDefaultForUnknownAiResponse(): void
    {
        $handler = $this->createHandlerWithDepartments();
        $this->stubPromptAndModel();

        $this->aiFacade->method('chat')
            ->willReturn(['content' => 'nonexistent@example.com']);

        $result = $this->service->routeEmailToDepartment($handler, 'Hello', 'Some email');

        $this->assertSame('support@example.com', $result, 'Should fall back to default department');
    }

    /**
     * LLMs sometimes return verbose answers instead of just an email address.
     * The routing must not silently forward to a wrong address.
     */
    public function testRouteEmailFallsBackToDefaultForVerboseAiResponse(): void
    {
        $handler = $this->createHandlerWithDepartments();
        $this->stubPromptAndModel();

        $this->aiFacade->method('chat')
            ->willReturn(['content' => 'I recommend routing to sales@example.com because it mentions a purchase.']);

        $result = $this->service->routeEmailToDepartment($handler, 'Order', 'I want to buy...');

        $this->assertSame('support@example.com', $result, 'Verbose AI response is not a valid email match');
    }

    public function testRouteEmailFallsBackToDefaultWhenAiThrows(): void
    {
        $handler = $this->createHandlerWithDepartments();
        $this->stubPromptAndModel();

        $this->aiFacade->method('chat')
            ->willThrowException(new \RuntimeException('Provider timeout'));

        $result = $this->service->routeEmailToDepartment($handler, 'Urgent', 'Help!');

        $this->assertSame('support@example.com', $result, 'AI failure must not lose the email');
    }

    public function testRouteEmailFallsBackToDefaultWhenNoPromptFound(): void
    {
        $handler = $this->createHandlerWithDepartments();

        $this->promptRepository->method('findByTopic')->willReturn(null);

        $result = $this->service->routeEmailToDepartment($handler, 'Hello', 'Body');

        $this->assertSame('support@example.com', $result);
    }

    public function testRouteEmailFallsBackToDefaultWhenNoModelConfigured(): void
    {
        $handler = $this->createHandlerWithDepartments();

        $prompt = new \App\Entity\Prompt();
        $prompt->setTopic('tools:mailhandler');
        $prompt->setPrompt('Route this email. Departments: [TARGETLIST]');
        $this->promptRepository->method('findByTopic')->willReturn($prompt);

        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $result = $this->service->routeEmailToDepartment($handler, 'Hello', 'Body');

        $this->assertSame('support@example.com', $result);
    }

    public function testRouteEmailReturnsNullWhenNoDepartments(): void
    {
        $handler = new InboundEmailHandler();
        $handler->setUserId(1);
        $handler->setDepartments([]);

        $result = $this->service->routeEmailToDepartment($handler, 'Hello', 'Body');

        $this->assertNull($result, 'No departments configured → nothing to route to');
    }

    public function testRouteEmailTrimsWhitespaceFromAiResponse(): void
    {
        $handler = $this->createHandlerWithDepartments();
        $this->stubPromptAndModel();

        $this->aiFacade->method('chat')
            ->willReturn(['content' => "  sales@example.com  \n"]);

        $result = $this->service->routeEmailToDepartment($handler, 'Order', 'Buy stuff');

        $this->assertSame('sales@example.com', $result);
    }

    private function createHandlerWithDepartments(): InboundEmailHandler
    {
        $handler = new InboundEmailHandler();
        $handler->setUserId(1);
        $handler->setDepartments([
            ['id' => '1', 'email' => 'sales@example.com', 'rules' => 'Sales inquiries', 'isDefault' => false],
            ['id' => '2', 'email' => 'support@example.com', 'rules' => 'Technical support', 'isDefault' => true],
            ['id' => '3', 'email' => 'info@example.com', 'rules' => 'General questions', 'isDefault' => false],
        ]);

        return $handler;
    }

    /**
     * Stubs the prompt lookup and model config so AI routing can proceed
     * to the actual AiFacade call (which is stubbed separately per test).
     */
    private function stubPromptAndModel(): void
    {
        $prompt = new \App\Entity\Prompt();
        $prompt->setTopic('tools:mailhandler');
        $prompt->setPrompt('Route this email to the correct department. Departments: [TARGETLIST]');
        $this->promptRepository->method('findByTopic')->willReturn($prompt);

        $this->modelConfigService->method('getDefaultModel')->willReturn(42);
        $this->modelConfigService->method('getProviderForModel')->willReturn('openai');
        $this->modelConfigService->method('getModelName')->willReturn('gpt-4');
    }

    // ────────────────────────────────────────────────────────────────
    // Private test helpers (MIME / decode)
    // ────────────────────────────────────────────────────────────────

    /**
     * @param array<int, object>       $parts
     * @param callable(string): string $fetchBody
     *
     * @return array{plain: string, html: string}
     */
    private function invokeCollectTextParts(array $parts, string $sectionPrefix, callable $fetchBody): array
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('collectTextParts');
        $method->setAccessible(true);

        /** @var array{plain: string, html: string} $result */
        $result = $method->invoke($this->service, $parts, $sectionPrefix, $fetchBody);

        return $result;
    }

    private function getDecodeEmailBodyMethod(): \ReflectionMethod
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('decodeEmailBody');
        $method->setAccessible(true);

        return $method;
    }
}
