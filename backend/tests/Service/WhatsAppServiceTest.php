<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\File\FileProcessor;
use App\Service\File\UserUploadPathBuilder;
use App\Service\Message\MessageProcessor;
use App\Service\RateLimitService;
use App\Service\WhatsAppService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Unit tests for WhatsAppService
 * Tests Meta WhatsApp Business API integration with dynamic multi-number support.
 */
class WhatsAppServiceTest extends TestCase
{
    private WhatsAppService $service;
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;
    private EntityManagerInterface $em;
    private RateLimitService $rateLimitService;
    private MessageProcessor $messageProcessor;
    private FileProcessor $fileProcessor;
    private UserUploadPathBuilder $pathBuilder;
    private string $testPhoneNumberId = '123456789'; // Test phone number ID

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->rateLimitService = $this->createMock(RateLimitService::class);
        $this->messageProcessor = $this->createMock(MessageProcessor::class);
        $this->fileProcessor = $this->createMock(FileProcessor::class);
        $this->pathBuilder = new UserUploadPathBuilder(); // Real instance (final class)

        // Create service with test configuration (dynamic multi-number support)
        $this->service = new WhatsAppService(
            $this->httpClient,
            $this->logger,
            $this->em,
            $this->rateLimitService,
            $this->messageProcessor,
            $this->fileProcessor,
            $this->pathBuilder,
            'test_token',
            true,
            '/tmp/test_uploads',
            2
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

        $this->assertIsArray($result);
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

        $this->assertIsArray($result);
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
        $this->assertIsArray($result);
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
}
