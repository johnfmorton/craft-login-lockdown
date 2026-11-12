<?php
/**
 * Login Lockdown plugin for Craft CMS
 *
 * @link      https://supergeekery.com
 * @copyright Copyright (c) 2024 John F Morton
 */

declare(strict_types=1);

namespace johnfmorton\loginlockdown\http;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use johnfmorton\loginlockdown\LoginLockdown;

/**
 * Refuses login attempts from blocked IPs on Craft CMS 6.
 *
 * Craft 6 handles logins in Laravel routes, which never fire the Yii
 * EVENT_BEFORE_ACTION that enforces blocks on Craft 5. This middleware runs
 * on Craft's control panel and site route groups instead, so a blocked IP is
 * refused before Craft's login controller (or any other CP action) runs.
 * The decision itself is LoginLockdown::refuseBlockedLoginRequest(), shared
 * with the Craft 5 path.
 *
 * Only loaded on Craft 6: it depends on Laravel classes that Craft 5 doesn't
 * have, so it's excluded from PHPStan's Craft 5 analysis (see phpstan.neon).
 */
class BlockedIpMiddleware
{
    /**
     * Controller actions that log a user in from the front end.
     */
    private const FRONT_END_LOGIN_ACTIONS = [
        'CraftCms\\Cms\\Http\\Controllers\\Auth\\LoginController@attemptLogin',
        'CraftCms\\Cms\\Http\\Controllers\\Auth\\PasskeyController@login',
    ];

    /**
     * Add this middleware to Craft's control panel and site route groups.
     */
    public static function register(): void
    {
        $router = app(Router::class);
        foreach (['craft.cp', 'craft.web'] as $group) {
            $router->pushMiddlewareToGroup($group, self::class);
        }
    }

    /**
     * The client IP on Craft 6, from Laravel's request: forwarded headers are
     * honored only from proxies trusted in `bootstrap/app.php`
     * (`$middleware->trustProxies(...)`), never from the client itself.
     */
    public static function clientIp(): ?string
    {
        return app(Request::class)->ip();
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $plugin = LoginLockdown::$plugin;
        $route = $request->route();
        $routeMiddleware = is_object($route) ? $route->middleware() : [];
        $actionName = is_object($route) ? $route->getActionName() : '';

        $refuse = $plugin->refuseBlockedLoginRequest(
            in_array('craft.cp', $routeMiddleware, true),
            in_array($actionName, self::FRONT_END_LOGIN_ACTIONS, true),
            $request->isMethod('POST'),
            $request->input('loginName'),
            $request->userAgent(),
        );

        if (!$refuse) {
            return $next($request);
        }

        $blockMessage = $plugin->getSettings()->getBlockMessageParsed();
        $headers = ['X-Login-Lockdown' => 'BLOCKED'];

        if ($request->expectsJson()) {
            // Craft's login form submits via AJAX and displays this JSON as an error.
            return new JsonResponse([
                'success' => false,
                'message' => $blockMessage,
                'error' => $blockMessage,
            ], 403, $headers);
        }

        return new Response($plugin->renderBlockedHtml($blockMessage), 403, $headers);
    }
}
