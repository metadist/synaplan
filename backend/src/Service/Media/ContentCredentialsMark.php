<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Entity\File;
use App\Repository\FileRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Records that a stored file was generated, and marks the bytes when it can.
 * A provider manifest is left byte-identical when we cannot sign over it.
 */
final readonly class ContentCredentialsMark
{
    public const NONE = 'none';
    public const SIGNED = 'signed';
    public const PROVIDER = 'provider';
    public const BOTH = 'both';

    public function __construct(
        private FileRepository $files,
        private C2paSignerClient $signer,
        private XmpDigitalSource $xmp,
        #[Autowire('%app.upload_dir%')]
        private string $uploadDir,
    ) {
    }

    public function apply(File $file): void
    {
        $relative = ltrim($file->getFilePath(), '/');
        $absolute = $this->uploadDir.'/'.$relative;
        $state = self::NONE;
        if (is_file($absolute) && is_readable($absolute)) {
            $original = file_get_contents($absolute);
            if (is_string($original) && '' !== $original) {
                $state = $this->markBytes($absolute, $original, $file);
            }
        }

        $file->setGenerated(true);
        $file->setCredentials($state);
        if (null === $file->getGeneratedModel() || '' === $file->getGeneratedModel()) {
            $provider = $file->getProvider();
            $file->setGeneratedModel(null !== $provider && '' !== $provider ? $provider : null);
        }
        $this->files->save($file);
    }

    public function label(File $file, string $modelLabel): void
    {
        $modelLabel = trim($modelLabel);
        if ('' === $modelLabel) {
            return;
        }
        $file->setGenerated(true);
        $file->setGeneratedModel(mb_substr($modelLabel, 0, 96));
        $this->files->save($file);
    }

    private function markBytes(string $absolute, string $original, File $file): string
    {
        $hasProvider = str_contains($original, 'c2pa') || str_contains($original, 'trainedAlgorithmicMedia');
        $label = $file->getGeneratedModel() ?: ($file->getProvider() ?: 'model');
        $signed = $this->signer->sign($original, basename($absolute), (string) $label, $hasProvider);
        if (is_string($signed)) {
            file_put_contents($absolute, $signed);

            return $hasProvider ? self::BOTH : self::SIGNED;
        }
        if ($hasProvider) {
            return self::PROVIDER;
        }
        $embedded = $this->xmp->embed($original);
        if (is_string($embedded)) {
            file_put_contents($absolute, $embedded);
        }

        return self::NONE;
    }
}
