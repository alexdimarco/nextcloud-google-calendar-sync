<?php

declare(strict_types=1);

namespace OCA\GoogleCalSync\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

class CalendarMappingMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'googlecalsync_mappings', CalendarMapping::class);
    }

    public function find(int $id, string $userId): CalendarMapping {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('id',      $qb->createNamedParameter($id)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        /** @var CalendarMapping */
        return $this->findEntity($qb);
    }

    /** @return CalendarMapping[] */
    public function findAllForUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        return $this->findEntities($qb);
    }

    /**
     * Return mappings that are due for a sync (last_synced_at is null or
     * older than sync_interval_minutes ago).
     *
     * @return CalendarMapping[]
     */
    public function findDue(string $userId): array {
        $qb = $this->db->getQueryBuilder();

        $now = new \DateTime();

        // Use a raw expression: last_synced_at IS NULL OR
        // TIMESTAMPDIFF(MINUTE, last_synced_at, NOW()) >= sync_interval_minutes
        // We do the simpler approach: fetch all and filter in PHP.
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $all = $this->findEntities($qb);

        return array_filter($all, function (CalendarMapping $m) use ($now) {
            $last = $m->getLastSyncedAt();
            if ($last === null) {
                return true;
            }
            $lastDt  = new \DateTime($last);
            $diffMin = ($now->getTimestamp() - $lastDt->getTimestamp()) / 60;
            return $diffMin >= $m->getSyncIntervalMinutes();
        });
    }

    /** @return CalendarMapping[] */
    public function findAllUsers(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('user_id')
           ->from($this->getTableName());
        $stmt  = $qb->executeQuery();
        $users = [];
        while ($row = $stmt->fetch()) {
            $users[] = $row['user_id'];
        }
        $stmt->closeCursor();
        return $users;
    }
}
