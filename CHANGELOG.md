# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/johnfmorton/craft-login-lockdown/compare/v1.0.3...HEAD
[1.0.3]: https://github.com/johnfmorton/craft-login-lockdown/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/johnfmorton/craft-login-lockdown/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/johnfmorton/craft-login-lockdown/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/johnfmorton/craft-login-lockdown/releases/tag/v1.0.0
