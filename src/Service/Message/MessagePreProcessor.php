<?php

namespace App\Service\Message;

use App\Entity\Message;
use App\Repository\MessageRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * PreProcessor für eingehende Nachrichten
 * 
 * Tasks:
 * - File Download (von WhatsApp, Upload, etc.)
 * - File Parsing (Tika, Whisper, OCR)
 * - Message Metadata extraction
 */
class MessagePreProcessor
{
    public function __construct(
        private MessageRepository $messageRepository,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $tikaBaseUrl,
        private string $uploadsDir
    ) {}

    /**
     * Prozessiert Message (Files downloaden, parsen, etc.)
     */
    public function process(Message $message, callable $progressCallback = null): Message
    {
        // Only show preprocessing status if there's actually a file to process
        $hasFile = $message->getFile() > 0 && $message->getFilePath();
        
        if ($hasFile) {
            $this->notify($progressCallback, 'preprocessing', 'Processing file...');
            $this->processFile($message);
            $this->notify($progressCallback, 'preprocessing', 'File processing complete.');
        }

        $this->messageRepository->save($message);

        return $message;
    }

    /**
     * File-Processing: Download, Parse, Extract Text
     */
    private function processFile(Message $message): void
    {
        $filePath = $message->getFilePath();
        $fileType = strtolower($message->getFileType());

        // File existiert lokal?
        $fullPath = $this->uploadsDir . '/' . $filePath;
        if (!file_exists($fullPath)) {
            $this->logger->warning("File not found: {$fullPath}");
            return;
        }

        // Parse File mit Tika (für PDFs, DOCX, etc.)
        if (in_array($fileType, ['pdf', 'docx', 'doc', 'xlsx', 'xls', 'pptx', 'txt'])) {
            $text = $this->parseWithTika($fullPath);
            if ($text) {
                $message->setFileText($text);
            }
        }

        // Audio mit Whisper (später)
        // if (in_array($fileType, ['ogg', 'mp3', 'wav', 'm4a'])) {
        //     $text = $this->transcribeWithWhisper($fullPath);
        //     if ($text) {
        //         $message->setFileText($text);
        //     }
        // }

        // Image OCR (später)
        // if (in_array($fileType, ['jpg', 'jpeg', 'png', 'webp'])) {
        //     $text = $this->ocrWithTesseract($fullPath);
        //     if ($text) {
        //         $message->setFileText($text);
        //     }
        // }
    }

    /**
     * Parse File mit Apache Tika
     */
    private function parseWithTika(string $filePath): ?string
    {
        try {
            // Tika Server: PUT /tika
            $response = $this->httpClient->request('PUT', $this->tikaBaseUrl . '/tika', [
                'headers' => [
                    'Accept' => 'text/plain',
                ],
                'body' => fopen($filePath, 'r'),
                'timeout' => 30,
            ]);

            if ($response->getStatusCode() === 200) {
                $text = $response->getContent();
                return trim($text);
            }
        } catch (\Exception $e) {
            $this->logger->error("Tika parsing failed: {$e->getMessage()}");
        }

        return null;
    }

    private function notify(?callable $callback, string $status, string $message): void
    {
        if ($callback) {
            $callback([
                'status' => $status,
                'message' => $message,
                'timestamp' => time(),
            ]);
        }
    }
}

