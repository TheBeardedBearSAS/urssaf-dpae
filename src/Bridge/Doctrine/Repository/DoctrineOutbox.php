<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use TheBeardedBear\UrssafDpae\Application\Port\Outbox;
use TheBeardedBear\UrssafDpae\Application\Port\OutboxEntry;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Entity\OutboxMessage;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\InstantType;
use TheBeardedBear\UrssafDpae\Contract\Event\IntegrationEvent;
use TheBeardedBear\UrssafDpae\Contract\Event\IntegrationEventCodec;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;

/**
 * Outbox table written with DBAL on the connection of the entity manager of the package, so that the events join the
 * transaction of the state change ({@see \TheBeardedBear\UrssafDpae\Bridge\Doctrine\DoctrineTransactionManager}).
 */
final readonly class DoctrineOutbox implements Outbox
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function append(IntegrationEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->connection()->insert($this->table(), OutboxMessage::of($event)->row(), OutboxMessage::TYPES);
        }
    }

    public function pending(int $limit): array
    {
        $rows = $this->connection()->createQueryBuilder()
            ->select('sequence', 'data', 'attempts')
            ->from($this->table())
            ->where('dispatched_at IS NULL')
            ->orderBy('sequence')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(self::entry(...), $rows);
    }

    public function markDispatched(OutboxEntry $entry, Instant $at): void
    {
        $this->connection()->update($this->table(), ['dispatched_at' => $at], ['sequence' => $entry->sequence], OutboxMessage::TYPES);
    }

    public function markFailed(OutboxEntry $entry, string $error): void
    {
        $this->connection()->executeStatement(
            \sprintf('UPDATE %s SET attempts = attempts + 1, last_error = :error WHERE sequence = :sequence', $this->table()),
            ['error' => $error, 'sequence' => $entry->sequence],
        );
    }

    public function purgeDispatchedBefore(Instant $before): int
    {
        return (int) $this->connection()->executeStatement(
            \sprintf('DELETE FROM %s WHERE dispatched_at IS NOT NULL AND dispatched_at < :before', $this->table()),
            ['before' => $before],
            ['before' => InstantType::NAME],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function entry(array $row): OutboxEntry
    {
        /** @var array{sequence: int|string, data: string, attempts: int|string} $row */
        return new OutboxEntry((int) $row['sequence'], IntegrationEventCodec::decode($row['data']), (int) $row['attempts']);
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    private function table(): string
    {
        return $this->entityManager->getClassMetadata(OutboxMessage::class)->getTableName();
    }
}
