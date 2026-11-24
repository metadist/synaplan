<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Widget;

use App\Entity\Widget;
use App\Service\Widget\WidgetLegacyLoader;
use PHPUnit\Framework\TestCase;

final class WidgetLegacyLoaderTest extends TestCase
{
    public function testTheLoaderStartsTheWidgetWithItsSettingsAndThePagesSignedVisitor(): void
    {
        $module = $this->moduleSource((new Widget())->setWidgetId('wdg_1')->setConfig(['position' => 'bottom-left', 'autoOpen' => true]));

        self::assertStringContainsString('import SynaplanWidget from "https://app.example.test/widget.js";', $module);
        self::assertStringContainsString('"widgetId":"wdg_1"', $module);
        self::assertStringContainsString('"position":"bottom-left"', $module);
        self::assertStringContainsString('"autoOpen":true', $module);
        self::assertStringContainsString('"apiUrl":"https://app.example.test"', $module);
        self::assertStringContainsString('const visitor = window.SynaplanWidgetVisitor || {};', $module);
        self::assertStringContainsString('externalUserId: visitor.externalUserId,', $module);
        self::assertStringContainsString('externalUserIdSignature: visitor.externalUserIdSignature', $module);
    }

    public function testAWidgetSettingCannotBreakOutOfTheScript(): void
    {
        $hostile = "'}); alert(1); `\${alert(2)}` </script><script>alert(3)//";
        $widget = (new Widget())->setWidgetId('wdg_1')->setConfig(['autoMessage' => $hostile, 'primaryColor' => $hostile]);

        $script = (new WidgetLegacyLoader())->script('https://app.example.test', $widget);
        $module = $this->moduleSource($widget);

        self::assertStringNotContainsString('</script>', $script);
        self::assertStringNotContainsString('<script>', $script);
        self::assertSame(1, preg_match('/Object\.assign\((\{.*?\}), \{\n/s', $module, $match));
        $init = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([$hostile, $hostile], [$init['autoMessage'], $init['primaryColor']]);
    }

    /** The module the loader injects, as the browser would read it from `script.textContent`. */
    private function moduleSource(Widget $widget): string
    {
        $script = (new WidgetLegacyLoader())->script('https://app.example.test', $widget);
        self::assertSame(1, preg_match('/script\.textContent = (".*");\n/', $script, $match));

        return json_decode($match[1], false, 512, JSON_THROW_ON_ERROR);
    }
}
