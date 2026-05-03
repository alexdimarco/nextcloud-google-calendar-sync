<?php

declare(strict_types=1);

namespace OCA\GoogleCalSync\AppInfo;

use OCA\GoogleCalSync\Service\CalendarSyncService;
use OCA\GoogleCalSync\Service\GoogleOAuthService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
    public const APP_ID = 'googlecalsync';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        // Services are auto-wired via constructor injection
    }

    public function boot(IBootContext $context): void {
        // Nothing needed at boot time
    }
}
