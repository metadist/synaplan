<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\UrlWatch;

use App\Entity\UrlWatch;
use App\Repository\UrlWatchRepository;
use App\Service\UrlContentResult;
use App\Service\UrlContentService;
use App\Service\UrlWatch\TextDiff;
use App\Service\UrlWatch\UrlWatchCompareResult;
use App\Service\UrlWatch\UrlWatchFetchFailedException;
use App\Service\UrlWatch\UrlWatchNotFoundException;
use App\Service\UrlWatch\UrlWatchService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class UrlWatchServiceTest extends TestCase
{
    public function testNormalizeUrlLowercasesHostAndStripsFragment(): void
    {
        self::assertSame(
            'https://example.com/news',
            UrlWatchService::normalizeUrl('https://Example.COM/news/#top'),
        );
        self::assertSame(
            'https://example.com/news',
            UrlWatchService::normalizeUrl('https://example.com/news/'),
        );
        self::assertSame(
            'https://example.com/',
            UrlWatchService::normalizeUrl('https://example.com/'),
        );
    }

    public function testRememberFirstThenUnchangedThenChanged(): void
    {
        $service = $this->service();

        $first = $service->remember(7, 'https://example.com/news', 'News', "line one\nline two");
        self::assertSame(UrlWatchCompareResult::FIRST, $first->status);
        self::assertStringContainsString('Status: first_save', $first->promptText);
        self::assertStringContainsString('line one', $first->promptText);

        $same = $service->remember(7, 'https://EXAMPLE.com/news/', 'News', "line one\nline two");
        self::assertSame(UrlWatchCompareResult::UNCHANGED, $same->status);
        self::assertStringContainsString('No changes', $same->promptText);
        self::assertSame($first->watch->getUrl(), $same->watch->getUrl());

        $changed = $service->remember(7, 'https://example.com/news', 'News', "line one\nline three");
        self::assertSame(UrlWatchCompareResult::CHANGED, $changed->status);
        self::assertStringContainsString('- line two', $changed->diffText);
        self::assertStringContainsString('+ line three', $changed->diffText);
        self::assertSame("line one\nline three", $changed->watch->getBody());
    }

    public function testRegisterIsIdempotentPerOwnerAndUrl(): void
    {
        $service = $this->service();
        $a = $service->register(7, 'Please fetch https://example.com/a');
        $b = $service->register(7, 'https://example.com/a/');
        $other = $service->register(8, 'https://example.com/a');

        self::assertSame($a, $b);
        self::assertNotSame($a, $other);
        self::assertSame('https://example.com/a', $a->getUrl());
    }

    public function testRegisterRejectsNonUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->register(7, 'not a url');
    }

    public function testRefreshFetchesAndRemembers(): void
    {
        $service = $this->service($this->urlContentMock(new UrlContentResult(
            url: 'https://example.com/a',
            extractedText: 'fresh body',
            title: 'Fresh',
            hostname: 'example.com',
            success: true,
        )));
        $watch = $service->register(7, 'https://example.com/a');
        self::assertNotNull($watch->getId());

        $result = $service->refresh((int) $watch->getId(), 7);
        self::assertSame(UrlWatchCompareResult::FIRST, $result->status);
        self::assertSame('fresh body', $result->watch->getBody());
        self::assertSame('Fresh', $result->watch->getTitle());
    }

    public function testRefreshMissingIsNotFound(): void
    {
        $this->expectException(UrlWatchNotFoundException::class);
        $this->service()->refresh(99, 7);
    }

    public function testRefreshFailedFetch(): void
    {
        $service = $this->service($this->urlContentMock(new UrlContentResult(
            url: 'https://example.com/a',
            extractedText: '',
            title: '',
            hostname: 'example.com',
            success: false,
            error: 'HTTP 500',
        )));
        $watch = $service->register(7, 'https://example.com/a');

        $this->expectException(UrlWatchFetchFailedException::class);
        $service->refresh((int) $watch->getId(), 7);
    }

    public function testDeleteOnlyForOwner(): void
    {
        $service = $this->service();
        $watch = $service->register(7, 'https://example.com/a');
        $id = (int) $watch->getId();

        self::assertFalse($service->delete($id, 8));
        self::assertTrue($service->delete($id, 7));
        self::assertNull($service->get($id, 7));
    }

    private function service(?UrlContentService $urlContent = null): UrlWatchService
    {
        $urlContent ??= $this->urlContentMock();
        /** @var array<string, UrlWatch> $store */
        $store = [];
        $nextId = 1;

        $repo = $this->createMock(UrlWatchRepository::class);
        $repo->method('findOneByOwnerAndHash')->willReturnCallback(
            static function (int $ownerId, string $hash) use (&$store): ?UrlWatch {
                return $store[$ownerId.'|'.$hash] ?? null;
            },
        );
        $repo->method('findOneForOwner')->willReturnCallback(
            static function (int $id, int $ownerId) use (&$store): ?UrlWatch {
                foreach ($store as $watch) {
                    if ($watch->getId() === $id && $watch->getOwnerId() === $ownerId) {
                        return $watch;
                    }
                }

                return null;
            },
        );
        $repo->method('findByOwner')->willReturnCallback(
            static function (int $ownerId) use (&$store): array {
                return array_values(array_filter(
                    $store,
                    static fn (UrlWatch $watch): bool => $watch->getOwnerId() === $ownerId,
                ));
            },
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(
            static function (object $entity) use (&$store, &$nextId): void {
                if (!$entity instanceof UrlWatch) {
                    return;
                }
                if (null === $entity->getId()) {
                    $ref = new \ReflectionProperty(UrlWatch::class, 'id');
                    $ref->setValue($entity, $nextId);
                    ++$nextId;
                }
                $store[$entity->getOwnerId().'|'.$entity->getUrlHash()] = $entity;
            },
        );
        $em->method('remove')->willReturnCallback(
            static function (object $entity) use (&$store): void {
                if (!$entity instanceof UrlWatch) {
                    return;
                }
                unset($store[$entity->getOwnerId().'|'.$entity->getUrlHash()]);
            },
        );

        return new UrlWatchService($repo, $em, $urlContent, new TextDiff());
    }

    private function urlContentMock(?UrlContentResult $fetchResult = null): UrlContentService
    {
        $urlContent = $this->createMock(UrlContentService::class);
        $urlContent->method('extractUrls')->willReturnCallback(
            static function (string $raw): array {
                if (1 === preg_match('/https?:\/\/[^\s]+/i', $raw, $m)) {
                    return [rtrim($m[0], '.,;:!?)')];
                }

                return [];
            },
        );
        if (null !== $fetchResult) {
            $urlContent->method('fetchForCrawling')->willReturn($fetchResult);
        }

        return $urlContent;
    }
}
