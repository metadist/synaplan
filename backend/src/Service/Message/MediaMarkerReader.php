<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\Service\File\FileHelper;
use App\Service\File\UploadRequestPath;
use App\Service\File\UserUploadPathBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads the `[IMAGE:…]` / `[VIDEO:…]` marker of a reply.
 *
 * The marker is model output, so whoever controls the prompt controls its
 * value. The value becomes `Message::filePath`, and that column decides who
 * may download a stored file and which file later turns read. A marker is
 * therefore taken only when it names
 *
 * - a file that exists inside the replying account's own upload directory, or
 * - an https address in a reply of the built-in demo provider, whose canned
 *   answers carry placeholder media.
 *
 * Anything else is dropped. The marker is removed from the text either way.
 */
final readonly class MediaMarkerReader
{
    private const MARKER_PATTERN = '/\[(IMAGE|VIDEO):([^\]\r\n]*)\]/i';
    private const FILE_TYPES = ['IMAGE' => 'png', 'VIDEO' => 'mp4'];
    private const SERVE_PREFIX = '/api/v1/files/uploads/';
    private const DEMO_PROVIDER = 'test';
    private const MAX_TARGET_LENGTH = 1024;
    private const LOGGED_TARGET_LENGTH = 200;

    public function __construct(
        private UserUploadPathBuilder $userUploadPathBuilder,
        #[Autowire('%app.upload_dir%')]
        private string $uploadDir,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param int         $userId   the account the reply is stored for
     * @param string|null $provider name of the provider that wrote the reply
     */
    public function read(string $text, int $userId, ?string $provider): MediaMarker
    {
        $found = $this->firstMarker($text);
        if (null === $found) {
            return new MediaMarker($text);
        }

        $cleanText = trim((string) preg_replace(self::MARKER_PATTERN, '', $text));
        $target = trim($found['target']);

        if (!$this->isAllowed($target, $userId, $provider)) {
            $this->logger->warning('Media marker refused: target is not a file of this account', [
                'user_id' => $userId,
                'provider' => $provider,
                'target' => mb_substr($target, 0, self::LOGGED_TARGET_LENGTH),
            ]);

            return new MediaMarker($cleanText);
        }

        return new MediaMarker($cleanText, $target, self::FILE_TYPES[$found['kind']]);
    }

    /**
     * An image marker wins over a video marker, wherever each one stands.
     *
     * @return array{kind: 'IMAGE'|'VIDEO', target: string}|null
     */
    private function firstMarker(string $text): ?array
    {
        if (!preg_match_all(self::MARKER_PATTERN, $text, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $video = null;
        foreach ($matches as $match) {
            if ('IMAGE' === strtoupper($match[1])) {
                return ['kind' => 'IMAGE', 'target' => $match[2]];
            }
            $video ??= ['kind' => 'VIDEO', 'target' => $match[2]];
        }

        return $video;
    }

    private function isAllowed(string $target, int $userId, ?string $provider): bool
    {
        if ('' === $target || strlen($target) > self::MAX_TARGET_LENGTH) {
            return false;
        }

        if (str_contains($target, '://')) {
            return null !== $provider
                && self::DEMO_PROVIDER === strtolower($provider)
                && $this->isHttpsUrl($target);
        }

        return $this->isOwnStoredFile($target, $userId);
    }

    private function isHttpsUrl(string $target): bool
    {
        return str_starts_with($target, 'https://')
            && false !== filter_var($target, FILTER_VALIDATE_URL);
    }

    private function isOwnStoredFile(string $target, int $userId): bool
    {
        $relative = str_starts_with($target, self::SERVE_PREFIX)
            ? substr($target, strlen(self::SERVE_PREFIX))
            : $target;

        if (null === UploadRequestPath::fromRoute($relative) || str_contains($relative, '\\')) {
            return false;
        }

        $ownDirectory = $this->userUploadPathBuilder->buildUserBaseRelativePath($userId).'/';
        if (!str_starts_with($relative, $ownDirectory)) {
            return false;
        }

        return false !== FileHelper::resolvePathNfs($this->uploadDir.'/'.$relative, $this->uploadDir);
    }
}
