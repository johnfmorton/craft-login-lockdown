# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security

- **Fixed IP spoofing in client IP detection.** `ProtectionService::getClientIp()` previously trusted the `CF-Connecting-IP`, `X-Forwarded-For`, and `X-Real-IP` request headers unconditionally and used the left-most value, which is client-controlled. This let an attacker rotate a header to bypass blocking entirely, bypass the IP whitelist, or spoof a victim's IP to lock a legitimate user out of the Control Panel. IP detection now delegates to Craft's `Request::getUserIP()`, which honors forwarded headers only from proxies you've marked as trusted via `trustedHosts`/`ipHeaders` in `config/general.php`. **Sites behind Cloudflare or a reverse proxy should review the [proxy configuration guidance](README.md#how-it-works).**
- **Fixed a front-end login block bypass.** Front-end enforcement only recognized a login when the `action` was passed as a POST body param, so a blocked IP could keep attempting logins by targeting the `/actions/users/login` path or query-string form instead. Enforcement now inspects the resolved action, which is invariant across all invocation forms.
- **Bounded database writes from blocked IPs.** A blocked IP that kept hitting the login endpoint previously caused one login-attempt row (plus a re-save of the block row) to be written per request, which an attacker could use to flood the database. Writes for an already-blocked IP are now throttled to at most once per 60 seconds; the 403 block is still returned on every request.

## [1.0.5] - 2026-06-09

### Changed

- Bumped minimum Craft CMS to `^5.9.18` (was `^5.8.0`) so consumers no longer install Craft versions affected by [GHSA-gj2p-p9m4-c8gw](https://github.com/advisories/GHSA-gj2p-p9m4-c8gw), [GHSA-qrgm-p9w5-rrfw](https://github.com/advisories/GHSA-qrgm-p9w5-rrfw), and [GHSA-33m5-hqp9-97pw](https://github.com/advisories/GHSA-33m5-hqp9-97pw), all patched in Craft 5.9.18
- Stopped committing `composer.lock` — distributed plugins shouldn't ship lock files, since consumers resolve dependencies against their own. This also clears noise from Dependabot scans of transitive dependencies that don't actually affect consumers
- Added a `.github/dependabot.yml` restricting Composer scans to direct dependencies and only opening PRs when a new version falls outside the existing constraint, so future Dependabot activity reflects real security updates rather than lockfile churn

## [1.0.4] - 2026-03-24

### Fixed

- Non-admin users with plugin access can now view the blocked IPs page without getting a 403 error
- CP nav item now hidden for users without plugin access permission

## [1.0.3] - 2026-02-04

### Fixed

- Login attempts from already-blocked IPs are now recorded and reset the lockout timer

## [1.0.2] - 2026-02-02

### Fixed

- Corrected GitHub repository URLs in composer.json documentation and support links

## [1.0.1] - 2026-02-02

### Added

- Block expiration time now included in email and Pushover notification messages

## [1.0.0] - 2026-02-02

### Added

- Brute force protection for Craft CMS control panel login
- Brute force protection for front-end login forms
- Configurable failed attempt threshold and time window
- Configurable lockout duration
- IP whitelist to exclude trusted addresses from blocking
- Email notifications when IPs are blocked
- Pushover notifications when IPs are blocked
- Control panel interface for viewing and managing blocked IPs
- CLI commands for managing blocked IPs (`login-lockdown/block/list`, `add`, `remove`, `check`)
- CLI command for cleaning up old records (`login-lockdown/cleanup`)
- Proxy-aware IP detection (Cloudflare, X-Forwarded-For, X-Real-IP)
- Environment variable support for all settings using `$ENV_VAR` syntax

[Unreleased]: https://github.com/johnfmorton/craft-login-lockdown/compare/v1.0.5...HEAD
[1.0.5]: https://github.com/johnfmorton/craft-login-lockdown/compare/v1.0.4...v1.0.5
[1.0.4]: https://github.com/johnfmorton/craft-login-lockdown/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/johnfmorton/craft-login-lockdown/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/johnfmorton/craft-login-lockdown/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/johnfmorton/craft-login-lockdown/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/johnfmorton/craft-login-lockdown/releases/tag/v1.0.0
