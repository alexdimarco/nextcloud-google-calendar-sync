<?php

declare(strict_types=1);

namespace OCA\GoogleCalSync\Settings;

use OCA\GoogleCalSync\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class PersonalSettings implements ISettings {

    public function getForm(): TemplateResponse {
        return new TemplateResponse(Application::APP_ID, 'personal-settings', [], 'blank');
    }

    public function getSection(): string {
        return Application::APP_ID;
    }

    public function getPriority(): int {
        return 10;
    }
}
