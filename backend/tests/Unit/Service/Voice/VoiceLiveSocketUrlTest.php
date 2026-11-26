<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Voice;

use App\Service\Voice\VoiceLiveSocketUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class VoiceLiveSocketUrlTest extends TestCase
{
    #[DataProvider('cases')]
    public function testTheSchemeFollowsThePublicSite(string $requestUrl, string $appUrl, string $expected): void
    {
        $url = (new VoiceLiveSocketUrl('/voice/ws', $appUrl))->build(Request::create($requestUrl), 'a.b.c');

        self::assertSame($expected, $url);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function cases(): iterable
    {
        yield 'a secure request is wss' => [
            'https://synaplan.test/api',
            'http://localhost:8000',
            'wss://synaplan.test/voice/ws?ticket=a.b.c',
        ];
        yield 'plain HTTP behind a TLS proxy follows an HTTPS APP_URL on the same host' => [
            'http://web.synaplan.test/api',
            'https://web.synaplan.test',
            'wss://web.synaplan.test/voice/ws?ticket=a.b.c',
        ];
        yield 'the host comparison ignores case' => [
            'http://web.synaplan.test/api',
            'https://WEB.Synaplan.Test/',
            'wss://web.synaplan.test/voice/ws?ticket=a.b.c',
        ];
        yield 'another host than APP_URL keeps the request scheme' => [
            'http://10.0.0.15:8000/api',
            'https://web.synaplan.test',
            'ws://10.0.0.15:8000/voice/ws?ticket=a.b.c',
        ];
        yield 'a local install stays ws' => [
            'http://localhost:8000/api',
            'http://localhost:8000',
            'ws://localhost:8000/voice/ws?ticket=a.b.c',
        ];
        yield 'an empty APP_URL keeps the request scheme' => [
            'http://synaplan.test/api',
            '',
            'ws://synaplan.test/voice/ws?ticket=a.b.c',
        ];
    }

    public function testThePathIsNormalisedAndTheTicketEncoded(): void
    {
        $url = (new VoiceLiveSocketUrl('voice/ws', ''))->build(Request::create('https://synaplan.test/'), 'a b');

        self::assertSame('wss://synaplan.test/voice/ws?ticket=a%20b', $url);
    }
}
