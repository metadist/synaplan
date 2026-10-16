<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Opendesk;

use App\Service\Opendesk\OpendeskConnectSnippet;
use PHPUnit\Framework\TestCase;

final class OpendeskConnectSnippetTest extends TestCase
{
    public function testPublicHttpsBecomesAwssJitsiTemplate(): void
    {
        $snippet = new OpendeskConnectSnippet(
            'https://notes.example/',
            'http://transcriber:8095',
            'de',
            'opendesk',
        );

        $data = $snippet->toArray();

        self::assertSame('Meeting notes', $data['product']);
        self::assertSame('opendesk', $data['mode']);
        self::assertSame('de', $data['language']);
        self::assertSame(
            'wss://notes.example/transcribe?sessionId={{MEETING_ID}}&sendBack=true&lang=de',
            $data['jitsi']['url_template'],
        );
        self::assertStringContainsString('Authorization', $data['jitsi']['jicofo']);
        self::assertSame(['audio:transcribe'], $data['scopes']);
        self::assertStringContainsString('visible participant', $data['element']['element_call']);
    }

    public function testUnknownLanguageFallsBackToAuto(): void
    {
        $snippet = new OpendeskConnectSnippet('', 'http://transcriber:8095', 'klingon', 'nope');
        $data = $snippet->toArray();

        self::assertSame('auto', $data['language']);
        self::assertSame('opendesk', $data['mode']);
        self::assertStringStartsWith('ws://transcriber:8095/transcribe', $data['jitsi']['url_template']);
    }
}
