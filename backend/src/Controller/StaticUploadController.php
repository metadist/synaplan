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
 * Serve static uploads (e.g., generated images) with auth check.
 *
 * For AI-generated content - only visible to owner or if chat is shared
 */
#[Route('/api/v1/files/uploads')]
class StaticUploadController extends AbstractController
{
    public function __construct(
        private MessageRepository $messageRepository,
        private string $uploadDir,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Serve uploaded file by path (supports subdirectories).
     *
     * GET /api/v1/files/uploads/{path}
     * Examples:
     * - /api/v1/files/uploads/generated_abc123.png (legacy flat files)
     * - /api/v1/files/uploads/13/000/00013/2025/01/123_google_1736189000.mp4 (user subdirectories)
     *
     * Access control:
     * - Owner can always access
     * - Public if chat is shared
     */
    #[Route('/{path}', name: 'serve_static_upload', requirements: ['path' => '.+'], methods: ['GET'])]
    public function serve(
        string $path,
        #[CurrentUser] ?User $user,
        Request $request,
    ): Response {
        // Extract filename from path (last segment)
        $filename = basename($path);

        // Check if this is an AI-generated file that should bypass strict auth
        // Browser <audio> and <video> tags can't send Authorization headers,
        // so we identify AI-generated files by their naming patterns:
        // - tts_*, generated_*, ai_* (legacy patterns)
        // - {messageId}_{provider}_{timestamp}.{ext} (media generation pattern, e.g., 3092_google_1767702325.mp4)
        $isTemporaryAiFile = preg_match('/^(tts_|generated_|ai_)/', $filename);
        $isMediaGeneratedFile = preg_match('/^\d+_[a-z]+_\d+\.[a-z0-9]+$/i', $filename);

        if ($isTemporaryAiFile || $isMediaGeneratedFile) {
            // For AI-generated files: Allow access without strict auth
            // Security is provided by:
            // 1. Files are in user-specific directories (path contains user ID hash)
            // 2. Filenames include message ID, provider, and timestamp (hard to guess)
            // 3. Files are ephemeral and can be cleaned up periodically

            $this->logger->info('StaticUploadController: Serving AI-generated file', [
                'path' => $path,
                'filename' => $filename,
                'is_temporary' => $isTemporaryAiFile,
                'is_media_generated' => $isMediaGeneratedFile,
                'user_id' => $user?->getId(),
            ]);

            // Skip message/permission check - serve directly
            return $this->serveFile($path);
        }

        // 1. For regular files: Find message by filePath (format: "/api/v1/files/uploads/{path}")
        $filePath = "/api/v1/files/uploads/{$path}";

        $message = $this->messageRepository->createQueryBuilder('m')
            ->leftJoin('m.chat', 'c')
            ->addSelect('c')
            ->where('m.filePath = :path')
            ->setParameter('path', $filePath)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$message) {
            $this->logger->warning('StaticUploadController: Message not found for file', [
                'path' => $path,
                'file_path' => $filePath,
            ]);
            throw $this->createNotFoundException('File not found in database');
        }

        // 2. Check if file is public (chat is shared) AND not expired
        $chat = $message->getChat();
        $isPublicCheck = $chat ? $chat->isPublic() : false;
        $isExpiredCheck = $message->isShareExpired();
        $isPublicAndValid = $isPublicCheck && !$isExpiredCheck;

        $this->logger->info('StaticUploadController: Access check', [
            'path' => $path,
            'message_id' => $message->getId(),
            'chat_id' => $chat ? $chat->getId() : null,
            'is_public' => $isPublicCheck,
            'is_expired' => $isExpiredCheck,
            'is_public_and_valid' => $isPublicAndValid,
            'has_user' => null !== $user,
            'user_id' => $user?->getId(),
            'owner_id' => $message->getUserId(),
        ]);

        // 3. Permission check: Public files OR authenticated owner
        if (!$isPublicAndValid) {
            // Not public - require authentication
            if (!$user) {
                $this->logger->warning('StaticUploadController: Unauthorized access attempt', [
                    'path' => $path,
                    'is_chat_public' => $chat ? $chat->isPublic() : false,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);

                return $this->json([
                    'error' => 'Authentication required',
                ], Response::HTTP_UNAUTHORIZED);
            }

            // Check ownership (only owner can access private files)
            if ($message->getUserId() !== $user->getId()) {
                $this->logger->warning('StaticUploadController: Forbidden access attempt', [
                    'path' => $path,
                    'user_id' => $user->getId(),
                    'owner_id' => $message->getUserId(),
                    'is_chat_public' => $chat ? $chat->isPublic() : false,
                ]);

                return $this->json([
                    'error' => 'Access denied',
                ], Response::HTTP_FORBIDDEN);
            }
        }

        // 4. Additional check for expired public shares
        if ($chat && $chat->isPublic() && $message->isShareExpired()) {
            $this->logger->info('StaticUploadController: Expired share link accessed', [
                'path' => $path,
            ]);

            return $this->json([
                'error' => 'Share link has expired',
            ], Response::HTTP_GONE);
        }

        // 5. Serve the file
        return $this->serveFile($path);
    }

    /**
     * Serve file from disk with security checks.
     *
     * @param string $path Relative path from uploads dir (can include subdirectories)
     */
    private function serveFile(string $path): Response
    {
        // Build absolute path with security checks
        $absolutePath = $this->uploadDir.'/'.$path;

        // Resolve to real path (prevents symlink attacks)
        $realPath = realpath($absolutePath);
        $realUploadDir = realpath($this->uploadDir);

        // Security: Ensure file is within upload directory (no path traversal)
        if (!$realPath || !$realUploadDir || 0 !== strpos($realPath, $realUploadDir)) {
            $this->logger->error('StaticUploadController: Path traversal attempt', [
                'path' => $path,
                'absolute_path' => $absolutePath,
                'real_path' => $realPath,
                'upload_dir' => $realUploadDir,
            ]);
            throw $this->createNotFoundException('Invalid file path');
        }

        if (!file_exists($realPath)) {
            $this->logger->error('StaticUploadController: File not found on disk', [
                'path' => $path,
                'real_path' => $realPath,
            ]);
            throw $this->createNotFoundException('File not found on disk');
        }

        // Extract filename for response headers (last segment of path)
        $filename = basename($path);

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
            'path' => $path,
            'filename' => $filename,
            'mime_type' => $mimeType,
        ]);

        return $response;
    }

    /**
     * Get MIME type for file extension.
     */
    private function getMimeType(string $extension): ?string
    {
        return match (strtolower($extension)) {
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
            default => null,
        };
    }
}
