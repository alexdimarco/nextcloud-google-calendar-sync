<?php

declare(strict_types=1);

namespace OCA\GoogleCalSync\Controller;

use OCA\GoogleCalSync\AppInfo\Application;
use OCA\GoogleCalSync\Service\GoogleOAuthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

class OAuthController extends Controller {

    public function __construct(
        IRequest $request,
        private GoogleOAuthService $oauthService,
        private IURLGenerator $urlGenerator,
        private string $userId,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * @NoAdminRequired
     * Begin OAuth flow — returns the Google authorization URL.
     */
    public function start(): JSONResponse {
        $credentialsJson = $this->request->getParam('credentials_json');
        if (empty($credentialsJson)) {
            return new JSONResponse(['error' => 'credentials_json is required'], 400);
        }

        try {
            $decoded = json_decode($credentialsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return new JSONResponse(['error' => 'Invalid JSON in credentials_json'], 400);
        }

        $redirectUri = $this->urlGenerator->linkToRouteAbsolute(
            Application::APP_ID . '.oauth.callback'
        );

        try {
            $authUrl = $this->oauthService->getAuthorizationUrl($this->userId, $decoded, $redirectUri);
            return new JSONResponse(['auth_url' => $authUrl]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     * Google redirects here after the user grants access.
     */
    public function callback(): RedirectResponse {
        $code  = $this->request->getParam('code');
        $state = $this->request->getParam('state');
        $error = $this->request->getParam('error');

        $settingsUrl = $this->urlGenerator->linkToRoute(
            Application::APP_ID . '.page.index'
        );

        if ($error) {
            return new RedirectResponse($settingsUrl . '?oauth_error=' . urlencode($error));
        }

        $redirectUri = $this->urlGenerator->linkToRouteAbsolute(
            Application::APP_ID . '.oauth.callback'
        );

        try {
            $this->oauthService->handleCallback($this->userId, $code, $state, $redirectUri);
        } catch (\Exception $e) {
            return new RedirectResponse($settingsUrl . '?oauth_error=' . urlencode($e->getMessage()));
        }

        return new RedirectResponse($settingsUrl . '?oauth_success=1');
    }

    /**
     * @NoAdminRequired
     * Revoke stored tokens and credentials.
     */
    public function disconnect(): JSONResponse {
        try {
            $this->oauthService->disconnect($this->userId);
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @NoAdminRequired
     * Return connection status for the current user.
     */
    public function status(): JSONResponse {
        $connected = $this->oauthService->isConnected($this->userId);
        $email     = $connected ? $this->oauthService->getConnectedEmail($this->userId) : null;
        return new JSONResponse([
            'connected' => $connected,
            'email'     => $email,
        ]);
    }
}
