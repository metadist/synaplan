<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SelfAware\Docs;

use App\Service\SelfAware\Docs\PlatformDocReferenceResolver;
use App\Service\SelfAware\Docs\PlatformDocsSyncState;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PlatformDocReferenceResolverTest extends TestCase
{
    public function testKnownSlugBecomesAMarkdownLink(): void
    {
        $resolver = $this->resolver([
            'using-synaplan' => $this->page('Using Synaplan', 'https://docs.example/using-synaplan'),
        ]);

        $this->assertSame(
            'See [Using Synaplan](https://docs.example/using-synaplan).',
            $resolver->resolveDocTags('See [Doc:using-synaplan].'),
        );
    }

    public function testUnknownSlugIsRemovedWithoutLeavingASpaceBeforePunctuation(): void
    {
        $this->assertSame('See.', $this->resolver([])->resolveDocTags('See [Doc:missing].'));
    }

    public function testRemovedTagDoesNotLeaveADoubleSpace(): void
    {
        $this->assertSame('Hello world', $this->resolver([])->resolveDocTags('Hello [Doc:missing] world'));
    }

    public function testMultiSlugKeepsKnownPagesAndDropsTheRest(): void
    {
        $resolver = $this->resolver([
            'using-synaplan' => $this->page('Using Synaplan', 'https://docs.example/using-synaplan'),
        ]);

        $this->assertSame(
            'See [Using Synaplan](https://docs.example/using-synaplan).',
            $resolver->resolveDocTags('See [Doc:using-synaplan, missing].'),
        );
    }

    public function testNonHttpsUrlIsStripped(): void
    {
        $resolver = $this->resolver([
            'draft' => $this->page('Draft', 'http://docs.example/draft'),
        ]);

        $this->assertSame('Read.', $resolver->resolveDocTags('Read [Doc:draft].'));
    }

    public function testEmptyCatalogStripsEveryTag(): void
    {
        $this->assertSame('Hello.', $this->resolver([])->resolveDocTags('Hello [Doc:using-synaplan].'));
    }

    public function testTextWithoutATagDoesNotReadTheCatalog(): void
    {
        $state = $this->createMock(PlatformDocsSyncState::class);
        $state->expects($this->never())->method('read');

        $resolver = new PlatformDocReferenceResolver($state, new NullLogger());

        $this->assertSame('Hello.', $resolver->resolveDocTags('Hello.'));
    }

    public function testTitleBracketsCannotBreakTheMarkdownLink(): void
    {
        $resolver = $this->resolver([
            'using-synaplan' => $this->page('Using [Synaplan]', 'https://docs.example/using-synaplan'),
        ]);

        $this->assertSame(
            '[Using Synaplan](https://docs.example/using-synaplan)',
            $resolver->resolveDocTags('[Doc:using-synaplan]'),
        );
    }

    public function testEncodeDocsMetaMatchesTheWebChatShape(): void
    {
        $docs = [[
            'slug' => 'using-synaplan',
            'title' => 'Using Synaplan',
            'url' => 'https://docs.example/using-synaplan',
        ]];

        $this->assertSame(
            json_encode($docs, JSON_UNESCAPED_SLASHES),
            PlatformDocReferenceResolver::encodeDocsMeta(['docs' => $docs]),
        );
        $this->assertNull(PlatformDocReferenceResolver::encodeDocsMeta([]));
    }

    /**
     * @param array<string, array{title: string, url: string, section?: string}> $pages
     */
    private function resolver(array $pages): PlatformDocReferenceResolver
    {
        $stored = [];
        foreach ($pages as $slug => $page) {
            $stored[$slug] = [
                'sha256' => 'abc',
                'file_id' => 1,
                'title' => $page['title'],
                'url' => $page['url'],
                'section' => $page['section'] ?? 'guide',
                'synced_at' => '2026-01-01T00:00:00Z',
                'slug' => $slug,
            ];
        }

        $state = $this->createMock(PlatformDocsSyncState::class);
        $state->method('read')->willReturn([
            'manifest_url' => 'https://docs.example/manifest.json',
            'manifest_version' => '1',
            'synced_at' => '2026-01-01T00:00:00Z',
            'pages' => $stored,
        ]);

        return new PlatformDocReferenceResolver($state, new NullLogger());
    }

    /**
     * @return array{title: string, url: string}
     */
    private function page(string $title, string $url): array
    {
        return ['title' => $title, 'url' => $url];
    }
}
