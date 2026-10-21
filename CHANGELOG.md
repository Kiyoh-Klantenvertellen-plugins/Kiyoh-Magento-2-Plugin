# Changelog

## 1.3.0 - 2026-10-21

### Fixed

- PHP 8.4 / Magento 2.4.9 fatal error on boot. Magento 2.4.9 requires `symfony/console` ^7.4, whose `Command::execute()` declares `: int`. Four commands overrode `execute()` without that return type, so `bin/magento` aborted while loading the command list. `configure()` on those same commands now declares `: void` as well, which Symfony Console 8 requires. Fixes GitHub issue #2 (Jira KP-5).
- PHP 8.4 deprecation for implicitly nullable parameters. Nullable arguments are now explicit (`?string`, `?callable`, and the nullable resource arguments on the credentials backend model).

### Changed

- Minimum PHP version is 8.2 (`composer.json` `php: >=8.2.0`). PHP 8.1 and older are end-of-life and are affected by PHP CGI argument-injection issues (php2shell). Magento 2.4.6 is the oldest release that officially supports PHP 8.2.

## 1.2.0 - 2026-09-30

- Module version recorded in `etc/module.xml` and the README. See git history for the multistore sync fixes included in that release.
