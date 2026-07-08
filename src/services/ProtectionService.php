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
use craft\db\Query;
use DateTime;
use johnfmorton\loginlockdown\LoginLockdown;
use johnfmorton\loginlockdown\records\BlockedIpRecord;
use johnfmorton\loginlockdown\records\LoginAttemptRecord;

/**
 * Protection Service
 *
 * Core service for tracking login attempts and managing IP blocks.
 *
 * @author    John F Morton
 * @package   LoginLockdown
 * @since     1.0.0
 */
class ProtectionService extends Component
{
    /**
     * Record a failed login attempt
     *
     * @param string $ipAddress The IP address
     * @param string|null $username The attempted username
     * @param string|null $userAgent The user agent
     * @return bool Whether the IP should now be blocked
     */
    public function recordFailedAttempt(string $ipAddress, ?string $username = null, ?string $userAgent = null): bool
    {
        $settings = LoginLockdown::$plugin->getSettings();

        // Don't record if protection is disabled
        if (!$settings->getEnabledParsed()) {
            return false;
        }

        // Don't record if IP is whitelisted
        if ($this->isWhitelisted($ipAddress)) {
            return false;
        }

        // Record the attempt
        $record = new LoginAttemptRecord();
        $record->ipAddress = $ipAddress;
        $record->username = $username;
        $record->userAgent = $userAgent;
        $record->dateAttempted = (new DateTime())->format('Y-m-d H:i:s');
        $record->save();

        Craft::info("Login Lockdown: Recorded failed login attempt from {$ipAddress}", __METHOD__);

        // Check if we should block this IP
        return $this->checkAndBlockIfNeeded($ipAddress, $username);
    }

    /**
     * Check if IP should be blocked and block if needed
     *
     * @param string $ipAddress The IP address
     * @param string|null $username The last attempted username
     * @return bool Whether the IP was blocked
     */
    private function checkAndBlockIfNeeded(string $ipAddress, ?string $username = null): bool
    {
        $settings = LoginLockdown::$plugin->getSettings();

        // Count recent attempts within the window
        $attemptWindow = $settings->getAttemptWindowParsed();
        $windowStart = (new DateTime())
            ->modify("-{$attemptWindow} seconds")
            ->format('Y-m-d H:i:s');

        $attemptCount = (int)(new Query())
            ->from(LoginAttemptRecord::tableName())
            ->where(['ipAddress' => $ipAddress])
            ->andWhere(['>=', 'dateAttempted', $windowStart])
            ->count();

        // Check if threshold reached
        $maxAttempts = $settings->getMaxAttemptsParsed();
        if ($attemptCount >= $maxAttempts) {
            $this->blockIp($ipAddress, $attemptCount, "Exceeded {$maxAttempts} failed login attempts");

            // Send notification
            LoginLockdown::$plugin->notificationService->sendBlockNotification(
                $ipAddress,
                $attemptCount,
                $username
            );

            return true;
        }

        return false;
    }

    /**
     * Block an IP address
     *
     * @param string $ipAddress The IP address to block
     * @param int $attemptCount Number of failed attempts
     * @param string $reason Reason for blocking
     * @param bool $isManual Whether this is a manual block
     */
    public function blockIp(
        string $ipAddress,
        int $attemptCount = 0,
        string $reason = 'Manual block',
        bool $isManual = false,
    ): void {
        $settings = LoginLockdown::$plugin->getSettings();

        // Check if already blocked
        /** @var BlockedIpRecord|null $existing */
        $existing = BlockedIpRecord::find()
            ->where(['ipAddress' => $ipAddress])
            ->one();

        $lockoutDuration = $settings->getLockoutDurationParsed();
        $blockedUntil = (new DateTime())
            ->modify("+{$lockoutDuration} seconds")
            ->format('Y-m-d H:i:s');

        if ($existing) {
            // Update existing block
            $existing->attemptCount = $attemptCount;
            $existing->reason = $reason;
            $existing->blockedUntil = $blockedUntil;
            $existing->isManual = $isManual;
            $existing->save();
        } else {
            // Create new block
            try {
                $record = new BlockedIpRecord();
                $record->ipAddress = $ipAddress;
                $record->attemptCount = $attemptCount;
                $record->reason = $reason;
                $record->blockedUntil = $blockedUntil;
                $record->isManual = $isManual;
                $record->save();
            } catch (\yii\db\IntegrityException $e) {
                // Race condition: another request already inserted this IP
                // Try to update the existing record instead
                /** @var BlockedIpRecord|null $existingRecord */
                $existingRecord = BlockedIpRecord::find()
                    ->where(['ipAddress' => $ipAddress])
                    ->one();
                if ($existingRecord) {
                    $existingRecord->attemptCount = $attemptCount;
                    $existingRecord->reason = $reason;
                    $existingRecord->blockedUntil = $blockedUntil;
                    $existingRecord->isManual = $isManual;
                    $existingRecord->save();
                }
            }
        }

        Craft::warning("Login Lockdown: Blocked IP {$ipAddress} - {$reason}", __METHOD__);
    }

    /**
     * Unblock an IP address
     *
     * @param string $ipAddress The IP address to unblock
     * @return bool Whether the IP was unblocked
     */
    public function unblockIp(string $ipAddress): bool
    {
        $record = BlockedIpRecord::find()
            ->where(['ipAddress' => $ipAddress])
            ->one();

        if ($record) {
            $record->delete();
            Craft::info("Login Lockdown: Unblocked IP {$ipAddress}", __METHOD__);
            return true;
        }

        return false;
    }

    /**
     * Unblock an IP by record ID
     *
     * @param int $id The record ID
     * @return bool Whether the IP was unblocked
     */
    public function unblockById(int $id): bool
    {
        $record = BlockedIpRecord::findOne($id);

        if ($record) {
            $ipAddress = $record->ipAddress;
            $record->delete();
            Craft::info("Login Lockdown: Unblocked IP {$ipAddress}", __METHOD__);
            return true;
        }

        return false;
    }

    /**
     * Record an attempt from an already-blocked IP and extend the block timer
     *
     * Called when a blocked IP tries to log in again. This ensures continued
     * attempts are tracked and the lockout timer resets on each new attempt.
     *
     * @param string $ipAddress The IP address
     * @param string|null $username The attempted username
     * @param string|null $userAgent The user agent
     */
    public function recordBlockedAttemptAndExtend(string $ipAddress, ?string $username = null, ?string $userAgent = null): void
    {
        $settings = LoginLockdown::$plugin->getSettings();

        // Record the attempt
        $record = new LoginAttemptRecord();
        $record->ipAddress = $ipAddress;
        $record->username = $username;
        $record->userAgent = $userAgent;
        $record->dateAttempted = (new DateTime())->format('Y-m-d H:i:s');
        $record->save();

        // Count recent attempts within the window
        $attemptWindow = $settings->getAttemptWindowParsed();
        $windowStart = (new DateTime())
            ->modify("-{$attemptWindow} seconds")
            ->format('Y-m-d H:i:s');

        $attemptCount = (int)(new Query())
            ->from(LoginAttemptRecord::tableName())
            ->where(['ipAddress' => $ipAddress])
            ->andWhere(['>=', 'dateAttempted', $windowStart])
            ->count();

        // Extend the block timer
        $lockoutDuration = $settings->getLockoutDurationParsed();
        $blockedUntil = (new DateTime())
            ->modify("+{$lockoutDuration} seconds")
            ->format('Y-m-d H:i:s');

        $now = (new DateTime())->format('Y-m-d H:i:s');

        /** @var BlockedIpRecord|null $existing */
        $existing = BlockedIpRecord::find()
            ->where(['ipAddress' => $ipAddress])
            ->andWhere(['>', 'blockedUntil', $now])
            ->one();

        if ($existing) {
            $existing->attemptCount = $attemptCount;
            $existing->blockedUntil = $blockedUntil;
            $existing->save();
        }

        Craft::info(
            "Login Lockdown: Recorded blocked attempt from {$ipAddress}, extended block (attempts: {$attemptCount})",
            __METHOD__
        );
    }

    /**
     * Check if an IP is currently blocked
     *
     * @param string $ipAddress The IP address to check
     * @return bool Whether the IP is blocked
     */
    public function isBlocked(string $ipAddress): bool
    {
        $settings = LoginLockdown::$plugin->getSettings();

        // Not blocked if protection is disabled
        if (!$settings->getEnabledParsed()) {
            return false;
        }

        // Not blocked if whitelisted
        if ($this->isWhitelisted($ipAddress)) {
            return false;
        }

        $now = (new DateTime())->format('Y-m-d H:i:s');

        $record = BlockedIpRecord::find()
            ->where(['ipAddress' => $ipAddress])
            ->andWhere(['>', 'blockedUntil', $now])
            ->one();

        return $record !== null;
    }

    /**
     * Check if an IP is whitelisted
     *
     * @param string $ipAddress The IP address to check
     * @return bool Whether the IP is whitelisted
     */
    public function isWhitelisted(string $ipAddress): bool
    {
        $settings = LoginLockdown::$plugin->getSettings();
        return in_array($ipAddress, $settings->getWhitelistedIps(), true);
    }

    /**
     * Get all currently blocked IPs
     *
     * @param bool $includeExpired Whether to include expired blocks
     * @return array Array of BlockedIpRecord objects
     */
    public function getBlockedIps(bool $includeExpired = false): array
    {
        $query = BlockedIpRecord::find();

        if (!$includeExpired) {
            $now = (new DateTime())->format('Y-m-d H:i:s');
            $query->andWhere(['>', 'blockedUntil', $now]);
        }

        return $query->orderBy(['dateCreated' => SORT_DESC])->all();
    }

    /**
     * Get recent login attempts for an IP
     *
     * @param string $ipAddress The IP address
     * @param int $limit Maximum number of attempts to return
     * @return array Array of LoginAttemptRecord objects
     */
    public function getRecentAttempts(string $ipAddress, int $limit = 10): array
    {
        return LoginAttemptRecord::find()
            ->where(['ipAddress' => $ipAddress])
            ->orderBy(['dateAttempted' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    /**
     * Get the client's IP address (proxy-aware)
     *
     * Delegates to Craft's request component, which resolves the real client IP
     * using the site's configured trusted-proxy settings (`trustedHosts`,
     * `ipHeaders`, `secureHeaders`). Forwarded headers such as `X-Forwarded-For`
     * and `CF-Connecting-IP` are honored *only* when they originate from a
     * trusted proxy, and the forwarded chain is walked from the right so the
     * left-most, client-injected value cannot be spoofed.
     *
     * Do NOT trust raw forwarded headers here: doing so lets an attacker rotate
     * a header to bypass blocking/whitelisting, or spoof a victim's IP to lock a
     * legitimate user out. Sites behind Cloudflare or another reverse proxy
     * should configure `trustedHosts`/`ipHeaders` in `config/general.php` so the
     * correct header is honored securely.
     *
     * @return string The client IP address
     */
    public function getClientIp(): string
    {
        return Craft::$app->getRequest()->getUserIP() ?? '0.0.0.0';
    }

    /**
     * Clean up old login attempts and expired blocks
     *
     * @param int $olderThanDays Delete records older than this many days
     * @return int Number of records deleted
     */
    public function cleanup(int $olderThanDays = 30): int
    {
        $cutoff = (new DateTime())
            ->modify("-{$olderThanDays} days")
            ->format('Y-m-d H:i:s');

        // Delete old login attempts
        $attemptsDeleted = Craft::$app->getDb()->createCommand()
            ->delete(LoginAttemptRecord::tableName(), ['<', 'dateAttempted', $cutoff])
            ->execute();

        // Delete expired blocks
        $now = (new DateTime())->format('Y-m-d H:i:s');
        $blocksDeleted = Craft::$app->getDb()->createCommand()
            ->delete(BlockedIpRecord::tableName(), ['<', 'blockedUntil', $now])
            ->execute();

        Craft::info(
            "Login Lockdown: Cleanup removed {$attemptsDeleted} login attempts and {$blocksDeleted} expired blocks",
            __METHOD__
        );

        return $attemptsDeleted + $blocksDeleted;
    }
}
