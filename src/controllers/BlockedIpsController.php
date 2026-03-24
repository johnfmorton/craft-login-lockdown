<?php
/**
 * Login Lockdown plugin for Craft CMS 5.x
 *
 * @link      https://supergeekery.com
 * @copyright Copyright (c) 2024 John F Morton
 */

declare(strict_types=1);

namespace johnfmorton\loginlockdown\controllers;

use Craft;
use craft\web\Controller;
use johnfmorton\loginlockdown\LoginLockdown;
use yii\web\Response;

/**
 * Blocked IPs Controller
 *
 * Handles the CP interface for managing blocked IPs.
 *
 * @author    John F Morton
 * @package   LoginLockdown
 * @since     1.0.0
 */
class BlockedIpsController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        $this->requirePermission('accessPlugin-login-lockdown');
        return parent::beforeAction($action);
    }

    /**
     * Display the blocked IPs list
     */
    public function actionIndex(): Response
    {
        $blockedIps = LoginLockdown::$plugin->protectionService->getBlockedIps(true);

        return $this->renderTemplate('login-lockdown/index', [
            'blockedIps' => $blockedIps,
        ]);
    }

    /**
     * Unblock an IP address
     */
    public function actionUnblock(): Response
    {
        $this->requirePostRequest();

        $id = Craft::$app->getRequest()->getRequiredBodyParam('id');

        if (LoginLockdown::$plugin->protectionService->unblockById((int)$id)) {
            Craft::$app->getSession()->setNotice(Craft::t('login-lockdown', 'IP address unblocked.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('login-lockdown', 'Could not unblock IP address.'));
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * Manually block an IP address
     */
    public function actionBlock(): Response
    {
        $this->requirePostRequest();

        $ipAddress = Craft::$app->getRequest()->getRequiredBodyParam('ipAddress');

        // Validate IP address format
        if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
            Craft::$app->getSession()->setError(Craft::t('login-lockdown', 'Invalid IP address format.'));
            return $this->redirectToPostedUrl();
        }

        LoginLockdown::$plugin->protectionService->blockIp(
            $ipAddress,
            0,
            'Manually blocked by admin',
            true
        );

        Craft::$app->getSession()->setNotice(Craft::t('login-lockdown', 'IP address blocked.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Clean up old records
     */
    public function actionCleanup(): Response
    {
        $this->requirePostRequest();

        $deleted = LoginLockdown::$plugin->protectionService->cleanup();

        Craft::$app->getSession()->setNotice(
            Craft::t('login-lockdown', '{count} old records cleaned up.', ['count' => $deleted])
        );

        return $this->redirectToPostedUrl();
    }

    /**
     * Send a test Pushover notification
     */
    public function actionTestPushover(): Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();

        Craft::info('Login Lockdown: Test Pushover action called', __METHOD__);

        $result = LoginLockdown::$plugin->notificationService->sendTestPushoverNotification();

        Craft::info('Login Lockdown: Test Pushover result: ' . json_encode($result), __METHOD__);

        return $this->asJson($result);
    }
}
