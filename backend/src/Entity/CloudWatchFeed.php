<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The revision the watch index has reached for one cloud. The row stays after
 * the watches themselves are erased.
 */
#[ORM\Entity]
#[ORM\Table(name: 'BCLOUDWATCHFEED')]
class CloudWatchFeed
{
    #[ORM\Id]
    #[ORM\Column(name: 'BINSTANCEID', length: 191)]
    private string $instanceId = '';

    #[ORM\Column(name: 'BREVISION', type: 'bigint', options: ['default' => 0])]
    private int $revision = 0;
}
