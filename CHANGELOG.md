# Changelog

## 1.4.0 - 2026-10-23

### Added

- Kiyoh Mobile integration health (Jira KP-87). For each store view with a Location ID and API token, the module POSTs to `{url}/api/integrations/health` on every comma-separated **Kiyoh Mobile URL(s)** value (default `https://kiyoh-mobile.com`). The body uses `platform` `magento2`, `shopId` from the store base host, and `pluginVersion`, with connection, invite, product sync (up to 500 product codes), webhook, and widget groups. Reports go out on config save, invitation sent or failed, and product sync, plus a daily cron. Unchanged payloads are sent again once the last accepted report is 24 hours old. Calls use a 2 second timeout, wait out HTTP 429, and leave order processing running when the health endpoint is slow or unavailable.

## 1.3.0 - 2026-10-21

### Fixed

- PHP 8.4 / Magento 2.4.9 fatal error on boot. Magento 2.4.9 requires `symfony/console` ^7.4, whose `Command::execute()` declares `: int`. Four commands overrode `execute()` without that return type, so `bin/magento` aborted while loading the command list. `configure()` on those same commands now declares `: void` as well, which Symfony Console 8 requires. Fixes GitHub issue #2 (Jira KP-5).
- PHP 8.4 deprecation for implicitly nullable parameters. Nullable arguments are now explicit (`?string`, `?callable`, and the nullable resource arguments on the credentials backend model).

### Changed

- Minimum PHP version is 8.2 (`composer.json` `php: >=8.2.0`). PHP 8.1 and older are end-of-life and are affected by PHP CGI argument-injection issues (php2shell). Magento 2.4.6 is the oldest release that officially supports PHP 8.2.

## 1.2.0 - 2026-09-30

- Module version recorded in `etc/module.xml` and the README. See git history for the multistore sync fixes included in that release.
