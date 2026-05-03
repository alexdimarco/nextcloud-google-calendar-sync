<?php

declare(strict_types=1);

namespace OCA\GoogleCalSync\Controller;

use OCA\GoogleCalSync\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

class PageController extends Controller {

    public function __construct(
        IRequest $request,
        private string $userId,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): TemplateResponse {
        return new TemplateResponse(Application::APP_ID, 'main', [
            'user_id' => $this->userId,
        ]);
    }
}
