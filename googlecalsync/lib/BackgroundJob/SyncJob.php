<?php

declare(strict_types=1);

namespace OCA\GoogleCalSync\BackgroundJob;

use OCA\GoogleCalSync\Db\CalendarMappingMapper;
use OCA\GoogleCalSync\Service\CalendarSyncService;
use OCA\GoogleCalSync\Service\GoogleOAuthService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Runs every minute via Nextcloud's cron/webcron mechanism.
 * Each mapping stores its own sync_interval_minutes; this job checks
 * which mappings are actually due and syncs only those.
 */
class SyncJob extends TimedJob {

    public function __construct(
        ITimeFactory $time,
        private CalendarMappingMapper $mappingMapper,
        private CalendarSyncService $syncService,
        private GoogleOAuthService $oauthService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);

        // Run the job every minute so we can respect per-mapping intervals
        $this->setInterval(60);
        $this->setTimeSensitivity(\OCP\BackgroundJob\IJob::TIME_SENSITIVE);
    }

    protected function run(mixed $argument): void {
        // Get all users who have at least one mapping
        $userIds = $this->mappingMapper->findAllUsers();

        foreach ($userIds as $userId) {
            if (!$this->oauthService->isConnected($userId)) {
                continue;
            }
            try {
                $results = $this->syncService->syncUser($userId);
                foreach ($results as $r) {
                    if (!empty($r['error'])) {
                        $this->logger->error(
                            '[googlecalsync] Sync error for user {user}, mapping {mapping}: {error}',
                            ['user' => $userId, 'mapping' => $r['mapping_id'] ?? '?', 'error' => $r['error']]
                        );
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error(
                    '[googlecalsync] Sync failed for user {user}: {error}',
                    ['user' => $userId, 'error' => $e->getMessage()]
                );
            }
        }
    }
}
