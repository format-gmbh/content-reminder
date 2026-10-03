# Content Reminder for TYPO3

Reminders attached to TYPO3 pages – with due date, optional recurrence,
responsible person and an archive of completed tasks – so that editorial
content stays up to date.

| | |
|---|---|
| Extension key | `content_reminder` |
| Composer | `formatsoft/content-reminder` |
| TYPO3 | 13.4 LTS, 14.x |
| License | GPL-2.0-or-later |

> Status: early development (0.1.0-dev). Not ready for production use.

## Installation

```bash
composer require formatsoft/content-reminder
```

Add the site set **Content Reminder** (`formatsoft/content-reminder`) as a
dependency of your site and run the database analyzer.

## Permissions

Grant backend user groups access to the table `tx_contentreminder_reminder`
(select / modify). Additional permissions are available in the
"Access Lists" tab under **Content Reminder**:

- *Assign reminders to other users*
- *Manage all reminders*

## Development

Dependencies for the tests are installed into `.Build/` of the extension:

```bash
composer install
composer test:unit
```

Functional tests need a database user that may create databases, e.g. in DDEV:

```bash
typo3DatabaseDriver=mysqli typo3DatabaseHost=db typo3DatabaseUsername=root \
typo3DatabasePassword=root typo3DatabaseName=ft_content_reminder \
composer test:functional
```
