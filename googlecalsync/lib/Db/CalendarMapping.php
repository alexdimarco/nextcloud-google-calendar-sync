<?php

declare(strict_types=1);

namespace OCA\GoogleCalSync\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string  getUserId()
 * @method void    setUserId(string $userId)
 * @method string  getMode()
 * @method void    setMode(string $mode)
 * @method string  getGoogleCalendarIds()
 * @method void    setGoogleCalendarIds(string $json)
 * @method string  getNextcloudCalendar()
 * @method void    setNextcloudCalendar(string $uri)
 * @method int     getReadOnly()
 * @method void    setReadOnly(int $readOnly)
 * @method int     getSyncIntervalMinutes()
 * @method void    setSyncIntervalMinutes(int $minutes)
 * @method ?string getLastSyncedAt()
 * @method void    setLastSyncedAt(?string $ts)
 * @method ?string getGoogleSyncToken()
 * @method void    setGoogleSyncToken(?string $token)
 */
class CalendarMapping extends Entity {

    protected string  $userId             = '';
    protected string  $mode               = 'one_to_one';
    protected string  $googleCalendarIds  = '[]';
    protected string  $nextcloudCalendar  = '';
    protected int     $readOnly           = 0;
    protected int     $syncIntervalMinutes = 5;
    protected ?string $lastSyncedAt       = null;
    protected ?string $googleSyncToken    = null;

    public function toArray(): array {
        return [
            'id'                    => $this->getId(),
            'user_id'               => $this->getUserId(),
            'mode'                  => $this->getMode(),
            'google_calendar_ids'   => json_decode($this->getGoogleCalendarIds(), true),
            'nextcloud_calendar'    => $this->getNextcloudCalendar(),
            'read_only'             => (bool) $this->getReadOnly(),
            'sync_interval_minutes' => $this->getSyncIntervalMinutes(),
            'last_synced_at'        => $this->getLastSyncedAt(),
        ];
    }
}
