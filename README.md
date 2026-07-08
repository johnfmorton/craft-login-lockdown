# Login Lockdown Documentation

Login Lockdown is a Craft CMS 5 plugin that protects your login forms from brute force password attacks by tracking failed login attempts per IP address and temporarily blocking access after a configurable threshold is reached.

## How It Works

1. When a user fails to log in (to either the Control Panel or a front-end login form), the plugin records the attempt along with their IP address
2. If the same IP address exceeds the maximum allowed failed attempts within the configured time window, the IP is blocked
3. Blocked IPs receive a 403 Forbidden response when attempting to log in via the Control Panel or front-end forms
4. After the lockout duration expires, the IP is automatically unblocked
5. Whitelisted IPs are never blocked, regardless of failed attempts

**Note:** Both Control Panel and front-end login forms are protected by default. Front-end protection can be disabled in the settings if desired.

The plugin identifies the client IP using Craft's built-in request handling, which resolves the real client IP according to your site's **trusted-proxy configuration**. This is deliberately *not* a matter of blindly trusting `X-Forwarded-For`/`CF-Connecting-IP`: a forwarded header is honored only when the request actually came from a proxy you've marked as trusted, and the forwarded chain is walked so a client cannot spoof its own address.

**If your site sits behind Cloudflare, a load balancer, or another reverse proxy, configure the trusted proxy in `config/general.php`** so the correct IP is used:

```php
use craft\config\GeneralConfig;

return GeneralConfig::create()
    // Only trust forwarding headers from your proxy's IP range(s):
    ->trustedHosts(['10.0.0.0/8', '172.16.0.0/12'])
    // Cloudflare users: honor CF-Connecting-IP (in addition to X-Forwarded-For):
    ->ipHeaders(['CF-Connecting-IP', 'X-Forwarded-For']);
```

Without this, Craft falls back to the direct connection IP (`REMOTE_ADDR`), which is the safe default. **Do not** configure the plugin to trust raw forwarded headers from every client — that would let an attacker rotate a header to bypass blocking, slip past the whitelist, or spoof a victim's IP to lock a legitimate user out.

## Installation

Install via Composer:

```bash
composer require johnfmorton/login-lockdown
```

Then install the plugin in Craft:

```bash
php craft plugin/install login-lockdown
```

Or install via the Control Panel under **Settings → Plugins**.

## Configuration

Configure the plugin via **Settings → Login Lockdown** in the control panel.

**All settings support environment variables** using the `$ENV_VAR` syntax. For example, set a field to `$MY_VAR` and define the value in your `.env` file.

### Protection Settings

| Setting | Default | Type | Description |
|---------|---------|------|-------------|
| Enable Protection | `true` | boolean | Enable or disable brute force protection |
| Protect Front-End Login Forms | `true` | boolean | Block login attempts from blocked IPs on front-end forms (not just CP) |
| Maximum Failed Attempts | `5` | integer | Number of failed login attempts before blocking |
| Attempt Window | `900` | integer | Time window in seconds for counting failed attempts (15 min) |
| Lockout Duration | `86400` | integer | How long to block an IP in seconds (24 hours) |
| Block Message | (see below) | string | Message displayed to blocked users |
| Whitelisted IP Addresses | (none) | array | IPs that should never be blocked |

**Default block message**: "Access temporarily blocked due to too many failed login attempts. Please try again later."

### Notification Settings

| Setting | Default | Type | Description |
|---------|---------|------|-------------|
| Enable Notifications | `false` | boolean | Send notifications when an IP is blocked |
| Notification Email | (empty) | string | Email address for block notifications |
| Enable Pushover | `false` | boolean | Send push notifications via Pushover |
| Pushover User Key | (empty) | string | Your Pushover user key |
| Pushover API Token | (empty) | string | Your Pushover application API token |

### Environment Variable Support

All settings support Craft CMS environment variable syntax. In the control panel, enter a value like `$MY_ENV_VAR` and define the actual value in your `.env` file.

#### Boolean Values

For boolean settings (Enable Protection, Enable Notifications, Enable Pushover), use one of:
- `true`, `1`, `yes`, `on` for enabled
- `false`, `0`, `no`, `off` for disabled
- Or an environment variable like `$LOGIN_LOCKDOWN_ENABLED`

#### Example Setup

1. In your `.env` file:
```bash
# Protection settings
LOGIN_LOCKDOWN_ENABLED=true
LOGIN_LOCKDOWN_PROTECT_FRONTEND=true
LOGIN_LOCKDOWN_MAX_ATTEMPTS=3
LOGIN_LOCKDOWN_ATTEMPT_WINDOW=600
LOGIN_LOCKDOWN_LOCKOUT_DURATION=3600

# Notification settings
LOGIN_LOCKDOWN_NOTIFY=true
SECURITY_EMAIL=security@example.com

# Pushover credentials
PUSHOVER_ENABLED=true
PUSHOVER_USER_KEY=your_pushover_user_key_here
PUSHOVER_API_TOKEN=your_pushover_api_token_here
```

2. In the plugin settings (Control Panel → Settings → Login Lockdown):
   - Set **Enable Protection** to `$LOGIN_LOCKDOWN_ENABLED`
   - Set **Protect Front-End Login Forms** to `$LOGIN_LOCKDOWN_PROTECT_FRONTEND`
   - Set **Maximum Failed Attempts** to `$LOGIN_LOCKDOWN_MAX_ATTEMPTS`
   - Set **Attempt Window** to `$LOGIN_LOCKDOWN_ATTEMPT_WINDOW`
   - Set **Lockout Duration** to `$LOGIN_LOCKDOWN_LOCKOUT_DURATION`
   - Set **Enable Notifications** to `$LOGIN_LOCKDOWN_NOTIFY`
   - Set **Notification Email** to `$SECURITY_EMAIL`
   - Set **Enable Pushover** to `$PUSHOVER_ENABLED`
   - Set **Pushover User Key** to `$PUSHOVER_USER_KEY`
   - Set **Pushover API Token** to `$PUSHOVER_API_TOKEN`

This approach keeps sensitive credentials out of your project config and allows different values per environment.

### Common Time Values

| Duration | Seconds |
|----------|---------|
| 5 minutes | `300` |
| 15 minutes | `900` |
| 30 minutes | `1800` |
| 1 hour | `3600` |
| 6 hours | `21600` |
| 12 hours | `43200` |
| 24 hours | `86400` |
| 7 days | `604800` |

## Managing Blocked IPs

The plugin adds a "Login Lockdown" section to the control panel navigation where you can:

- View all currently blocked IP addresses
- See when each IP was blocked and when the block expires
- See the number of failed attempts that triggered the block
- Manually unblock IP addresses
- Manually block IP addresses

## Notifications

When enabled, the plugin can send notifications each time an IP is blocked:

### Email Notifications

Requires:
- `Enable Notifications` set to `true` (or env var resolving to true)
- Valid email address in `Notification Email` (or environment variable reference)
- Craft's email settings configured correctly

### Pushover Notifications

[Pushover](https://pushover.net/) is a service for sending push notifications to mobile devices.

Requires:
- `Enable Notifications` set to `true`
- `Enable Pushover` set to `true`
- Valid `Pushover User Key` (from your Pushover account)
- Valid `Pushover API Token` (create an application in Pushover)

## Whitelisting IPs

Whitelisted IPs are never blocked, even if they exceed the failed attempt threshold. Use this for:
- Your office IP address
- Trusted administrator IPs
- Automated testing systems

Add whitelisted IPs via the Control Panel settings page using the editable table.

## Database Tables

The plugin creates two database tables:

### `loginlockdown_login_attempts`
Stores failed login attempts:
- `ipAddress`: The IP that made the attempt
- `username`: The username that was tried
- `userAgent`: The browser/client user agent
- `dateAttempted`: When the attempt occurred

### `loginlockdown_blocked_ips`
Stores blocked IPs:
- `ipAddress`: The blocked IP
- `attemptCount`: Number of failed attempts
- `reason`: Why the IP was blocked
- `blockedUntil`: When the block expires
- `isManual`: Whether this was a manual block

## CLI Commands

The plugin provides Craft CLI commands for managing blocked IPs and cleaning up old records.

### Cleanup Command

Clean up old login attempt records and expired blocks:

```bash
# Delete records older than 30 days (default)
php craft login-lockdown/cleanup

# Delete records older than 7 days
php craft login-lockdown/cleanup --days=7
php craft login-lockdown/cleanup -d 7
```

### Block Management Commands

List, add, remove, and check blocked IPs:

```bash
# List currently blocked IPs
php craft login-lockdown/block/list

# Include expired blocks in the list
php craft login-lockdown/block/list --all
php craft login-lockdown/block/list -a

# Block an IP address manually
php craft login-lockdown/block/add 192.168.1.100

# Unblock an IP address
php craft login-lockdown/block/remove 192.168.1.100

# Check if an IP is blocked
php craft login-lockdown/block/check 192.168.1.100
```

## Cleanup (Programmatic)

Old login attempt records and expired blocks are not cleaned up automatically. You can trigger cleanup via the Control Panel, CLI commands (see above), or programmatically via the protection service:

```php
use johnfmorton\loginlockdown\LoginLockdown;

// Delete records older than 30 days (default)
LoginLockdown::$plugin->protectionService->cleanup();

// Delete records older than 7 days
LoginLockdown::$plugin->protectionService->cleanup(7);
```

### Scheduled Cleanup

To run cleanup automatically, add a cron job to execute the CLI command:

```bash
# Run daily at 3am (delete records older than 30 days)
0 3 * * * cd /path/to/project && php craft login-lockdown/cleanup

# Run weekly, keeping only 7 days of records
0 3 * * 0 cd /path/to/project && php craft login-lockdown/cleanup --days=7
```

## Programmatic Usage

### Check if an IP is blocked

```php
use johnfmorton\loginlockdown\LoginLockdown;

$ip = '192.168.1.100';
$isBlocked = LoginLockdown::$plugin->protectionService->isBlocked($ip);
```

### Manually block an IP

```php
use johnfmorton\loginlockdown\LoginLockdown;

LoginLockdown::$plugin->protectionService->blockIp(
    '192.168.1.100',       // IP address
    0,                     // attempt count (0 for manual)
    'Suspicious activity', // reason
    true                   // isManual
);
```

### Manually unblock an IP

```php
use johnfmorton\loginlockdown\LoginLockdown;

LoginLockdown::$plugin->protectionService->unblockIp('192.168.1.100');
```

### Get all blocked IPs

```php
use johnfmorton\loginlockdown\LoginLockdown;

// Get only active blocks
$blockedIps = LoginLockdown::$plugin->protectionService->getBlockedIps();

// Include expired blocks
$allBlocks = LoginLockdown::$plugin->protectionService->getBlockedIps(true);
```

## Troubleshooting

### I'm locked out of my own site

If you've accidentally blocked yourself:

1. **Via CLI**: `php craft login-lockdown/block/remove YOUR_IP_ADDRESS`
2. **Via database**: Delete your IP from the `loginlockdown_blocked_ips` table
3. **Disable plugin**: Rename the plugin folder temporarily to disable it

### Blocks aren't working behind a proxy (all attempts recorded as one IP)

If every attempt is recorded against your proxy's IP instead of the real client IP, Craft isn't being told to trust your proxy's forwarding headers. Configure it in `config/general.php`:

1. Make sure your proxy forwards the client IP:
   - For Cloudflare: the `CF-Connecting-IP` header is sent automatically
   - For nginx: `proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;`
   - For load balancers: ensure `X-Forwarded-For` is passed
2. Tell Craft which proxy to trust and which header to read (see [How It Works](#how-it-works) for a full example):
   ```php
   ->trustedHosts(['10.0.0.0/8'])          // your proxy's IP range(s)
   ->ipHeaders(['CF-Connecting-IP', 'X-Forwarded-For'])
   ```

Trusting a header without restricting `trustedHosts` to your proxy is a security risk — clients could then spoof their IP.

### Notifications aren't sending

1. **Email**: Verify Craft's email settings are configured and working
2. **Pushover**: Verify your user key and API token are correct (check for typos in env var names)
3. Check the Craft logs for error messages

### Environment variables not working

1. Ensure the `.env` file is in the project root
2. Check that the variable name in settings matches exactly (case-sensitive)
3. Verify the `.env` file is being loaded (check other Craft env vars work)
4. Clear Craft's caches after changing `.env` values

## Security Recommendations

1. **Set reasonable thresholds**: 3-5 attempts is usually sufficient
2. **Use shorter attempt windows**: 5-15 minutes catches rapid attacks
3. **Enable notifications**: Be aware of attacks as they happen
4. **Whitelist carefully**: Only whitelist static, trusted IPs
5. **Monitor logs**: Review blocked IPs periodically for patterns
6. **Combine with other security**: Use strong passwords, 2FA, and keep Craft updated
7. **Use environment variables**: Keep sensitive credentials like Pushover keys out of project config
8. **Schedule regular cleanup**: Set up a cron job to periodically clean up old login attempts and expired blocks to keep database tables lean (see [Scheduled Cleanup](#scheduled-cleanup))

## License

MIT License - see LICENSE file for details.

## Support

- **Issues**: [GitHub Issues](https://github.com/johnfmorton/craft-login-lockdown/issues)
- **Author**: John F Morton ([supergeekery.com](https://supergeekery.com))
