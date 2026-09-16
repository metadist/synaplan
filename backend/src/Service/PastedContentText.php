<?php

declare(strict_types=1);

namespace App\Service;

final class PastedContentText
{
    public static function strip(string $text): string
    {
        $stripped = preg_replace('/<pasted-content>[\s\S]*?<\/pasted-content>/', '', $text) ?? $text;
        $collapsed = preg_replace("/\n{3,}/", "\n\n", $stripped) ?? $stripped;

        return trim($collapsed);
    }
}
