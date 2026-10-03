# Changelog

All notable changes to this extension are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/),
versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Weekly email with overdue and upcoming reminders per responsible person and
  site, sent by the command `content-reminder:send-weekly-mail` (to be run daily
  via the scheduler); German and English
- Site settings for the weekly email (weekday, lookahead, sender, recipient for
  unassigned reminders) and `contentReminder.backendUrl`
- User setting to opt out of the weekly email

## [0.1.0] - 2026-10-03

First public release for TYPO3 13.4 LTS and 14.x.

### Added

- Reminders on pages with optional due date, notes, responsible person and
  recurrence (every n days, weeks, months or years, counted from completion)
- Archive of completed reminders
- Permissions based on table and page permissions plus the custom options
  "Assign reminders to other users" and "Manage all reminders", enforced by
  a DataHandler hook
- Page tree marker for due reminders
- Panel in the page module with actions and completion history
- Filter and row actions in the list module
- Dashboard widgets and the dashboard preset "Content maintenance"
- Site set "Content Reminder" with the setting `contentReminder.assignableGroups`

[Unreleased]: https://github.com/format-gmbh/content-reminder/compare/0.1.0...main
[0.1.0]: https://github.com/format-gmbh/content-reminder/releases/tag/0.1.0
