# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Login Lockdown is a Craft CMS 5 plugin that protects login forms from brute force attacks by tracking failed login attempts per IP address and temporarily blocking access after a configurable threshold.

**Stack:** Craft CMS 5.8+ / PHP 8.2+

## Common Commands

```bash
composer run check-cs           # Check code style (ECS)
composer run fix-cs             # Fix code style issues
composer run phpstan            # Run static analysis (PHPStan level 4)
```

## Architecture

### Plugin Structure

```
src/
├── LoginLockdown.php           # Main plugin class, event registration
├── models/Settings.php         # Plugin settings with env var support
├── services/
│   ├── ProtectionService.php   # Core logic: tracking attempts, blocking IPs
│   └── NotificationService.php # Email and Pushover notifications
├── records/
│   ├── BlockedIpRecord.php     # ActiveRecord for blocked IPs table
│   └── LoginAttemptRecord.php  # ActiveRecord for login attempts table
├── controllers/
│   └── BlockedIpsController.php # CP UI for managing blocked IPs
├── console/controllers/
│   ├── BlockController.php     # CLI: list/add/remove/check blocked IPs
│   └── CleanupController.php   # CLI: cleanup old records
├── migrations/Install.php      # Creates database tables
└── templates/                  # Control panel Twig templates
```

### Event Flow

1. **Failed Login Detection**: Plugin listens to `UsersController::EVENT_LOGIN_FAILURE`
2. **Attempt Recording**: `ProtectionService::recordFailedAttempt()` stores attempt in `loginlockdown_login_attempts`
3. **Threshold Check**: If attempts exceed `maxAttempts` within `attemptWindow`, IP is blocked
4. **Block Enforcement**: `Application::EVENT_BEFORE_ACTION` intercepts login POST requests from blocked IPs
5. **Notifications**: `NotificationService` sends email/Pushover alerts when IPs are blocked

### Settings with Environment Variables

All settings support `$ENV_VAR` syntax. The Settings model has paired properties:
- Raw property (e.g., `$enabled`) - stores the value as entered (may be env var reference)
- Parsed getter (e.g., `getEnabledParsed()`) - resolves env vars and returns typed value

### Database Tables

- `loginlockdown_login_attempts` - Failed login attempts with IP, username, user agent
- `loginlockdown_blocked_ips` - Currently blocked IPs with expiration time

### Proxy-Aware IP Detection

`ProtectionService::getClientIp()` delegates to `Craft::$app->getRequest()->getUserIP()`, which resolves the client IP using the site's configured trusted-proxy settings (`trustedHosts` / `ipHeaders` / `secureHeaders` in `config/general.php`). Forwarded headers (`X-Forwarded-For`, `CF-Connecting-IP`, etc.) are honored only from trusted proxies; otherwise it falls back to `REMOTE_ADDR`.

**Do not** reintroduce hand-rolled header trust here (e.g. reading `CF-Connecting-IP`/`X-Forwarded-For` directly and returning the left-most value). That made the block key client-spoofable, which defeated the protection (rotate a header to bypass a block), bypassed the whitelist, and let an attacker spoof a victim's IP to lock a legitimate user out. Sites behind a proxy configure trust in `config/general.php` — the plugin must not trust headers unconditionally.
