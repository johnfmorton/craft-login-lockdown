# Login Lockdown Documentation

Login Lockdown is a Craft CMS 5 plugin that protects your login forms from brute force password attacks by tracking failed login attempts per IP address and temporarily blocking access after a configurable threshold is reached.

## How It Works

1. When a user fails to log in (to either the Control Panel or a front-end login form), the plugin records the attempt along with their IP address
2. If the same IP address exceeds the maximum allowed failed attempts within the configured time window, the IP is blocked
3. Blocked IPs receive a 403 Forbidden response when attempting to log in via the Control Panel or front-end forms
4. After the lockout duration expires, the IP is automatically unblocked
5. Whitelisted IPs are never blocked, regardless of failed attempts

**Note:** Both Control Panel and front-end login forms are protected by default. Front-end protection can be disabled in the settings if desired.

### How the client IP is determined

Blocking only works if the plugin knows each visitor's real IP, and a visitor must not be able to fake it. By default the plugin uses the **direct connection address** (`REMOTE_ADDR`) and ignores every forwarded header a client could send.

Forwarded headers are used only when you've told Craft which proxies to trust, and only when the request actually came from one of them. The plugin then reads your `ipHeaders` (default `X-Forwarded-For`) from the right, skipping your trusted proxies, so the first address your infrastructure didn't add is the client. (Since 2.0.1 the plugin does this itself rather than using Craft's `getUserIP()`, which would accept a client-supplied `Client-IP` or `X-Forwarded-For` under Craft's default `trustedHosts` of `['any']`.)

**If your site sits behind Cloudflare, a load balancer, or another reverse proxy, configure the trusted proxy in `config/general.php`.** Otherwise every visitor is seen as the proxy's IP, and a few failed logins would block everyone. The plugin logs a warning when requests look proxied but the IP it resolves is the proxy's (see [Behind Cloudflare or another proxy](#behind-cloudflare-or-another-proxy)).

```php
use craft\config\GeneralConfig;

return GeneralConfig::create()
    // Only trust forwarding headers from your proxy's IP range(s):
    ->trustedHosts(['10.0.0.0/8', '172.16.0.0/12'])
    // Cloudflare users: honor CF-Connecting-IP (in addition to X-Forwarded-For):
    ->ipHeaders(['CF-Connecting-IP', 'X-Forwarded-For']);
```

Leaving `trustedHosts` at Craft's default (`['any']`) means "no trusted proxies" to this plugin: it uses the direct connection IP, the safe default. Only list headers in `ipHeaders` that your proxy sets or overwrites. If a header passes through your proxy unchanged (some proxies forward `Client-IP` as-is), a client could still put any IP in it.

### Behind Cloudflare or another proxy

If Cloudflare proxies your site (the orange cloud in DNS), the connection your server sees comes from a Cloudflare edge server, not the visitor. Pick **one** of these so Login Lockdown, and Craft, see the real visitor IP. The web server option is simplest, because it fixes the IP for everything on the site.

**nginx** (for example in Forge's site configuration, inside the `server` block):

```nginx
# Trust CF-Connecting-IP only from Cloudflare's edge: https://www.cloudflare.com/ips/
set_real_ip_from 173.245.48.0/20;
set_real_ip_from 103.21.244.0/22;
set_real_ip_from 103.22.200.0/22;
set_real_ip_from 103.31.4.0/22;
set_real_ip_from 141.101.64.0/18;
set_real_ip_from 108.162.192.0/18;
set_real_ip_from 190.93.240.0/20;
set_real_ip_from 188.114.96.0/20;
set_real_ip_from 197.234.240.0/22;
set_real_ip_from 198.41.128.0/17;
set_real_ip_from 162.158.0.0/15;
set_real_ip_from 104.16.0.0/13;
set_real_ip_from 104.24.0.0/14;
set_real_ip_from 172.64.0.0/13;
set_real_ip_from 131.0.72.0/22;
set_real_ip_from 2400:cb00::/32;
set_real_ip_from 2606:4700::/32;
set_real_ip_from 2803:f800::/32;
set_real_ip_from 2405:b500::/32;
set_real_ip_from 2405:8100::/32;
set_real_ip_from 2a06:98c0::/29;
set_real_ip_from 2c0f:f248::/32;
real_ip_header CF-Connecting-IP;
```

**Apache** (`mod_remoteip`):

```apache
RemoteIPHeader CF-Connecting-IP
# One line per range from https://www.cloudflare.com/ips/
RemoteIPTrustedProxy 173.245.48.0/20
RemoteIPTrustedProxy 103.21.244.0/22
# ...and the rest of the list
```

**Craft 5** (`config/general.php`), if you can't change the web server:

```php
->trustedHosts([
    // Cloudflare's ranges: https://www.cloudflare.com/ips/
    '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
    '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
    '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
    '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
    '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
])
->ipHeaders(['CF-Connecting-IP'])
```

Cloudflare adds ranges occasionally, so check the list now and then.

For a load balancer or other reverse proxy, the idea is the same: trust only your proxy's addresses (`set_real_ip_from` / `RemoteIPTrustedProxy` / `trustedHosts`), and read the header it sets (usually `X-Forwarded-For`).

**Check it worked:** fail a login on purpose, then look at the attempts list under **Login Lockdown** in the control panel. It should show your own IP, not a proxy's. The plugin also logs a warning ("Every visitor may be treated as that one IP") when it sees a Cloudflare header or a forwarded private address that doesn't match the IP it resolved.

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

Forwarded headers are ignored unless `trustedHosts` is restricted to your proxy's ranges; Craft's default `['any']` doesn't count. The plugin logs a warning ("Every visitor may be treated as that one IP") when it detects this. For Cloudflare, see [Behind Cloudflare or another proxy](#behind-cloudflare-or-another-proxy).

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
