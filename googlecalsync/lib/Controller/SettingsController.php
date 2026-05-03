<?php

declare(strict_types=1);

namespace OCA\GoogleCalSync\Controller;

use OCA\GoogleCalSync\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;

class SettingsController extends Controller {

    public function __construct(
        IRequest $request,
        private IConfig $config,
        private string $userId,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * @NoAdminRequired
     */
    public function get(): JSONResponse {
        return new JSONResponse([
            'default_sync_interval_minutes' => (int) $this->config->getUserValue(
                $this->userId, Application::APP_ID, 'default_sync_interval_minutes', '5'
            ),
        ]);
    }

    /**
     * @NoAdminRequired
     */
    public function save(): JSONResponse {
        $interval = (int) $this->request->getParam('default_sync_interval_minutes', 5);
        $interval = max(1, $interval);

        $this->config->setUserValue(
            $this->userId, Application::APP_ID, 'default_sync_interval_minutes', (string) $interval
        );

        return new JSONResponse(['success' => true, 'default_sync_interval_minutes' => $interval]);
    }
}
