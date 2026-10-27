<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A watch the index must still report as gone after its row was deleted.
 */
#[ORM\Entity]
#[ORM\Table(name: 'BCLOUDWATCHTOMBSTONES')]
#[ORM\Index(columns: ['BINSTANCEID', 'BREVISION'], name: 'idx_cloudwatch_tombstone_feed')]
class CloudWatchTombstone
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BINSTANCEID', length: 191)]
    private string $instanceId = '';

    #[ORM\Column(name: 'BWATCHID', type: 'bigint')]
    private int $watchId = 0;

    #[ORM\Column(name: 'BREVISION', type: 'bigint')]
    private int $revision = 0;

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created = 0;
}
