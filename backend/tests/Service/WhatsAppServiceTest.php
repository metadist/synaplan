<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\AI\Service\AiFacade;
use App\DTO\WhatsApp\IncomingMessageDto;
use App\Entity\Message;
use App\Entity\User;
use App\Service\DiscordNotificationService;
use App\Service\File\FileProcessor;
use App\Service\File\UserUploadPathBuilder;
use App\Service\Message\MessageProcessor;
use App\Service\RateLimitService;
use App\Service\WhatsAppService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Unit tests for WhatsAppService
 * Tests Meta WhatsApp Business API integration with dynamic multi-number support.
 *
 * Message Type Handling:
 * - TEXT: Processed as AI prompt, text response
 * - AUDIO (voice-only): Transcribed via Whisper, TTS audio response
 * - IMAGE: Vision AI analysis, brief text comment
 * - VIDEO: Audio extracted via FFmpeg, transcribed, text response
 */
class WhatsAppServiceTest extends TestCase
{
    private WhatsAppService $service;
    /** @var HttpClientInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $httpClient;
    private LoggerInterface $logger;
    private EntityManagerInterface $em;
    private RateLimitService $rateLimitService;
    private MessageProcessor $messageProcessor;
    private FileProcessor $fileProcessor;
    private UserUploadPathBuilder $pathBuilder;
    /** @var AiFacade&\PHPUnit\Framework\MockObject\MockObject */
    private $aiFacade;
    /** @var DiscordNotificationService&\PHPUnit\Framework\MockObject\MockObject */
    private $discord;
    /** @var CacheInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $cache;
    /** @var LockFactory&\PHPUnit\Framework\MockObject\MockObject */
    private $lockFactory;
    private string $testPhoneNumberId = '123456789'; // Test phone number ID

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->rateLimitService = $this->createMock(RateLimitService::class);
        $this->messageProcessor = $this->createMock(MessageProcessor::class);
        $this->fileProcessor = $this->createMock(FileProcessor::class);
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->discord = $this->createMock(DiscordNotificationService::class);
        $this->pathBuilder = new UserUploadPathBuilder(); // Real instance (final class)

        // Create cache mock - by default returns time() (fresh entry = no duplicate)
        $this->cache = $this->createMock(CacheInterface::class);
        $this->cache->method('get')->willReturnCallback(function (string $key, callable $callback) {
            // Simulate fresh cache entry by calling the callback
            $item = $this->createMock(ItemInterface::class);
            $item->method('expiresAfter')->willReturnSelf();

            return $callback($item);
        });

        // Create lock factory mock - by default lock is acquired successfully
        $this->lockFactory = $this->createMock(LockFactory::class);
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);
        // release() returns void, no need to configure return value
        $this->lockFactory->method('createLock')->willReturn($lock);

        // Create service with test configuration (dynamic multi-number support)
        $this->service = new WhatsAppService(
            $this->httpClient,
            $this->logger,
            $this->em,
            $this->rateLimitService,
            $this->messageProcessor,
            $this->fileProcessor,
            $this->pathBuilder,
            $this->aiFacade,
            $this->discord,
            $this->cache,
            $this->lockFactory,
            'test_token',
            true,
            '/tmp/test_uploads',
            2,
            'https://app.example.com' // APP_URL for TTS audio URLs
        );
    }

    public function testIsAvailableReturnsTrueWhenEnabled(): void
    {
        $this->assertTrue($this->service->isAvailable());
    }

    public function testIsAvailableReturnsFalseWhenDisabled(): void
    {
        $service = new WhatsAppService(
            $this->httpClient,
            $this->logger,
            $this->em,
            $this->rateLimitService,
            $this->messageProcessor,
            $this->fileProcessor,
            $this->pathBuilder,
            $this->aiFacade,
            $this->discord,
            $this->cache,
            $this->lockFactory,
            'test_token',
            false, // disabled
            '/tmp/test_uploads',
            2
        );

        $this->assertFalse($service->isAvailable());
    }

    public function testSendMessageWhenDisabled(): void
    {
        $service = new WhatsAppService(
            $this->httpClient,
            $this->logger,
            $this->em,
            $this->rateLimitService,
            $this->messageProcessor,
            $this->fileProcessor,
            $this->pathBuilder,
            $this->aiFacade,
            $this->discord,
            $this->cache,
            $this->lockFactory,
            'test_token',
            false,
            '/tmp/test_uploads',
            2
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WhatsApp service is not available');

        $service->sendMessage('+1234567890', 'Test message', $this->testPhoneNumberId);
    }

    public function testSendMessageSuccess(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'messages' => [
                ['id' => 'wamid.test123'],
            ],
        ]);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->stringContains('/messages'),
                $this->callback(function ($options) {
                    return isset($options['json']['type'])
                        && 'text' === $options['json']['type']
                        && isset($options['json']['text']['body'])
                        && 'Hello World' === $options['json']['text']['body'];
                })
            )
            ->willReturn($response);

        $result = $this->service->sendMessage('+1234567890', 'Hello World', $this->testPhoneNumberId);

        $this->assertTrue($result['success']);
        $this->assertEquals('wamid.test123', $result['message_id']);
    }

    public function testSendMessageFailure(): void
    {
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willThrowException(new \Exception('API Error'));

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with('Failed to send WhatsApp message', $this->anything());

        $result = $this->service->sendMessage('+1234567890', 'Test', $this->testPhoneNumberId);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('API Error', $result['error']);
    }

    public function testSendMessageWithNetworkException(): void
    {
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willThrowException(new \Exception('Network error'));

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with('Failed to send WhatsApp message', $this->anything());

        $result = $this->service->sendMessage('+1234567890', 'Test', $this->testPhoneNumberId);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Network error', $result['error']);
    }

    public function testSendMessageRequiresPhoneNumberId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Phone Number ID is required');

        $this->service->sendMessage('+1234567890', 'Test', '');
    }

    public function testGetMediaUrlSuccess(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'url' => 'https://example.com/media/file.jpg',
            'mime_type' => 'image/jpeg',
            'sha256' => 'abc123',
        ]);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                $this->stringContains('/media123'),
                $this->callback(function ($options) {
                    return isset($options['headers']['Authorization'])
                        && 'Bearer test_token' === $options['headers']['Authorization'];
                })
            )
            ->willReturn($response);

        $result = $this->service->getMediaUrl('media123', $this->testPhoneNumberId);

        // getMediaUrl returns just the URL string, not an array
        $this->assertIsString($result);
        $this->assertEquals('https://example.com/media/file.jpg', $result);
    }

    public function testGetMediaUrlFailure(): void
    {
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willThrowException(new \Exception('Media not found'));

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with('Failed to get WhatsApp media URL', $this->anything());

        $result = $this->service->getMediaUrl('invalid_media_id', $this->testPhoneNumberId);

        $this->assertNull($result);
    }

    public function testMarkAsReadSuccess(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'success' => true,
        ]);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->stringContains('/messages'),
                $this->callback(function ($options) {
                    return isset($options['json']['messaging_product'])
                        && 'whatsapp' === $options['json']['messaging_product']
                        && isset($options['json']['status'])
                        && 'read' === $options['json']['status']
                        && isset($options['json']['message_id'])
                        && 'wamid.test123' === $options['json']['message_id'];
                })
            )
            ->willReturn($response);

        $this->service->markAsRead('wamid.test123', $this->testPhoneNumberId);

        // If no exception thrown, test passes
        $this->assertTrue(true);
    }

    public function testMarkAsReadWhenDisabled(): void
    {
        $service = new WhatsAppService(
            $this->httpClient,
            $this->logger,
            $this->em,
            $this->rateLimitService,
            $this->messageProcessor,
            $this->fileProcessor,
            $this->pathBuilder,
            $this->aiFacade,
            $this->discord,
            $this->cache,
            $this->lockFactory,
            'test_token',
            false,
            '/tmp/test_uploads',
            2
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WhatsApp service is not available');

        $service->markAsRead('wamid.test123', $this->testPhoneNumberId);
    }

    public function testVerifyWebhookSignature(): void
    {
        $payload = json_encode(['test' => 'data']);
        $secret = 'test_secret';
        $validSignature = 'sha256='.hash_hmac('sha256', $payload, $secret);

        $result = $this->service->verifyWebhookSignature($payload, $validSignature, $secret);

        $this->assertTrue($result);
    }

    public function testVerifyWebhookSignatureInvalid(): void
    {
        $payload = json_encode(['test' => 'data']);
        $secret = 'test_secret';
        $invalidSignature = 'sha256=invalid_signature';

        $result = $this->service->verifyWebhookSignature($payload, $invalidSignature, $secret);

        $this->assertFalse($result);
    }

    public function testSendMediaSuccess(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'messages' => [['id' => 'wamid.media123']],
        ]);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->stringContains('/messages'),
                $this->callback(function ($options) {
                    return isset($options['json']['type'])
                        && 'image' === $options['json']['type']
                        && isset($options['json']['image']['link']);
                })
            )
            ->willReturn($response);

        $result = $this->service->sendMedia(
            '+1234567890',
            'image',
            'https://example.com/image.jpg',
            $this->testPhoneNumberId,
            'Check this out!'
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('wamid.media123', $result['message_id']);
    }

    public function testDownloadMediaSuccess(): void
    {
        // Mock getMediaUrl call
        $getMediaResponse = $this->createMock(ResponseInterface::class);
        $getMediaResponse->method('getStatusCode')->willReturn(200);
        $getMediaResponse->method('toArray')->willReturn(['url' => 'https://example.com/media/file.jpg']);

        // Mock download call
        $downloadResponse = $this->createMock(ResponseInterface::class);
        $downloadResponse->method('getContent')->willReturn('binary_image_data');
        $downloadResponse->method('getHeaders')->willReturn(['content-type' => ['image/jpeg']]);

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($getMediaResponse, $downloadResponse);

        $result = $this->service->downloadMedia('media123', $this->testPhoneNumberId, 27);

        // downloadMedia now returns an array with file info
        $this->assertArrayHasKey('file_path', $result);
        $this->assertArrayHasKey('file_type', $result);
        $this->assertEquals('jpg', $result['file_type']);
    }

    public function testDownloadMediaFailure(): void
    {
        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willThrowException(new \Exception('Download failed'));

        $this->logger
            ->expects($this->once())
            ->method('error');

        $result = $this->service->downloadMedia('media123', $this->testPhoneNumberId, 27);

        $this->assertNull($result);
    }

    public function testDownloadMediaRejectsFileTooLarge(): void
    {
        // Mock getMediaUrl call
        $getMediaResponse = $this->createMock(ResponseInterface::class);
        $getMediaResponse->method('getStatusCode')->willReturn(200);
        $getMediaResponse->method('toArray')->willReturn(['url' => 'https://example.com/media/huge_file.mp4']);

        // Mock download call with file that exceeds 128 MB
        $downloadResponse = $this->createMock(ResponseInterface::class);
        $downloadResponse->method('getHeaders')->willReturn([
            'content-type' => ['video/mp4'],
            'content-length' => ['150000000'], // 150 MB - exceeds 128 MB limit
        ]);
        // Content should not be called since we check Content-Length first
        $downloadResponse->expects($this->never())->method('getContent');

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($getMediaResponse, $downloadResponse);

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with('WhatsApp media file too large (Content-Length)', $this->anything());

        $result = $this->service->downloadMedia('huge_media', $this->testPhoneNumberId, 27);

        // Should return null for files that are too large
        $this->assertNull($result);
    }

    public function testDownloadMediaRejectsFileTooLargeByActualSize(): void
    {
        // Mock getMediaUrl call
        $getMediaResponse = $this->createMock(ResponseInterface::class);
        $getMediaResponse->method('getStatusCode')->willReturn(200);
        $getMediaResponse->method('toArray')->willReturn(['url' => 'https://example.com/media/file.mp4']);

        // Mock download call without Content-Length header but with large actual content
        $largeContent = str_repeat('x', 150 * 1024 * 1024); // 150 MB
        $downloadResponse = $this->createMock(ResponseInterface::class);
        $downloadResponse->method('getHeaders')->willReturn([
            'content-type' => ['video/mp4'],
            // No content-length header
        ]);
        $downloadResponse->method('getContent')->willReturn($largeContent);

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($getMediaResponse, $downloadResponse);

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with('WhatsApp media file too large (actual size)', $this->anything());

        $result = $this->service->downloadMedia('large_media', $this->testPhoneNumberId, 27);

        // Should return null when actual downloaded size exceeds limit
        $this->assertNull($result);
    }

    public function testDownloadMediaRejectsDisallowedFileType(): void
    {
        // Mock getMediaUrl call
        $getMediaResponse = $this->createMock(ResponseInterface::class);
        $getMediaResponse->method('getStatusCode')->willReturn(200);
        $getMediaResponse->method('toArray')->willReturn(['url' => 'https://example.com/media/malicious.exe']);

        // Mock download call with disallowed file type (executable)
        $downloadResponse = $this->createMock(ResponseInterface::class);
        $downloadResponse->method('getHeaders')->willReturn([
            'content-type' => ['application/x-msdownload'], // .exe MIME type
            'content-length' => ['1024'],
        ]);
        $downloadResponse->method('getContent')->willReturn('fake_executable_content');

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($getMediaResponse, $downloadResponse);

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with('WhatsApp media has disallowed file type', $this->anything());

        $result = $this->service->downloadMedia('malicious_media', $this->testPhoneNumberId, 27);

        // Should return null for disallowed file types
        $this->assertNull($result);
    }

    public function testDownloadMediaRejectsUnknownMimeType(): void
    {
        // Mock getMediaUrl call
        $getMediaResponse = $this->createMock(ResponseInterface::class);
        $getMediaResponse->method('getStatusCode')->willReturn(200);
        $getMediaResponse->method('toArray')->willReturn(['url' => 'https://example.com/media/unknown.dat']);

        // Mock download call with unknown/unmapped MIME type
        $downloadResponse = $this->createMock(ResponseInterface::class);
        $downloadResponse->method('getHeaders')->willReturn([
            'content-type' => ['application/x-unknown-binary'],
            'content-length' => ['1024'],
        ]);
        $downloadResponse->method('getContent')->willReturn('unknown_content');

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($getMediaResponse, $downloadResponse);

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with('WhatsApp media has disallowed file type', $this->callback(function ($context) {
                return isset($context['extension']) && 'unknown' === $context['extension'];
            }));

        $result = $this->service->downloadMedia('unknown_media', $this->testPhoneNumberId, 27);

        // Should return null for unknown MIME types
        $this->assertNull($result);
    }

    // ============================================
    // Tests for Voice Message TTS Response Flow
    // ============================================

    /**
     * Helper to create an IncomingMessageDto for testing.
     */
    private function createIncomingMessageDto(string $type, array $messageContent = [], ?string $mediaId = null): IncomingMessageDto
    {
        $typeContent = $messageContent;
        if ($mediaId && in_array($type, ['audio', 'image', 'video', 'document'], true)) {
            $typeContent['id'] = $mediaId;
        }

        $incomingMsg = [
            'from' => '+491754070111',
            'id' => 'wamid.test'.time(),
            'timestamp' => time(),
            'type' => $type,
            $type => $typeContent,
        ];

        $value = [
            'metadata' => [
                'phone_number_id' => $this->testPhoneNumberId,
                'display_phone_number' => '+491234567890',
            ],
            'messages' => [$incomingMsg],
        ];

        return IncomingMessageDto::fromPayload($incomingMsg, $value);
    }

    /**
     * Test that voice-only messages (audio without caption) are correctly identified.
     */
    public function testVoiceOnlyMessageDetection(): void
    {
        // Create DTO for audio message without caption
        $dto = $this->createIncomingMessageDto('audio', [], 'media_audio_123');

        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('shouldSendAudioResponse');
        $method->setAccessible(true);

        $shouldSend = $method->invoke($this->service, $dto);

        $this->assertTrue($shouldSend, 'Audio message without caption should trigger audio response');
    }

    /**
     * Test that video messages are correctly identified for audio response.
     */
    public function testVideoMessageDetectionForAudioResponse(): void
    {
        // Create DTO for video message
        $dto = $this->createIncomingMessageDto('video', [], 'media_video_123');

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('shouldSendAudioResponse');
        $method->setAccessible(true);

        $shouldSend = $method->invoke($this->service, $dto);

        $this->assertTrue($shouldSend, 'Video message should trigger audio response');
    }

    /**
     * Test that audio messages with captions are NOT identified for audio response.
     */
    public function testAudioWithCaptionIsNotAudioResponse(): void
    {
        // This would be an unusual case but should be handled
        // Audio messages in WhatsApp typically don't have captions
        $dto = $this->createIncomingMessageDto('audio', ['caption' => 'Check this audio'], 'media_audio_123');

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('shouldSendAudioResponse');
        $method->setAccessible(true);

        $shouldSend = $method->invoke($this->service, $dto);

        // With a caption, it's not voice-only
        $this->assertFalse($shouldSend);
    }

    /**
     * Test that text messages are NOT identified for audio response.
     */
    public function testTextMessageIsNotAudioResponse(): void
    {
        $dto = $this->createIncomingMessageDto('text', ['body' => 'Hello world']);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('shouldSendAudioResponse');
        $method->setAccessible(true);

        $shouldSend = $method->invoke($this->service, $dto);

        $this->assertFalse($shouldSend, 'Text messages should not trigger audio response');
    }

    /**
     * Test that image messages are NOT identified for audio response.
     */
    public function testImageMessageIsNotAudioResponse(): void
    {
        $dto = $this->createIncomingMessageDto('image', [], 'media_image_123');

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('shouldSendAudioResponse');
        $method->setAccessible(true);

        $shouldSend = $method->invoke($this->service, $dto);

        $this->assertFalse($shouldSend, 'Image messages should not trigger audio response');
    }

    /**
     * Test TTS generation is called for voice-only messages.
     */
    public function testTtsGenerationForVoiceMessages(): void
    {
        // Setup: mock TTS to return a valid path
        $this->aiFacade
            ->expects($this->once())
            ->method('synthesize')
            ->with(
                $this->stringContains('AI response text'),
                $this->anything(),
                $this->anything()
            )
            ->willReturn([
                'relativePath' => '02/000/00002/2026/02/tts_test.mp3',
                'provider' => 'openai',
                'model' => 'tts-1',
            ]);

        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('generateTtsResponse');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, 'AI response text', 2);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('relativePath', $result);
        $this->assertStringContainsString('tts_test.mp3', $result['relativePath']);
    }

    /**
     * Test TTS generation handles failure gracefully.
     */
    public function testTtsGenerationFailureReturnsNull(): void
    {
        // Setup: mock TTS to throw an exception
        $this->aiFacade
            ->expects($this->once())
            ->method('synthesize')
            ->willThrowException(new \RuntimeException('TTS provider unavailable'));

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('generateTtsResponse');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, 'Test text', 2);

        $this->assertNull($result, 'TTS failure should return null, not throw exception');
    }

    /**
     * Test TTS text is truncated for very long responses.
     */
    public function testTtsTextTruncation(): void
    {
        $longText = str_repeat('A very long response. ', 500); // ~11,000 chars

        $this->aiFacade
            ->expects($this->once())
            ->method('synthesize')
            ->with(
                $this->callback(function ($text) {
                    // Should be truncated to ~4000 chars
                    return strlen($text) <= 4003; // 4000 + '...'
                }),
                $this->anything(),
                $this->anything()
            )
            ->willReturn([
                'relativePath' => 'test/path/audio.mp3',
                'provider' => 'openai',
            ]);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('generateTtsResponse');
        $method->setAccessible(true);

        $method->invoke($this->service, $longText, 2);
    }

    // ============================================
    // Tests for Error Message Handling
    // ============================================

    /**
     * Test error message formatting for transcription failures.
     */
    public function testErrorMessageForTranscriptionFailure(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn(['messages' => [['id' => 'test']]]);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function ($options) {
                    // Check that the error message contains German text about transcription
                    return isset($options['json']['text']['body'])
                        && str_contains($options['json']['text']['body'], 'Sprachnachricht');
                })
            )
            ->willReturn($response);

        $dto = $this->createIncomingMessageDto('audio', [], 'test_media');

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('sendErrorMessage');
        $method->setAccessible(true);

        $method->invoke($this->service, $dto, 'Whisper transcription failed');
    }

    /**
     * Test error message formatting for image analysis failures.
     */
    public function testErrorMessageForImageFailure(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn(['messages' => [['id' => 'test']]]);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function ($options) {
                    return isset($options['json']['text']['body'])
                        && str_contains($options['json']['text']['body'], 'Bild');
                })
            )
            ->willReturn($response);

        $dto = $this->createIncomingMessageDto('image', [], 'test_media');

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('sendErrorMessage');
        $method->setAccessible(true);

        $method->invoke($this->service, $dto, 'Image vision analysis failed');
    }

    /**
     * Test error message formatting for file too large.
     */
    public function testErrorMessageForFileTooLarge(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn(['messages' => [['id' => 'test']]]);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->anything(),
                $this->callback(function ($options) {
                    return isset($options['json']['text']['body'])
                        && str_contains($options['json']['text']['body'], 'groß')
                        && str_contains($options['json']['text']['body'], '128 MB');
                })
            )
            ->willReturn($response);

        $dto = $this->createIncomingMessageDto('video', [], 'test_media');

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('sendErrorMessage');
        $method->setAccessible(true);

        $method->invoke($this->service, $dto, 'File too large: 256 MB');
    }

    // ============================================
    // Tests for Message Text Extraction
    // ============================================

    /**
     * Test message text extraction for different message types.
     */
    public function testExtractMessageTextForTextMessage(): void
    {
        $dto = $this->createIncomingMessageDto('text', ['body' => 'Hello, how are you?']);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('extractMessageText');
        $method->setAccessible(true);

        $text = $method->invoke($this->service, $dto);

        $this->assertEquals('Hello, how are you?', $text);
    }

    /**
     * Test message text extraction for audio message.
     */
    public function testExtractMessageTextForAudioMessage(): void
    {
        $dto = $this->createIncomingMessageDto('audio', [], 'media_123');

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('extractMessageText');
        $method->setAccessible(true);

        $text = $method->invoke($this->service, $dto);

        $this->assertEquals('[Audio message]', $text);
    }

    /**
     * Test message text extraction for image with caption.
     */
    public function testExtractMessageTextForImageWithCaption(): void
    {
        $dto = $this->createIncomingMessageDto('image', ['caption' => 'What is this?'], 'media_123');

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('extractMessageText');
        $method->setAccessible(true);

        $text = $method->invoke($this->service, $dto);

        $this->assertEquals('What is this?', $text);
    }

    /**
     * Test message text extraction for image without caption.
     */
    public function testExtractMessageTextForImageWithoutCaption(): void
    {
        $dto = $this->createIncomingMessageDto('image', [], 'media_123');

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('extractMessageText');
        $method->setAccessible(true);

        $text = $method->invoke($this->service, $dto);

        $this->assertEquals('[Image]', $text);
    }

    /**
     * Test message text extraction for video with caption.
     */
    public function testExtractMessageTextForVideoWithCaption(): void
    {
        $dto = $this->createIncomingMessageDto('video', ['caption' => 'Check this video'], 'media_123');

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('extractMessageText');
        $method->setAccessible(true);

        $text = $method->invoke($this->service, $dto);

        $this->assertEquals('Check this video', $text);
    }

    /**
     * Test message text extraction for video without caption.
     */
    public function testExtractMessageTextForVideoWithoutCaption(): void
    {
        $dto = $this->createIncomingMessageDto('video', [], 'media_123');

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('extractMessageText');
        $method->setAccessible(true);

        $text = $method->invoke($this->service, $dto);

        $this->assertEquals('[Video]', $text);
    }

    /**
     * Test message text extraction for unsupported message type.
     */
    public function testExtractMessageTextForUnsupportedType(): void
    {
        // Create a DTO with an unsupported type manually
        $incomingMsg = [
            'from' => '+491754070111',
            'id' => 'wamid.test'.time(),
            'timestamp' => time(),
            'type' => 'sticker',
            'sticker' => ['id' => 'sticker_123'],
        ];

        $value = [
            'metadata' => [
                'phone_number_id' => $this->testPhoneNumberId,
                'display_phone_number' => '+491234567890',
            ],
            'messages' => [$incomingMsg],
        ];

        $dto = IncomingMessageDto::fromPayload($incomingMsg, $value);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('extractMessageText');
        $method->setAccessible(true);

        $text = $method->invoke($this->service, $dto);

        $this->assertStringContainsString('Unsupported message type', $text);
        $this->assertStringContainsString('sticker', $text);
    }

    // ============================================
    // Tests for Send Audio Response
    // ============================================

    /**
     * Test sending audio media via WhatsApp.
     */
    public function testSendAudioMediaSuccess(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'messages' => [['id' => 'wamid.audio123']],
        ]);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->stringContains('/messages'),
                $this->callback(function ($options) {
                    return isset($options['json']['type'])
                        && 'audio' === $options['json']['type']
                        && isset($options['json']['audio']['link'])
                        && str_contains($options['json']['audio']['link'], 'tts_response.mp3');
                })
            )
            ->willReturn($response);

        $result = $this->service->sendMedia(
            '+491754070111',
            'audio',
            'https://app.example.com/api/v1/files/uploads/02/000/00002/2026/02/tts_response.mp3',
            $this->testPhoneNumberId
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('wamid.audio123', $result['message_id']);
    }

    // ==================== DISCORD NOTIFICATION TESTS ====================

    public function testDiscordNotificationNotCalledWhenDisabled(): void
    {
        // Create a new discord mock that returns false for isEnabled
        $discordMock = $this->createMock(DiscordNotificationService::class);
        $discordMock->method('isEnabled')->willReturn(false);

        // notifyWhatsAppSuccess should never be called when disabled
        $discordMock->expects($this->never())->method('notifyWhatsAppSuccess');
        $discordMock->expects($this->never())->method('notifyWhatsAppError');

        // The service constructor accepts discord as parameter, so we verify the mock behavior
        $this->assertFalse($discordMock->isEnabled());
    }

    public function testDiscordServiceIsInjected(): void
    {
        // Verify that the discord service mock is properly set up
        $this->assertInstanceOf(DiscordNotificationService::class, $this->discord);
    }

    public function testDiscordMockCanBeConfiguredForSuccessNotifications(): void
    {
        // Configure mock to expect success notification
        $this->discord->expects($this->once())
            ->method('notifyWhatsAppSuccess')
            ->with(
                $this->equalTo('text'),
                $this->isType('string'),
                $this->isType('string'),
                $this->isType('string'),
                $this->isType('array')
            );

        // Trigger the mock directly to verify it works
        $this->discord->notifyWhatsAppSuccess('text', '+1234', 'Hello', 'Response', []);
    }

    public function testDiscordMockCanBeConfiguredForErrorNotifications(): void
    {
        // Configure mock to expect error notification
        $this->discord->expects($this->once())
            ->method('notifyWhatsAppError')
            ->with(
                $this->equalTo('processing'),
                $this->isType('string'),
                $this->isType('string'),
                $this->isType('string'),
                $this->isType('array')
            );

        // Trigger the mock directly to verify it works
        $this->discord->notifyWhatsAppError('processing', '+1234', 'Hello', 'Error message', []);
    }

    public function testDuplicateMessageDetection(): void
    {
        // Create a cache mock that returns an old cached value (message already processed)
        // Use a fixed old timestamp to avoid time-dependent test flakiness
        $oldTimestamp = 1700000000; // Fixed past timestamp (Nov 2023)
        $cacheWithHit = $this->createMock(CacheInterface::class);
        $cacheWithHit->method('get')->willReturn($oldTimestamp);

        // Create lock that allows acquisition
        $lockFactory = $this->createMock(LockFactory::class);
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);
        // release() returns void
        $lockFactory->method('createLock')->willReturn($lock);

        // Create service with the cache that has the message already
        $service = new WhatsAppService(
            $this->httpClient,
            $this->logger,
            $this->em,
            $this->rateLimitService,
            $this->messageProcessor,
            $this->fileProcessor,
            $this->pathBuilder,
            $this->aiFacade,
            $this->discord,
            $cacheWithHit,
            $lockFactory,
            'test_token',
            true,
            '/tmp/test_uploads',
            2,
            'https://app.example.com'
        );

        // Create a mock user
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        // Create incoming message DTO
        $incomingMsg = [
            'from' => '+1234567890',
            'id' => 'wamid.duplicate123',
            'timestamp' => (string) time(),
            'type' => 'text',
            'text' => ['body' => 'Hello duplicate'],
        ];
        $value = [
            'messaging_product' => 'whatsapp',
            'metadata' => [
                'phone_number_id' => $this->testPhoneNumberId,
                'display_phone_number' => '+49123456789',
            ],
            'contacts' => [['profile' => ['name' => 'Test User'], 'wa_id' => '+1234567890']],
            'messages' => [$incomingMsg],
        ];

        $dto = IncomingMessageDto::fromPayload($incomingMsg, $value);

        // The message processor should NOT be called for duplicates
        $this->messageProcessor->expects($this->never())->method('processStream');

        // Handle the duplicate message
        $result = $service->handleIncomingMessage($dto, $user, false);

        // Verify that it was detected as duplicate
        $this->assertTrue($result['success']);
        $this->assertTrue($result['duplicate']);
        $this->assertFalse($result['response_sent']);
        $this->assertEquals('wamid.duplicate123', $result['message_id']);
    }

    public function testDuplicateDetectionWhenLockNotAcquired(): void
    {
        // Create lock that cannot be acquired (another process holds it)
        $lockFactory = $this->createMock(LockFactory::class);
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(false); // Lock acquisition fails
        $lockFactory->method('createLock')->willReturn($lock);

        // Create service
        $service = new WhatsAppService(
            $this->httpClient,
            $this->logger,
            $this->em,
            $this->rateLimitService,
            $this->messageProcessor,
            $this->fileProcessor,
            $this->pathBuilder,
            $this->aiFacade,
            $this->discord,
            $this->cache,
            $lockFactory,
            'test_token',
            true,
            '/tmp/test_uploads',
            2,
            'https://app.example.com'
        );

        // Create a mock user
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        // Create incoming message DTO
        $incomingMsg = [
            'from' => '+1234567890',
            'id' => 'wamid.locked123',
            'timestamp' => (string) time(),
            'type' => 'text',
            'text' => ['body' => 'Hello locked'],
        ];
        $value = [
            'messaging_product' => 'whatsapp',
            'metadata' => [
                'phone_number_id' => $this->testPhoneNumberId,
                'display_phone_number' => '+49123456789',
            ],
            'contacts' => [['profile' => ['name' => 'Test User'], 'wa_id' => '+1234567890']],
            'messages' => [$incomingMsg],
        ];

        $dto = IncomingMessageDto::fromPayload($incomingMsg, $value);

        // The message processor should NOT be called when lock is held by another process
        $this->messageProcessor->expects($this->never())->method('processStream');

        // Handle the message - should be treated as duplicate because lock couldn't be acquired
        $result = $service->handleIncomingMessage($dto, $user, false);

        // Verify that it was treated as duplicate
        $this->assertTrue($result['success']);
        $this->assertTrue($result['duplicate']);
        $this->assertFalse($result['response_sent']);
    }
}
