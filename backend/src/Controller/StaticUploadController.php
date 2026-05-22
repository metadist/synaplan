<?php

namespace App\Controller;

use App\Entity\Message;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Repository\MessageRepository;
use App\Service\File\FileHelper;
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
        private FileRepository $fileRepository,
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
     *
     * Supports HTTP Range requests for video/audio seeking.
     */
    #[Route('/{path}', name: 'serve_static_upload', requirements: ['path' => '.+'], methods: ['GET', 'HEAD'])]
    public function serve(
        string $path,
        #[CurrentUser] ?User $user,
        Request $request,
    ): Response {
        // Extract filename from path (last segment)
        $filename = basename($path);

        // Check if this is an OG image for social media sharing
        // OG images are public and stored in og/{shard}/{token}.{ext}
        $isOgImage = str_starts_with($path, 'og/');

        if ($isOgImage) {
            $this->logger->info('StaticUploadController: Serving OG image', [
                'path' => $path,
            ]);

            return $this->serveFile($path, $request);
        }

        // Widget icons are public assets displayed on third-party websites
        $isWidgetIcon = str_starts_with($path, 'widget-icons/');

        if ($isWidgetIcon) {
            return $this->serveFile($path, $request);
        }

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
            return $this->serveFile($path, $request);
        }

        // 1. Resolve the asset to either a Message (legacy + AI-generated)
        //    or a File entity (user uploads, WhatsApp media — issue #976).
        //    The Message lookup tolerates both the prefixed
        //    `/api/v1/files/uploads/...` form (AI-generated content,
        //    `MediaGenerationHandler`) AND the raw relative path that
        //    older WhatsApp messages persisted before File entities were
        //    introduced. The File entity fallback covers regular web
        //    uploads (issue #955) and new WhatsApp media stored via
        //    `WhatsAppService::handleMediaDownload`.
        $context = $this->resolveFileContext($path);
        if (!$context) {
            $this->logger->warning('StaticUploadController: File not found in database', [
                'path' => $path,
            ]);
            throw $this->createNotFoundException('File not found in database');
        }

        $message = $context['message'];
        $ownerUserId = $context['owner_user_id'];

        // 2. Check if file is public (chat is shared) AND not expired
        $chat = $message?->getChat();
        $isPublicCheck = $chat ? $chat->isPublic() : false;
        $isExpiredCheck = $message ? $message->isShareExpired() : false;
        $isPublicAndValid = $isPublicCheck && !$isExpiredCheck;

        $this->logger->info('StaticUploadController: Access check', [
            'path' => $path,
            'source' => $context['source'],
            'message_id' => $message?->getId(),
            'file_id' => $context['file_id'],
            'chat_id' => $chat ? $chat->getId() : null,
            'is_public' => $isPublicCheck,
            'is_expired' => $isExpiredCheck,
            'is_public_and_valid' => $isPublicAndValid,
            'has_user' => null !== $user,
            'user_id' => $user?->getId(),
            'owner_id' => $ownerUserId,
        ]);

        // 3. Permission check: Public files OR authenticated owner
        if (!$isPublicAndValid) {
            // Not public - require authentication
            if (!$user) {
                $this->logger->warning('StaticUploadController: Unauthorized access attempt', [
                    'path' => $path,
                    'is_chat_public' => $isPublicCheck,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);

                return $this->json([
                    'error' => 'Authentication required',
                ], Response::HTTP_UNAUTHORIZED);
            }

            // Check ownership (only owner can access private files)
            if ($ownerUserId !== $user->getId()) {
                $this->logger->warning('StaticUploadController: Forbidden access attempt', [
                    'path' => $path,
                    'user_id' => $user->getId(),
                    'owner_id' => $ownerUserId,
                    'is_chat_public' => $isPublicCheck,
                ]);

                return $this->json([
                    'error' => 'Access denied',
                ], Response::HTTP_FORBIDDEN);
            }
        }

        // 4. Additional check for expired public shares.
        // `$chat` is only non-null when `$message` is set (we resolved
        // `$chat = $message?->getChat()` above), so the existing chat
        // checks already imply a non-null message — no extra null guard
        // needed.
        if ($chat && $chat->isPublic() && $message->isShareExpired()) {
            $this->logger->info('StaticUploadController: Expired share link accessed', [
                'path' => $path,
            ]);

            return $this->json([
                'error' => 'Share link has expired',
            ], Response::HTTP_GONE);
        }

        // 5. Serve the file
        return $this->serveFile($path, $request);
    }

    /**
     * Resolve a relative upload path to its owning Message (preferred) or
     * orphan File entity, returning the metadata needed for the access
     * check.
     *
     * Priority:
     *  1. `Message::filePath` matching either the prefixed serve URL or
     *     the raw relative path. This covers AI-generated media (which
     *     stores the prefixed form) and legacy inbound WhatsApp messages
     *     (which persisted the raw relative path before issue #976).
     *  2. `File::filePath` matching the raw relative path. This covers
     *     regular web uploads (issue #955 surfaced inline `<audio>`
     *     playback for them) and new WhatsApp media that travels through
     *     the same `File` pipeline.
     *
     * @return array{message: ?Message, owner_user_id: int, source: string, file_id: ?int}|null
     */
    private function resolveFileContext(string $path): ?array
    {
        $apiPath = '/api/v1/files/uploads/'.$path;

        $message = $this->messageRepository->createQueryBuilder('m')
            ->leftJoin('m.chat', 'c')
            ->addSelect('c')
            ->where('m.filePath = :apiPath OR m.filePath = :rawPath')
            ->setParameter('apiPath', $apiPath)
            ->setParameter('rawPath', $path)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($message instanceof Message) {
            return [
                'message' => $message,
                'owner_user_id' => $message->getUserId(),
                'source' => 'message_filepath',
                'file_id' => null,
            ];
        }

        $file = $this->fileRepository->findOneBy(['filePath' => $path]);
        if (null === $file) {
            return null;
        }

        $linkedMessage = $this->messageRepository->createQueryBuilder('m')
            ->leftJoin('m.chat', 'c')
            ->addSelect('c')
            ->innerJoin('m.files', 'f')
            ->where('f.id = :fileId')
            ->setParameter('fileId', $file->getId())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return [
            'message' => $linkedMessage instanceof Message ? $linkedMessage : null,
            'owner_user_id' => $file->getUserId(),
            'source' => $linkedMessage ? 'file_attached_to_message' : 'file_orphan',
            'file_id' => $file->getId(),
        ];
    }

    /**
     * Serve file from disk with security checks.
     *
     * Supports HTTP Range requests for video/audio seeking and resumable downloads.
     * Uses NFS-aware file checks to handle multi-server deployments with shared storage.
     *
     * @param string  $path    Relative path from uploads dir (can include subdirectories)
     * @param Request $request The HTTP request (for range header processing)
     */
    private function serveFile(string $path, Request $request): Response
    {
        // Build absolute path with security checks
        $absolutePath = $this->uploadDir.'/'.$path;

        // Use NFS-aware path resolution instead of realpath()
        // This handles NFS attribute caching issues in multi-server deployments
        $validatedPath = FileHelper::resolvePathNfs($absolutePath, $this->uploadDir);

        // Security: Ensure file is within upload directory and exists
        if (false === $validatedPath) {
            $this->logger->error('StaticUploadController: Invalid path or file not found', [
                'path' => $path,
                'absolute_path' => $absolutePath,
                'upload_dir' => $this->uploadDir,
            ]);
            throw $this->createNotFoundException('Invalid file path or file not found');
        }

        $realPath = $validatedPath;

        // Extract filename for response headers (last segment of path)
        $filename = basename($path);

        // Determine MIME type and serve inline for images/audio/video
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $inlineTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'mp3', 'wav', 'ogg', 'mp4', 'webm'];

        $disposition = in_array($extension, $inlineTypes)
            ? ResponseHeaderBag::DISPOSITION_INLINE
            : ResponseHeaderBag::DISPOSITION_ATTACHMENT;

        // Create response and enable automatic ETag generation
        $response = new BinaryFileResponse($realPath);
        $response->setContentDisposition($disposition, $filename);
        $response->setAutoEtag();

        // Set MIME type
        $mimeType = $this->getMimeType($extension);
        if ($mimeType) {
            $response->headers->set('Content-Type', $mimeType);
        }

        // Cache headers for better performance
        $response->setPublic();
        $response->setMaxAge(3600); // 1 hour

        // Security headers
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        // CRITICAL: Prepare response with request to enable Range request handling
        // This is required for video/audio seeking to work properly
        $response->prepare($request);

        $fileSize = filesize($realPath);
        $hasRangeHeader = $request->headers->has('Range');

        $this->logger->info('StaticUploadController: File served', [
            'path' => $path,
            'filename' => $filename,
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
            'has_range' => $hasRangeHeader,
            'status_code' => $response->getStatusCode(),
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
