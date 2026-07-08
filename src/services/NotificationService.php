<?php
/**
 * Login Lockdown plugin for Craft CMS 5.x
 *
 * @link      https://supergeekery.com
 * @copyright Copyright (c) 2024 John F Morton
 */

declare(strict_types=1);

namespace johnfmorton\loginlockdown\services;

use Craft;
use craft\base\Component;
use johnfmorton\loginlockdown\LoginLockdown;

/**
 * Notification Service
 *
 * Handles email and Pushover notifications when IPs are blocked.
 *
 * @author    John F Morton
 * @package   LoginLockdown
 * @since     1.0.0
 */
class NotificationService extends Component
{
    /**
     * Send notifications when an IP is blocked
     *
     * @param string $ipAddress The blocked IP address
     * @param int $attemptCount Number of failed attempts
     * @param string|null $username Last attempted username
     */
    public function sendBlockNotification(string $ipAddress, int $attemptCount, ?string $username = null): void
    {
        $settings = LoginLockdown::$plugin->getSettings();

        if (!$settings->getNotifyOnBlockParsed()) {
            return;
        }

        $siteName = Craft::$app->getSystemName();
        $message = $this->buildNotificationMessage($ipAddress, $attemptCount, $username, $siteName);

        // Send email notification
        $notifyEmail = $settings->getNotifyEmailParsed();
        if (!empty($notifyEmail)) {
            $this->sendEmailNotification($notifyEmail, $siteName, $message);
        }

        // Send Pushover notification
        $pushoverUserKey = $settings->getPushoverUserKeyParsed();
        $pushoverApiToken = $settings->getPushoverApiTokenParsed();
        if ($settings->getPushoverEnabledParsed() && !empty($pushoverUserKey) && !empty($pushoverApiToken)) {
            $this->sendPushoverNotification(
                $pushoverUserKey,
                $pushoverApiToken,
                $message,
                $siteName
            );
        }
    }

    /**
     * Build the notification message
     */
    private function buildNotificationMessage(
        string $ipAddress,
        int $attemptCount,
        ?string $username,
        string $siteName,
    ): string {
        $settings = LoginLockdown::$plugin->getSettings();
        $lockoutDuration = $settings->getLockoutDurationParsed();
        $blockedUntil = date('Y-m-d H:i:s T', time() + $lockoutDuration);

        $message = "Login Lockdown has blocked IP address {$ipAddress} on {$siteName}.\n\n";
        $message .= "Details:\n";
        $message .= "- Failed attempts: {$attemptCount}\n";

        if ($username) {
            $message .= "- Last attempted username: {$username}\n";
        }

        $message .= "- Blocked at: " . date('Y-m-d H:i:s T') . "\n";
        $message .= "- Block expires: {$blockedUntil}\n";

        return $message;
    }

    /**
     * Send email notification
     */
    private function sendEmailNotification(string $email, string $siteName, string $message): void
    {
        try {
            Craft::$app->getMailer()
                ->compose()
                ->setTo($email)
                ->setSubject("[{$siteName}] Login Lockdown - IP Blocked")
                ->setTextBody($message)
                ->send();

            Craft::info("Login Lockdown: Email notification sent to {$email}", __METHOD__);
        } catch (\Throwable $e) {
            Craft::error("Login Lockdown: Failed to send email notification: " . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Send a test Pushover notification to verify configuration
     *
     * @return array{success: bool, message: string}
     */
    public function sendTestPushoverNotification(): array
    {
        $settings = LoginLockdown::$plugin->getSettings();

        if (!$settings->getPushoverEnabledParsed()) {
            return [
                'success' => false,
                'message' => Craft::t('login-lockdown', 'Pushover notifications are not enabled.'),
            ];
        }

        $userKey = $settings->getPushoverUserKeyParsed();
        $apiToken = $settings->getPushoverApiTokenParsed();

        if (empty($userKey) || empty($apiToken)) {
            return [
                'success' => false,
                'message' => Craft::t('login-lockdown', 'Pushover User Key and API Token are required.'),
            ];
        }

        $siteName = Craft::$app->getSystemName();
        $message = Craft::t(
            'login-lockdown',
            'This is a test notification from Login Lockdown on {siteName}. Your Pushover configuration is working correctly!',
            ['siteName' => $siteName]
        );

        try {
            $ch = curl_init('https://api.pushover.net/1/messages.json');

            if ($ch === false) {
                return [
                    'success' => false,
                    'message' => Craft::t('login-lockdown', 'Failed to initialize cURL.'),
                ];
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => [
                    'token' => $apiToken,
                    'user' => $userKey,
                    'message' => $message,
                    'title' => Craft::t('login-lockdown', 'Login Lockdown Test - {siteName}', ['siteName' => $siteName]),
                    'priority' => 0,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                Craft::info('Login Lockdown: Test Pushover notification sent successfully', __METHOD__);
                return [
                    'success' => true,
                    'message' => Craft::t('login-lockdown', 'Test notification sent successfully!'),
                ];
            }

            $errorMessage = 'Unknown error';
            if ($response) {
                $responseData = json_decode($response, true);
                if (isset($responseData['errors']) && is_array($responseData['errors'])) {
                    $errorMessage = implode(', ', $responseData['errors']);
                }
            }

            Craft::error("Login Lockdown: Test Pushover notification failed with HTTP {$httpCode}: {$response}", __METHOD__);
            return [
                'success' => false,
                'message' => Craft::t('login-lockdown', 'Pushover API error: {error}', ['error' => $errorMessage]),
            ];
        } catch (\Throwable $e) {
            Craft::error("Login Lockdown: Failed to send test Pushover notification: " . $e->getMessage(), __METHOD__);
            return [
                'success' => false,
                'message' => Craft::t('login-lockdown', 'Error: {error}', ['error' => $e->getMessage()]),
            ];
        }
    }

    /**
     * Send Pushover notification
     */
    private function sendPushoverNotification(
        string $userKey,
        string $apiToken,
        string $message,
        string $siteName,
    ): void {
        try {
            $ch = curl_init('https://api.pushover.net/1/messages.json');

            if ($ch === false) {
                Craft::error('Login Lockdown: Failed to initialize cURL for Pushover', __METHOD__);
                return;
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => [
                    'token' => $apiToken,
                    'user' => $userKey,
                    'message' => $message,
                    'title' => "Login Lockdown Alert - {$siteName}",
                    'priority' => 0,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                Craft::info('Login Lockdown: Pushover notification sent successfully', __METHOD__);
            } else {
                Craft::error("Login Lockdown: Pushover notification failed with HTTP {$httpCode}: {$response}", __METHOD__);
            }
        } catch (\Throwable $e) {
            Craft::error("Login Lockdown: Failed to send Pushover notification: " . $e->getMessage(), __METHOD__);
        }
    }
}
