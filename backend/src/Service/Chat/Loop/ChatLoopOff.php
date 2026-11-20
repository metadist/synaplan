<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

/** A chat run was asked to start while the loop is switched off. */
final class ChatLoopOff extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The chat loop is switched off.');
    }
}
