<?php

declare(strict_types=1);

namespace OCA\GoogleCalSync\Service;

use OCA\GoogleCalSync\AppInfo\Application;
use OCA\GoogleCalSync\Db\CalendarMapping;
use OCA\GoogleCalSync\Db\CalendarMappingMapper;
use OCP\IConfig;
use OCP\IDBConnection;
use Sabre\DAV\Client as DavClient;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader as VObjectReader;

/**
 * Core sync logic.
 *
 * Sync modes
 * ----------
 * one_to_one : one Google calendar ↔ one Nextcloud calendar
 *              read_only=false → bidirectional (default)
 *              read_only=true  → Google → Nextcloud only
 *
 * many_to_one : multiple Google calendars → one Nextcloud calendar (always read-only pull)
 */
class CalendarSyncService {

    public function __construct(
        private GoogleOAuthService $oauthService,
        private CalendarMappingMapper $mappingMapper,
        private IConfig $config,
        private IDBConnection $db,
    ) {}

    // =========================================================================
    // Calendar discovery
    // =========================================================================

    public function listGoogleCalendars(string $userId): array {
        $client  = $this->oauthService->getAuthenticatedClient($userId);
        $service = new \Google\Service\Calendar($client);
        $list    = $service->calendarList->listCalendarList();
        $result  = [];
        foreach ($list->getItems() as $item) {
            $result[] = [
                'id'          => $item->getId(),
                'summary'     => $item->getSummary(),
                'description' => $item->getDescription(),
                'primary'     => $item->getPrimary() ?? false,
                'color'       => $item->getBackgroundColor(),
            ];
        }
        return $result;
    }

    public function listNextcloudCalendars(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select(['id', 'uri', 'displayname', 'principaluri'])
           ->from('calendars')
           ->where($qb->expr()->like('principaluri', $qb->createNamedParameter('%principals/users/' . $userId)));

        $stmt   = $qb->executeQuery();
        $result = [];
        while ($row = $stmt->fetch()) {
            $result[] = [
                'id'          => (int) $row['id'],
                'uri'         => $row['uri'],
                'displayname' => $row['displayname'],
                'principaluri'=> $row['principaluri'],
            ];
        }
        $stmt->closeCursor();
        return $result;
    }

    // =========================================================================
    // Full user sync (called by background job)
    // =========================================================================

    public function syncUser(string $userId): array {
        $mappings = $this->mappingMapper->findDue($userId);
        $results  = [];
        foreach ($mappings as $mapping) {
            try {
                $results[] = $this->syncMapping($userId, $mapping);
            } catch (\Exception $e) {
                $results[] = [
                    'mapping_id' => $mapping->getId(),
                    'error'      => $e->getMessage(),
                ];
            }
        }
        return $results;
    }

    // =========================================================================
    // Per-mapping sync
    // =========================================================================

    private function syncMapping(string $userId, CalendarMapping $mapping): array {
        $client      = $this->oauthService->getAuthenticatedClient($userId);
        $gcalService = new \Google\Service\Calendar($client);

        $googleIds     = json_decode($mapping->getGoogleCalendarIds(), true);
        $ncCalendarUri = $mapping->getNextcloudCalendar();
        $readOnly      = (bool) $mapping->getReadOnly();
        $mode          = $mapping->getMode();

        $stats = ['pulled' => 0, 'pushed' => 0, 'deleted' => 0, 'errors' => []];

        // --- Pull from Google → Nextcloud ---
        foreach ($googleIds as $googleCalId) {
            try {
                $pulled = $this->pullFromGoogle($userId, $gcalService, $googleCalId, $ncCalendarUri, $mapping);
                $stats['pulled'] += $pulled;
            } catch (\Exception $e) {
                $stats['errors'][] = "Pull $googleCalId: " . $e->getMessage();
            }
        }

        // --- Push from Nextcloud → Google (only for one_to_one non-read-only) ---
        if ($mode === 'one_to_one' && !$readOnly) {
            try {
                $pushed = $this->pushToGoogle($userId, $gcalService, $googleIds[0], $ncCalendarUri, $mapping);
                $stats['pushed'] += $pushed;
            } catch (\Exception $e) {
                $stats['errors'][] = 'Push: ' . $e->getMessage();
            }
        }

        // Update last_synced_at
        $mapping->setLastSyncedAt((new \DateTime())->format('Y-m-d H:i:s'));
        $this->mappingMapper->update($mapping);

        return array_merge(['mapping_id' => $mapping->getId()], $stats);
    }

    // =========================================================================
    // Pull: Google → Nextcloud
    // =========================================================================

    private function pullFromGoogle(
        string $userId,
        \Google\Service\Calendar $gcalService,
        string $googleCalId,
        string $ncCalendarUri,
        CalendarMapping $mapping,
    ): int {
        $syncToken = $mapping->getGoogleSyncToken();
        $count     = 0;

        $params = [
            'singleEvents' => true,
            'maxResults'   => 2500,
        ];
        if ($syncToken) {
            $params['syncToken'] = $syncToken;
        } else {
            // Full sync: events from 6 months ago to 12 months ahead
            $params['timeMin'] = (new \DateTime('-6 months'))->format(\DateTime::RFC3339);
            $params['timeMax'] = (new \DateTime('+12 months'))->format(\DateTime::RFC3339);
        }

        try {
            $events = $gcalService->events->listEvents($googleCalId, $params);
        } catch (\Google\Service\Exception $e) {
            if ($e->getCode() === 410) {
                // Sync token expired — full sync
                $mapping->setGoogleSyncToken(null);
                $params = [
                    'singleEvents' => true,
                    'maxResults'   => 2500,
                    'timeMin'      => (new \DateTime('-6 months'))->format(\DateTime::RFC3339),
                    'timeMax'      => (new \DateTime('+12 months'))->format(\DateTime::RFC3339),
                ];
                $events = $gcalService->events->listEvents($googleCalId, $params);
            } else {
                throw $e;
            }
        }

        foreach ($events->getItems() as $event) {
            if ($event->getStatus() === 'cancelled') {
                $this->deleteNextcloudEvent($userId, $ncCalendarUri, $event->getId());
                continue;
            }
            $vcal = $this->googleEventToVCal($event);
            $this->upsertNextcloudEvent($userId, $ncCalendarUri, $event->getId(), $vcal);
            $count++;
        }

        // Persist new sync token
        $newToken = $events->getNextSyncToken();
        if ($newToken) {
            $mapping->setGoogleSyncToken($newToken);
        }

        return $count;
    }

    // =========================================================================
    // Push: Nextcloud → Google
    // =========================================================================

    private function pushToGoogle(
        string $userId,
        \Google\Service\Calendar $gcalService,
        string $googleCalId,
        string $ncCalendarUri,
        CalendarMapping $mapping,
    ): int {
        $lastSync = $mapping->getLastSyncedAt();
        $count    = 0;

        // Find Nextcloud events modified since last sync
        $ncCalId = $this->getNextcloudCalendarId($userId, $ncCalendarUri);
        if ($ncCalId === null) {
            return 0;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select(['uri', 'calendardata', 'uid'])
           ->from('calendarobjects')
           ->where($qb->expr()->eq('calendarid', $qb->createNamedParameter($ncCalId)));

        if ($lastSync) {
            $ts = (new \DateTime($lastSync))->getTimestamp();
            $qb->andWhere($qb->expr()->gte('lastmodified', $qb->createNamedParameter($ts)));
        }

        $stmt = $qb->executeQuery();
        while ($row = $stmt->fetch()) {
            try {
                $vcal  = VObjectReader::read($row['calendardata']);
                $vevent = $vcal->VEVENT ?? null;
                if (!$vevent) {
                    continue;
                }

                $uid      = (string) $vevent->UID;
                $gEventId = $this->mapNextcloudUidToGoogleId($uid);

                $gEvent = $this->vcalToGoogleEvent($vevent);

                if ($gEventId) {
                    try {
                        $gcalService->events->patch($googleCalId, $gEventId, $gEvent);
                    } catch (\Google\Service\Exception $e) {
                        if ($e->getCode() === 404) {
                            $created = $gcalService->events->insert($googleCalId, $gEvent);
                            $this->saveUidToGoogleIdMapping($uid, $created->getId());
                        } else {
                            throw $e;
                        }
                    }
                } else {
                    $created = $gcalService->events->insert($googleCalId, $gEvent);
                    $this->saveUidToGoogleIdMapping($uid, $created->getId());
                }
                $count++;
            } catch (\Exception $e) {
                // Log and continue
            }
        }
        $stmt->closeCursor();

        return $count;
    }

    // =========================================================================
    // Nextcloud CalDAV helpers (direct DB access for speed)
    // =========================================================================

    private function upsertNextcloudEvent(string $userId, string $calendarUri, string $googleId, string $icalData): void {
        $calId = $this->getNextcloudCalendarId($userId, $calendarUri);
        if ($calId === null) {
            return;
        }

        $filename = 'gcalsync_' . md5($googleId) . '.ics';
        $size     = strlen($icalData);
        $now      = time();

        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from('calendarobjects')
           ->where($qb->expr()->eq('calendarid', $qb->createNamedParameter($calId)))
           ->andWhere($qb->expr()->eq('uri', $qb->createNamedParameter($filename)));
        $stmt = $qb->executeQuery();
        $row  = $stmt->fetch();
        $stmt->closeCursor();

        if ($row) {
            $qb2 = $this->db->getQueryBuilder();
            $qb2->update('calendarobjects')
                ->set('calendardata',  $qb2->createNamedParameter($icalData))
                ->set('lastmodified',  $qb2->createNamedParameter($now))
                ->set('size',          $qb2->createNamedParameter($size))
                ->set('etag',          $qb2->createNamedParameter(md5($icalData)))
                ->where($qb2->expr()->eq('id', $qb2->createNamedParameter($row['id'])))
                ->executeStatement();
        } else {
            $qb2 = $this->db->getQueryBuilder();
            $qb2->insert('calendarobjects')
                ->values([
                    'calendarid'   => $qb2->createNamedParameter($calId),
                    'uri'          => $qb2->createNamedParameter($filename),
                    'calendardata' => $qb2->createNamedParameter($icalData),
                    'lastmodified' => $qb2->createNamedParameter($now),
                    'size'         => $qb2->createNamedParameter($size),
                    'etag'         => $qb2->createNamedParameter(md5($icalData)),
                    'componenttype'=> $qb2->createNamedParameter('VEVENT'),
                    'uid'          => $qb2->createNamedParameter($googleId),
                ])
                ->executeStatement();
        }

        // Bump calendar's ctag so clients see the change
        $this->db->getQueryBuilder()
            ->update('calendars')
            ->set('synctoken', $this->db->getQueryBuilder()->createNamedParameter((string)(time())))
            ->where($this->db->getQueryBuilder()->expr()->eq('id', $this->db->getQueryBuilder()->createNamedParameter($calId)))
            ->executeStatement();
    }

    private function deleteNextcloudEvent(string $userId, string $calendarUri, string $googleId): void {
        $calId    = $this->getNextcloudCalendarId($userId, $calendarUri);
        if ($calId === null) {
            return;
        }
        $filename = 'gcalsync_' . md5($googleId) . '.ics';
        $this->db->getQueryBuilder()
            ->delete('calendarobjects')
            ->where($this->db->getQueryBuilder()->expr()->eq('calendarid', $this->db->getQueryBuilder()->createNamedParameter($calId)))
            ->andWhere($this->db->getQueryBuilder()->expr()->eq('uri', $this->db->getQueryBuilder()->createNamedParameter($filename)))
            ->executeStatement();
    }

    private function getNextcloudCalendarId(string $userId, string $calendarUri): ?int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from('calendars')
           ->where($qb->expr()->like('principaluri', $qb->createNamedParameter('%principals/users/' . $userId)))
           ->andWhere($qb->expr()->eq('uri', $qb->createNamedParameter($calendarUri)));
        $stmt = $qb->executeQuery();
        $row  = $stmt->fetch();
        $stmt->closeCursor();
        return $row ? (int) $row['id'] : null;
    }

    // =========================================================================
    // iCal / Google Event conversion
    // =========================================================================

    private function googleEventToVCal(\Google\Service\Calendar\Event $event): string {
        $vcal  = new VCalendar();
        $vevent = $vcal->add('VEVENT');

        $vevent->add('UID',     'gcalsync_' . $event->getId() . '@googlecalsync');
        $vevent->add('SUMMARY', $event->getSummary() ?? '(no title)');

        if ($event->getDescription()) {
            $vevent->add('DESCRIPTION', $event->getDescription());
        }
        if ($event->getLocation()) {
            $vevent->add('LOCATION', $event->getLocation());
        }

        $start = $event->getStart();
        $end   = $event->getEnd();

        if ($start->getDateTime()) {
            $vevent->add('DTSTART', new \DateTime($start->getDateTime()));
            $vevent->add('DTEND',   new \DateTime($end->getDateTime()));
        } else {
            // All-day event
            $vevent->add('DTSTART;VALUE=DATE', new \DateTime($start->getDate()));
            $vevent->add('DTEND;VALUE=DATE',   new \DateTime($end->getDate()));
        }

        $vevent->add('DTSTAMP',      new \DateTime());
        $vevent->add('LAST-MODIFIED', new \DateTime($event->getUpdated() ?? 'now'));
        $vevent->add('STATUS', strtoupper($event->getStatus() ?? 'confirmed'));

        return $vcal->serialize();
    }

    private function vcalToGoogleEvent(\Sabre\VObject\Component\VEvent $vevent): \Google\Service\Calendar\Event {
        $gEvent = new \Google\Service\Calendar\Event();
        $gEvent->setSummary((string) ($vevent->SUMMARY ?? ''));

        if ($vevent->DESCRIPTION) {
            $gEvent->setDescription((string) $vevent->DESCRIPTION);
        }
        if ($vevent->LOCATION) {
            $gEvent->setLocation((string) $vevent->LOCATION);
        }

        $dtstart = $vevent->DTSTART;
        $dtend   = $vevent->DTEND;

        if ($dtstart && $dtend) {
            $isAllDay = $dtstart->getValueType() === 'DATE';
            $start    = new \Google\Service\Calendar\EventDateTime();
            $end      = new \Google\Service\Calendar\EventDateTime();

            if ($isAllDay) {
                $start->setDate($dtstart->getDateTime()->format('Y-m-d'));
                $end->setDate($dtend->getDateTime()->format('Y-m-d'));
            } else {
                $start->setDateTime($dtstart->getDateTime()->format(\DateTime::RFC3339));
                $start->setTimeZone($dtstart->getDateTime()->getTimezone()->getName());
                $end->setDateTime($dtend->getDateTime()->format(\DateTime::RFC3339));
                $end->setTimeZone($dtend->getDateTime()->getTimezone()->getName());
            }

            $gEvent->setStart($start);
            $gEvent->setEnd($end);
        }

        return $gEvent;
    }

    // =========================================================================
    // UID ↔ Google ID mapping (stored in oc_appconfig-style user preference)
    // =========================================================================

    private function mapNextcloudUidToGoogleId(string $uid): ?string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('google_id')
           ->from('googlecalsync_uid_map')
           ->where($qb->expr()->eq('nc_uid', $qb->createNamedParameter($uid)));
        $stmt = $qb->executeQuery();
        $row  = $stmt->fetch();
        $stmt->closeCursor();
        return $row ? $row['google_id'] : null;
    }

    private function saveUidToGoogleIdMapping(string $uid, string $googleId): void {
        $existing = $this->mapNextcloudUidToGoogleId($uid);
        if ($existing) {
            $this->db->getQueryBuilder()
                ->update('googlecalsync_uid_map')
                ->set('google_id', $this->db->getQueryBuilder()->createNamedParameter($googleId))
                ->where($this->db->getQueryBuilder()->expr()->eq('nc_uid', $this->db->getQueryBuilder()->createNamedParameter($uid)))
                ->executeStatement();
        } else {
            $this->db->getQueryBuilder()
                ->insert('googlecalsync_uid_map')
                ->values([
                    'nc_uid'    => $this->db->getQueryBuilder()->createNamedParameter($uid),
                    'google_id' => $this->db->getQueryBuilder()->createNamedParameter($googleId),
                ])
                ->executeStatement();
        }
    }
}
