<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\MessageRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Serve static uploads (e.g., generated images) with auth check
 * 
 * For AI-generated content - only visible to owner or if chat is shared
 */
#[Route('/api/v1/files/uploads')]
class StaticUploadController extends AbstractController
{
    public function __construct(
        private MessageRepository $messageRepository,
        private string $uploadDir,
        private LoggerInterface $logger
    ) {}

    /**
     * Serve uploaded file by filename
     * 
     * GET /api/v1/files/uploads/{filename}
     * Example: /api/v1/files/uploads/generated_abc123.png
     * 
     * Access control:
     * - Owner can always access
     * - Public if chat is shared
     */
    #[Route('/{filename}', name: 'serve_static_upload', requirements: ['filename' => '[a-zA-Z0-9_\-\.]+'], methods: ['GET'])]
    public function serve(
        string $filename,
        #[CurrentUser] ?User $user,
        Request $request
    ): Response {
        // Check if this is a temporary AI-generated file (TTS, generated images, etc.)
        $isTemporaryAiFile = preg_match('/^(tts_|generated_|ai_)/', $filename);
        
        if ($isTemporaryAiFile) {
            // For temporary AI-generated files: Allow access without strict auth
            // These files are ephemeral (not stored in DB) and auto-deleted
            // Browser <audio> and <video> tags can't send Authorization headers
            
            $this->logger->info('StaticUploadController: Serving temporary AI-generated file', [
                'filename' => $filename,
                'user_id' => $user?->getId(),
                'has_auth' => $user !== null
            ]);
            
            // Skip message/permission check - serve directly
            return $this->serveFile($filename);
        }
        
        // 1. For regular files: Find message by filePath (format: "/api/v1/files/uploads/{filename}")
        $filePath = "/api/v1/files/uploads/{$filename}";
        
        $message = $this->messageRepository->createQueryBuilder('m')
            ->leftJoin('m.chat', 'c')
            ->addSelect('c')
            ->where('m.filePath = :path')
            ->setParameter('path', $filePath)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$message) {
            $this->logger->warning('StaticUploadController: Message not found for file', [
                'filename' => $filename,
                'file_path' => $filePath
            ]);
            throw $this->createNotFoundException('File not found in database');
        }

        // 2. Check if file is public (chat is shared) AND not expired
        $chat = $message->getChat();
        $isPublicCheck = $chat ? $chat->isPublic() : false;
        $isExpiredCheck = $message->isShareExpired();
        $isPublicAndValid = $isPublicCheck && !$isExpiredCheck;
        
        $this->logger->info('StaticUploadController: Access check', [
            'filename' => $filename,
            'message_id' => $message->getId(),
            'chat_id' => $chat ? $chat->getId() : null,
            'is_public' => $isPublicCheck,
            'is_expired' => $isExpiredCheck,
            'is_public_and_valid' => $isPublicAndValid,
            'has_user' => $user !== null,
            'user_id' => $user?->getId(),
            'owner_id' => $message->getUserId()
        ]);

        // 3. Permission check: Public files OR authenticated owner
        if (!$isPublicAndValid) {
            // Not public - require authentication
            if (!$user) {
                $this->logger->warning('StaticUploadController: Unauthorized access attempt', [
                    'filename' => $filename,
                    'is_chat_public' => $chat ? $chat->isPublic() : false,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
                return $this->json([
                    'error' => 'Authentication required'
                ], Response::HTTP_UNAUTHORIZED);
            }

            // Check ownership (only owner can access private files)
            if ($message->getUserId() !== $user->getId()) {
                $this->logger->warning('StaticUploadController: Forbidden access attempt', [
                    'filename' => $filename,
                    'user_id' => $user->getId(),
                    'owner_id' => $message->getUserId(),
                    'is_chat_public' => $chat ? $chat->isPublic() : false
                ]);
                return $this->json([
                    'error' => 'Access denied'
                ], Response::HTTP_FORBIDDEN);
            }
        }

        // 4. Additional check for expired public shares
        if ($chat && $chat->isPublic() && $message->isShareExpired()) {
            $this->logger->info('StaticUploadController: Expired share link accessed', [
                'filename' => $filename
            ]);
            return $this->json([
                'error' => 'Share link has expired'
            ], Response::HTTP_GONE);
        }

        // 5. Serve the file
        return $this->serveFile($filename);
    }
    
    /**
     * Serve file from disk with security checks
     */
    private function serveFile(string $filename): Response
    {
        // Build absolute path with security checks
        $absolutePath = $this->uploadDir . '/' . $filename;
        
        // Resolve to real path (prevents symlink attacks)
        $realPath = realpath($absolutePath);
        $realUploadDir = realpath($this->uploadDir);
        
        // Security: Ensure file is within upload directory (no path traversal)
        if (!$realPath || !$realUploadDir || strpos($realPath, $realUploadDir) !== 0) {
            $this->logger->error('StaticUploadController: Path traversal attempt', [
                'filename' => $filename,
                'absolute_path' => $absolutePath,
                'real_path' => $realPath,
                'upload_dir' => $realUploadDir
            ]);
            throw $this->createNotFoundException('Invalid file path');
        }

        if (!file_exists($realPath)) {
            $this->logger->error('StaticUploadController: File not found on disk', [
                'filename' => $filename,
                'real_path' => $realPath
            ]);
            throw $this->createNotFoundException('File not found on disk');
        }

        // Determine MIME type and serve inline for images/audio/video
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $inlineTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'mp3', 'wav', 'ogg', 'mp4', 'webm'];

        $disposition = in_array($extension, $inlineTypes)
            ? ResponseHeaderBag::DISPOSITION_INLINE
            : ResponseHeaderBag::DISPOSITION_ATTACHMENT;

        $response = new BinaryFileResponse($realPath);
        $response->setContentDisposition($disposition, $filename);
        
        // Set MIME type
        $mimeType = $this->getMimeType($extension);
        if ($mimeType) {
            $response->headers->set('Content-Type', $mimeType);
        }

        // Cache headers for better performance
        $response->setPublic();
        $response->setMaxAge(3600); // 1 hour
        $response->headers->set('Cache-Control', 'public, max-age=3600');

        // Security headers
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        
        // CORS headers for audio/video playback (already handled by Nelmio, but explicit for media)
        $response->headers->set('Accept-Ranges', 'bytes');

        $this->logger->info('StaticUploadController: File served', [
            'filename' => $filename,
            'mime_type' => $mimeType
        ]);

        return $response;
    }
    
    /**
     * Get MIME type for file extension
     */
    private function getMimeType(string $extension): ?string
    {
        return match(strtolower($extension)) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            'svg' => 'image/svg+xml',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',
            default => null
        };
    }
}

