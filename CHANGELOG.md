# Changelog

All notable changes to this extension are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/),
versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed

- Page tree is updated after reminders were saved or deleted in the editing form
  or list module, so that the markers are up to date
- Page module panel: heading "Due without responsible person" in the same size as the
  panel title

## [0.4.0] - 2026-10-04

### Added

- Archive table registered for the scheduler task "Table garbage collection"
  (date field: completion date, default retention 2 years)

## [0.3.0] - 2026-10-03

### Added

- Backend module "Reminders" below "Web"/"Content" with page tree: reminders of the
  selected page and subpages with filters (person, state) and actions, and the
  archive of completions with filters (person, period)

## [0.2.0] - 2026-10-03

### Added

- Weekly email with overdue and upcoming reminders per responsible person and
  site, sent by the command `content-reminder:send-weekly-mail` (to be run daily
  via the scheduler); German and English
- Site settings for the weekly email (weekday, lookahead, sender, recipient for
  unassigned reminders) and `contentReminder.backendUrl`
- User setting to opt out of the weekly email
- Button "Create reminder for this page" in the button bar of the page module

### Fixed

- Editing form: the list of responsible persons failed with an SQL error
  (ORDER BY in foreign_table_where)

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

[Unreleased]: https://github.com/format-gmbh/content-reminder/compare/0.4.0...main
[0.4.0]: https://github.com/format-gmbh/content-reminder/compare/0.3.0...0.4.0
[0.3.0]: https://github.com/format-gmbh/content-reminder/compare/0.2.0...0.3.0
[0.2.0]: https://github.com/format-gmbh/content-reminder/compare/0.1.0...0.2.0
[0.1.0]: https://github.com/format-gmbh/content-reminder/releases/tag/0.1.0
