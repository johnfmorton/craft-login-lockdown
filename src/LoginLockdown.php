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
use johnfmorton\loginlockdown\http\BlockedIpMiddleware;
use johnfmorton\loginlockdown\models\Settings;
use johnfmorton\loginlockdown\services\NotificationService;
use johnfmorton\loginlockdown\services\ProtectionService;
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

            // Register blocking of login attempts from blocked IPs. Craft 6
            // handles logins in Laravel routes, which never fire Yii's
            // EVENT_BEFORE_ACTION, so it gets route middleware instead.
            if (self::isCraft6()) {
                BlockedIpMiddleware::register();
            } else {
                $this->registerCpAccessBlocking();
            }
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
                // `user` is null when the login name matches no account (Craft 6 passes
                // null through the yii2 adapter), so keep the null-safe access even
                // though Craft 5's types say it's never null.
                // @phpstan-ignore nullsafe.neverNull
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

                // Is this a front-end login attempt? Inspect the *resolved* action
                // rather than only the `action` body param: Craft accepts the action
                // via the body param, the query string, or the `/actions/users/login`
                // path, and only the resolved action id is invariant across all of
                // those forms. Trusting the body param alone let a blocked IP keep
                // attempting front-end logins by switching to the path/query form.
                $isFrontEndLogin = $event->action->getUniqueId() === 'users/login';

                if (!$this->refuseBlockedLoginRequest(
                    $request->getIsCpRequest(),
                    $isFrontEndLogin,
                    $request->getIsPost(),
                    $request->getBodyParam('loginName'),
                    $request->getUserAgent(),
                )) {
                    return;
                }

                // Block login POST attempts - Craft's login form uses AJAX,
                // so sendBlockedResponse() will return JSON that displays as an error
                $this->sendBlockedResponse();
            }
        );
    }

    /**
     * Whether a request must be refused because it's a login attempt from a
     * blocked IP. When it must, the attempt is recorded and the block extended.
     *
     * Shared by the Craft 5 enforcement (a Yii EVENT_BEFORE_ACTION handler) and
     * the Craft 6 enforcement (BlockedIpMiddleware), so both apply the same
     * rules: only POSTs to the control panel, or to a front-end login action
     * when `protectFrontEndLogin` is on; already logged-in users always pass.
     */
    public function refuseBlockedLoginRequest(
        bool $isCpRequest,
        bool $isFrontEndLogin,
        bool $isPost,
        ?string $loginName,
        ?string $userAgent,
    ): bool {
        $isFrontEndLogin = $isFrontEndLogin && !$isCpRequest && $this->getSettings()->getProtectFrontEndLoginParsed();

        // Only check CP requests or front-end login attempts
        if (!$isCpRequest && !$isFrontEndLogin) {
            return false;
        }

        // Allow GET requests (so a blocked IP can still see the login page)
        if (!$isPost) {
            return false;
        }

        $ipAddress = $this->protectionService->getClientIp();

        // If IP is not blocked, allow everything
        if (!$this->protectionService->isBlocked($ipAddress)) {
            return false;
        }

        // Allow already logged-in users to continue working.
        // Brute force protection prevents unauthorized access, not authenticated sessions.
        if (Craft::$app->getUser()->getIdentity()) {
            return false;
        }

        // Record this attempt and extend the block timer
        try {
            $this->protectionService->recordBlockedAttemptAndExtend($ipAddress, $loginName, $userAgent);
        } catch (\Throwable $e) {
            Craft::error(
                "Login Lockdown: Error recording blocked attempt: " . $e->getMessage(),
                __METHOD__
            );
        }

        return true;
    }

    /**
     * Whether Craft CMS 6 (running this plugin through the yii2 adapter) is installed.
     */
    public static function isCraft6(): bool
    {
        return class_exists('CraftCms\\Cms\\Cms');
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
    public function renderBlockedHtml(string $blockMessage): string
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
