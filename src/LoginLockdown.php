<?php
/**
 * Login Lockdown plugin for Craft CMS 5.x
 *
 * Prevents brute force password attacks on the control panel by tracking
 * failed login attempts per IP address and blocking access after a
 * configurable threshold.
 *
 * @link      https://supergeekery.com
 * @copyright Copyright (c) 2024 John F Morton
 */

declare(strict_types=1);

namespace johnfmorton\loginlockdown;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\console\Application as ConsoleApplication;
use craft\controllers\UsersController;
use craft\events\LoginFailureEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use johnfmorton\loginlockdown\models\Settings;
use johnfmorton\loginlockdown\services\NotificationService;
use johnfmorton\loginlockdown\services\ProtectionService;
use yii\base\Action;
use yii\base\ActionEvent;
use yii\base\Event;
use yii\web\Response;

/**
 * Login Lockdown Plugin
 *
 * @author    John F Morton
 * @package   LoginLockdown
 * @since     1.0.0
 *
 * @property ProtectionService $protectionService
 * @property NotificationService $notificationService
 * @property Settings $settings
 * @method Settings getSettings()
 */
class LoginLockdown extends Plugin
{
    /**
     * Static property that is an instance of this plugin class
     */
    public static LoginLockdown $plugin;

    /**
     * @var string
     */
    public string $schemaVersion = '1.0.0';

    /**
     * Enable CP settings
     */
    public bool $hasCpSettings = true;

    /**
     * Enable CP section for blocked IPs management
     */
    public bool $hasCpSection = true;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        // Register services
        $this->setComponents([
            'protectionService' => ProtectionService::class,
            'notificationService' => NotificationService::class,
        ]);

        // Register console controllers
        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'johnfmorton\loginlockdown\console\controllers';
        }

        // Register CP URL rules
        $this->registerCpUrlRules();

        // Only register protection events if enabled
        if ($this->getSettings()->getEnabledParsed()) {
            // Register failed login handler
            $this->registerFailedLoginHandler();

            // Register CP access blocking
            $this->registerCpAccessBlocking();
        }

        Craft::info('Login Lockdown plugin initialized', __METHOD__);
    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem(): ?array
    {
        if (!Craft::$app->getUser()->checkPermission('accessPlugin-login-lockdown')) {
            return null;
        }

        $item = parent::getCpNavItem();

        if ($item) {
            $item['label'] = Craft::t('login-lockdown', 'Login Lockdown');
            $item['url'] = 'login-lockdown';
        }

        return $item;
    }

    /**
     * Register CP URL rules
     */
    private function registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['login-lockdown'] = 'login-lockdown/blocked-ips/index';
            }
        );
    }

    /**
     * Register handler for failed login attempts
     */
    private function registerFailedLoginHandler(): void
    {
        Event::on(
            UsersController::class,
            UsersController::EVENT_LOGIN_FAILURE,
            function(LoginFailureEvent $event) {
                // Skip console requests
                if (Craft::$app->getRequest()->getIsConsoleRequest()) {
                    return;
                }

                $ipAddress = $this->protectionService->getClientIp();
                $username = $event->user?->username ?? Craft::$app->getRequest()->getBodyParam('loginName');
                $userAgent = Craft::$app->getRequest()->getUserAgent();

                try {
                    $this->protectionService->recordFailedAttempt($ipAddress, $username, $userAgent);
                } catch (\Throwable $e) {
                    // Log the error but don't disrupt the normal login failure flow
                    // This ensures users see the normal "Invalid username or password" message
                    Craft::error(
                        "Login Lockdown: Error recording failed attempt: " . $e->getMessage(),
                        __METHOD__
                    );
                }
            }
        );
    }

    /**
     * Register handler to block login access for blocked IPs (both CP and front-end)
     */
    private function registerCpAccessBlocking(): void
    {
        Event::on(
            \yii\web\Application::class,
            \yii\web\Application::EVENT_BEFORE_ACTION,
            function(ActionEvent $event) {
                $request = Craft::$app->getRequest();

                // Skip console requests
                if ($request->getIsConsoleRequest()) {
                    return;
                }

                $isCpRequest = $request->getIsCpRequest();
                $settings = $this->getSettings();

                // Check if this is a front-end login attempt (only if setting enabled).
                // Inspect the *resolved* action rather than only the `action` body
                // param: Craft accepts the action via the body param, the query
                // string, or the `/actions/users/login` path, and only the resolved
                // action id is invariant across all of those forms. Trusting the body
                // param alone let a blocked IP keep attempting front-end logins by
                // switching to the path/query form.
                $isFrontEndLogin = false;
                if (!$isCpRequest && $request->getIsPost() && $settings->getProtectFrontEndLoginParsed()) {
                    $action = $event->action;
                    if ($action instanceof Action && $action->getUniqueId() === 'users/login') {
                        $isFrontEndLogin = true;
                    }
                }

                // Only check CP requests or front-end login attempts
                if (!$isCpRequest && !$isFrontEndLogin) {
                    return;
                }

                $ipAddress = $this->protectionService->getClientIp();

                // If IP is not blocked, allow everything
                if (!$this->protectionService->isBlocked($ipAddress)) {
                    return;
                }

                // IP is blocked - determine how to handle

                // Allow already logged-in users to continue working
                // Brute force protection prevents unauthorized access, not authenticated sessions
                $user = Craft::$app->getUser()->getIdentity();
                if ($user) {
                    return;
                }

                // Allow GET requests when not logged in (so they can see the login page)
                // This applies to both CP and front-end login pages
                if (!$request->getIsPost()) {
                    return;
                }

                // Record this attempt and extend the block timer
                $username = $request->getBodyParam('loginName');
                $userAgent = $request->getUserAgent();

                try {
                    $this->protectionService->recordBlockedAttemptAndExtend($ipAddress, $username, $userAgent);
                } catch (\Throwable $e) {
                    Craft::error(
                        "Login Lockdown: Error recording blocked attempt: " . $e->getMessage(),
                        __METHOD__
                    );
                }

                // Block login POST attempts - Craft's login form uses AJAX,
                // so sendBlockedResponse() will return JSON that displays as an error
                $this->sendBlockedResponse();
            }
        );
    }

    /**
     * Send a 403 response for blocked IPs and end the request.
     *
     * The response is built through Yii's Response component and the request is
     * terminated via Craft::$app->end() rather than raw echo/exit. This routes
     * the response through the framework's send pipeline (content-type/charset
     * formatting, security-header events, EVENT_AFTER_REQUEST) while still
     * short-circuiting the blocked action.
     */
    private function sendBlockedResponse(): void
    {
        $settings = $this->getSettings();
        $request = Craft::$app->getRequest();
        $response = Craft::$app->getResponse();

        $response->getHeaders()->set('X-Login-Lockdown', 'BLOCKED');
        $response->setStatusCode(403);

        $blockMessage = $settings->getBlockMessageParsed();

        if ($request->getAcceptsJson() || $request->getIsAjax()) {
            // Craft's login form submits via AJAX and displays this JSON as an error.
            $response->format = Response::FORMAT_JSON;
            $response->data = [
                'success' => false,
                'message' => $blockMessage,
                'error' => $blockMessage,
            ];
        } else {
            $response->format = Response::FORMAT_HTML;
            $response->data = $this->renderBlockedHtml($blockMessage);
        }

        Craft::$app->end(0, $response);
    }

    /**
     * Build the styled HTML page shown to blocked IPs on non-AJAX requests.
     */
    private function renderBlockedHtml(string $blockMessage): string
    {
        $safeMessage = htmlspecialchars($blockMessage, ENT_QUOTES, 'UTF-8');

        return '<!DOCTYPE html>'
            . '<html><head><title>Access Denied</title>'
            . '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f5f5f5;}'
            . '.message{text-align:center;padding:40px;background:white;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.1);max-width:500px;}'
            . 'h1{color:#dc2626;margin:0 0 16px;}p{color:#666;margin:0;line-height:1.6;}</style></head>'
            . '<body><div class="message">'
            . '<h1>Access Denied</h1>'
            . '<p>' . $safeMessage . '</p>'
            . '</div></body></html>';
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate(
            'login-lockdown/settings',
            [
                'settings' => $this->getSettings(),
            ]
        );
    }
}
