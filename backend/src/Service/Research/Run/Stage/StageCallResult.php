<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

final readonly class StageCallResult
{
    private function __construct(
        public ?ResearchModelReply $reply,
        public ?StageResult $stop,
    ) {
    }

    public static function reply(ResearchModelReply $reply): self
    {
        return new self($reply, null);
    }

    public static function stop(StageResult $stop): self
    {
        return new self(null, $stop);
    }
}
