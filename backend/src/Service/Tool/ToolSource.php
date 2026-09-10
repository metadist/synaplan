<?php

declare(strict_types=1);

namespace App\Service\Tool;

enum ToolSource: string
{
    case Builtin = 'builtin';
    case Mcp = 'mcp';
    case Document = 'document';
    case Skill = 'skill';
    case Plugin = 'plugin';
    case Custom = 'custom';
}
