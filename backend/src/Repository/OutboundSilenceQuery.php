<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

/**
 * Whether a chat channel should repeat the AI sentence.
 * The clock is the last outbound message, not the chat's updated-at.
 */
final readonly class OutboundSilenceQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public function isDue(int $chatId, ?string $peerKey, int $silenceSeconds, int $now): bool
    {
        if ($chatId <= 0) {
            return true;
        }

        $sql = 'SELECT m.BUNIXTIMES AS ts FROM BMESSAGES m';
        $params = ['chat' => $chatId, 'direction' => 'OUT'];
        if (null !== $peerKey && '' !== $peerKey) {
            $sql .= ' INNER JOIN BMESSAGEMETA meta ON meta.BMESSAGEID = m.BID AND meta.BMETAKEY = :metaKey AND meta.BMETAVALUE = :peer';
            $params['metaKey'] = 'tg_chat_id';
            $params['peer'] = $peerKey;
        }
        $sql .= ' WHERE m.BCHATID = :chat AND m.BDIRECT = :direction ORDER BY m.BUNIXTIMES DESC LIMIT 1';

        $statement = $this->connection->prepare($sql);
        foreach ($params as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $ts = $statement->executeQuery()->fetchOne();
        if (false === $ts || null === $ts) {
            return true;
        }

        return ($now - (int) $ts) >= $silenceSeconds;
    }
}
