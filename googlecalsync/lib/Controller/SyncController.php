<?php

declare(strict_types=1);

namespace OCA\GoogleCalSync\Controller;

use OCA\GoogleCalSync\AppInfo\Application;
use OCA\GoogleCalSync\Db\CalendarMappingMapper;
use OCA\GoogleCalSync\Db\CalendarMapping;
use OCA\GoogleCalSync\Service\CalendarSyncService;
use OCA\GoogleCalSync\Service\GoogleOAuthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class SyncController extends Controller {

    public function __construct(
        IRequest $request,
        private CalendarSyncService $syncService,
        private GoogleOAuthService $oauthService,
        private CalendarMappingMapper $mappingMapper,
        private string $userId,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * @NoAdminRequired
     * List calendars visible to the authenticated Google account.
     */
    public function listGoogleCalendars(): JSONResponse {
        if (!$this->oauthService->isConnected($this->userId)) {
            return new JSONResponse(['error' => 'Not connected to Google'], 401);
        }
        try {
            $calendars = $this->syncService->listGoogleCalendars($this->userId);
            return new JSONResponse(['calendars' => $calendars]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @NoAdminRequired
     * List Nextcloud CalDAV calendars owned by this user.
     */
    public function listNextcloudCalendars(): JSONResponse {
        try {
            $calendars = $this->syncService->listNextcloudCalendars($this->userId);
            return new JSONResponse(['calendars' => $calendars]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @NoAdminRequired
     * Return all calendar mappings for this user.
     */
    public function getMappings(): JSONResponse {
        $mappings = $this->mappingMapper->findAllForUser($this->userId);
        return new JSONResponse(['mappings' => array_map(fn($m) => $m->toArray(), $mappings)]);
    }

    /**
     * @NoAdminRequired
     * Create or update a calendar mapping.
     *
     * Body params:
     *   id                  (int|null)    – omit or null to create
     *   mode                (string)      – "one_to_one" | "many_to_one"
     *   google_calendar_ids (string[])    – one or more Google calendar IDs
     *   nextcloud_calendar  (string)      – Nextcloud calendar URI
     *   read_only           (bool)        – true = only pull from Google, no push
     *   sync_interval_minutes (int)       – override interval (min 1)
     */
    public function saveMapping(): JSONResponse {
        $id                   = $this->request->getParam('id');
        $mode                 = $this->request->getParam('mode', 'one_to_one');
        $googleCalendarIds    = $this->request->getParam('google_calendar_ids', []);
        $nextcloudCalendar    = $this->request->getParam('nextcloud_calendar');
        $readOnly             = (bool) $this->request->getParam('read_only', false);
        $syncIntervalMinutes  = (int)  $this->request->getParam('sync_interval_minutes', 5);

        if (empty($googleCalendarIds) || empty($nextcloudCalendar)) {
            return new JSONResponse(['error' => 'google_calendar_ids and nextcloud_calendar are required'], 400);
        }

        if (!in_array($mode, ['one_to_one', 'many_to_one'], true)) {
            return new JSONResponse(['error' => 'mode must be one_to_one or many_to_one'], 400);
        }

        if ($mode === 'one_to_one' && count($googleCalendarIds) > 1) {
            return new JSONResponse(['error' => 'one_to_one mode only supports a single Google calendar'], 400);
        }

        $syncIntervalMinutes = max(1, $syncIntervalMinutes);

        if ($id) {
            $mapping = $this->mappingMapper->find((int) $id, $this->userId);
        } else {
            $mapping = new CalendarMapping();
            $mapping->setUserId($this->userId);
        }

        $mapping->setMode($mode);
        $mapping->setGoogleCalendarIds(json_encode($googleCalendarIds));
        $mapping->setNextcloudCalendar($nextcloudCalendar);
        $mapping->setReadOnly($readOnly ? 1 : 0);
        $mapping->setSyncIntervalMinutes($syncIntervalMinutes);
        $mapping->setLastSyncedAt(null);

        if ($id) {
            $mapping = $this->mappingMapper->update($mapping);
        } else {
            $mapping = $this->mappingMapper->insert($mapping);
        }

        return new JSONResponse(['mapping' => $mapping->toArray()]);
    }

    /**
     * @NoAdminRequired
     * Delete a calendar mapping.
     */
    public function deleteMapping(int $id): JSONResponse {
        try {
            $mapping = $this->mappingMapper->find($id, $this->userId);
            $this->mappingMapper->delete($mapping);
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 404);
        }
    }

    /**
     * @NoAdminRequired
     * Immediately trigger a sync for all mappings belonging to this user.
     */
    public function runNow(): JSONResponse {
        if (!$this->oauthService->isConnected($this->userId)) {
            return new JSONResponse(['error' => 'Not connected to Google'], 401);
        }
        try {
            $results = $this->syncService->syncUser($this->userId);
            return new JSONResponse(['results' => $results]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }
}
