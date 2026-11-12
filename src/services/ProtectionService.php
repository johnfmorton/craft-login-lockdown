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
use johnfmorton\loginlockdown\http\BlockedIpMiddleware;
use johnfmorton\loginlockdown\LoginLockdown;
use johnfmorton\loginlockdown\records\BlockedIpRecord;
use johnfmorton\loginlockdown\records\LoginAttemptRecord;
use yii\validators\IpValidator;

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
     * @var int Minimum number of seconds between persisted attempts for an
     * already-blocked IP. A blocked IP that keeps hitting the login endpoint
     * would otherwise insert one attempt row (and re-save the block row) per
     * request, letting an attacker flood the database. The 403 block is still
     * enforced on every request — only the database writes are throttled.
     */
    private const BLOCKED_ATTEMPT_THROTTLE_SECONDS = 60;

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

        // Throttle writes: if an attempt for this IP was already recorded within
        // the throttle window, skip persisting this one. This stops a blocked IP
        // from flooding the attempts table (and repeatedly re-saving the block
        // row) by hammering the login endpoint. The caller still returns a 403
        // for every request regardless of whether this write happens.
        $throttleStart = (new DateTime())
            ->modify('-' . self::BLOCKED_ATTEMPT_THROTTLE_SECONDS . ' seconds')
            ->format('Y-m-d H:i:s');

        $recentlyRecorded = (new Query())
            ->from(LoginAttemptRecord::tableName())
            ->where(['ipAddress' => $ipAddress])
            ->andWhere(['>=', 'dateAttempted', $throttleStart])
            ->exists();

        if ($recentlyRecorded) {
            return;
        }

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
     * Get the client's IP address.
     *
     * Forwarded headers are only honored when the site has explicitly told Craft
     * which proxies to trust, and the request actually came from one of them.
     * Craft's own `getUserIP()` can't be used for this: it returns the first
     * valid address from any of `Client-IP`, `X-Forwarded-For`, `X-Forwarded`,
     * `X-Cluster-Client-IP`, `Forwarded-For` or `Forwarded`, taking the
     * left-most (client-controlled) entry, and only `X-Forwarded-For` is
     * filtered by `trustedHosts`, which defaults to `['any']`. An attacker
     * could rotate any of those headers to dodge blocks, or send a victim's IP
     * to lock them out.
     *
     * - `trustedHosts` unset or `['any']` (Craft's default): the direct
     *   connection address (`REMOTE_ADDR`). Client headers are ignored.
     * - `trustedHosts` set to proxy ranges (e.g. Cloudflare's): when the
     *   connection comes from one of those ranges, the site's `ipHeaders`
     *   (default `X-Forwarded-For`) are walked from the right, skipping
     *   trusted proxies, and the first untrusted address is the client.
     *
     * On Craft 6 the IP comes from Laravel's request instead (see
     * BlockedIpMiddleware::clientIp()). The yii2 adapter's legacy request would
     * honor a client-supplied `Client-IP` header, because Craft 6 deprecated
     * `trustedHosts` (default `['any']`) in favor of Laravel's trusted proxies.
     * Laravel's `ip()` only honors forwarded headers from proxies trusted in
     * `bootstrap/app.php`, and it's what Craft 6's own login throttling uses.
     *
     * @return string The client IP address
     */
    public function getClientIp(): string
    {
        // Craft 6: Laravel's request, which only honors forwarded headers from
        // proxies trusted in bootstrap/app.php (see BlockedIpMiddleware::clientIp()).
        if (LoginLockdown::isCraft6()) {
            $clientIp = BlockedIpMiddleware::clientIp() ?? '0.0.0.0';
            $this->warnIfUnconfiguredProxy($clientIp);
            return $clientIp;
        }

        $request = Craft::$app->getRequest();
        $remoteIp = $request->getRemoteIP();
        if ($remoteIp === null) {
            return '0.0.0.0';
        }

        $generalConfig = Craft::$app->getConfig()->getGeneral();
        $trustedRanges = self::trustedProxyRanges($generalConfig->trustedHosts);

        if ($trustedRanges === [] || !self::ipInRanges($remoteIp, $trustedRanges)) {
            if ($trustedRanges === []) {
                $this->warnIfUnconfiguredProxy($remoteIp);
            }
            return $remoteIp;
        }

        foreach ($generalConfig->ipHeaders ?? ['X-Forwarded-For'] as $headerName) {
            $value = $request->getHeaders()->get($headerName);
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            // Walk from the right: proxies append, so the right-most entries
            // were added by our own (trusted) infrastructure.
            $clientIp = null;
            $ips = preg_split('/\s*,\s*/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach (array_reverse($ips) as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                    break;
                }
                $clientIp = $ip;
                if (!self::ipInRanges($ip, $trustedRanges)) {
                    break;
                }
            }

            if ($clientIp !== null) {
                return $clientIp;
            }
        }

        return $remoteIp;
    }

    /**
     * The proxy ranges a site has explicitly trusted, or `[]` when it hasn't:
     * Craft's default `trustedHosts` of `['any']` trusts every client, so it
     * can't be used to decide whether forwarded headers are genuine.
     *
     * @param array<int|string, string|string[]> $trustedHosts
     * @return string[]
     */
    private static function trustedProxyRanges(array $trustedHosts): array
    {
        $ranges = [];
        foreach ($trustedHosts as $key => $value) {
            // Entries are either 'cidr' or 'cidr' => [headers].
            $range = is_array($value) ? $key : $value;
            if (is_string($range) && $range !== '') {
                $ranges[] = $range;
            }
        }

        return in_array('any', $ranges, true) ? [] : $ranges;
    }

    /**
     * @param string[] $ranges
     */
    private static function ipInRanges(string $ip, array $ranges): bool
    {
        $validator = new IpValidator(['ranges' => $ranges]);
        return $validator->validate($ip);
    }

    /**
     * Log a warning (at most once an hour) when the request looks proxied but the
     * resolved client IP is the proxy's own: every visitor then shares that
     * IP, so a few failed logins would block all of them.
     *
     * Two signs, chosen so a correctly configured site stays quiet:
     * - A CDN client header (`CF-Connecting-IP` from Cloudflare,
     *   `True-Client-IP` from Akamai/Cloudflare Enterprise) holding an IP
     *   other than the resolved one. When the web server or Craft is set up
     *   for the CDN, the two match.
     * - A private or reserved resolved IP plus an `X-Forwarded-For` header:
     *   a load balancer or reverse proxy on an internal network.
     */
    private function warnIfUnconfiguredProxy(string $clientIp): void
    {
        static $warned = false;
        if ($warned) {
            return;
        }

        $headers = Craft::$app->getRequest()->getHeaders();
        $reason = null;

        foreach (['CF-Connecting-IP', 'True-Client-IP'] as $cdnHeader) {
            $value = trim((string)$headers->get($cdnHeader, ''));
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_IP) !== false && $value !== $clientIp) {
                $reason = "a {$cdnHeader} header says the visitor is {$value}, which looks like a CDN such as Cloudflare";
                break;
            }
        }

        if ($reason === null) {
            $isPrivate = filter_var($clientIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
            if ($isPrivate && trim((string)$headers->get('X-Forwarded-For', '')) !== '') {
                $reason = 'it has an X-Forwarded-For header, which looks like a reverse proxy or load balancer';
            }
        }

        if ($reason === null) {
            return;
        }

        $warned = true;

        // Every request from a misconfigured site would qualify, so log at most
        // once an hour rather than filling the logs.
        if (!Craft::$app->getCache()->add('loginlockdown:proxy-warning', true, 3600)) {
            return;
        }

        Craft::warning(
            "Login Lockdown: requests appear to come from {$clientIp}, but {$reason}. "
            . 'Every visitor may be treated as that one IP, so a few failed logins could block everyone. '
            . 'Configure your trusted proxy: see "Behind Cloudflare or another proxy" in the plugin README.',
            __METHOD__
        );
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
