# Content Reminder for TYPO3

Reminders attached to TYPO3 pages – with due date, optional recurrence,
responsible person and an archive of completed tasks – so that editorial
content such as prices, seasonal notes or contact persons stays up to date.

| | |
|---|---|
| Extension key | `content_reminder` |
| Composer | `formatsoft/content-reminder` |
| TYPO3 | 13.4 LTS, 14.x |
| PHP | 8.2 – 8.5 |
| License | GPL-2.0-or-later |

## Features

- **Reminders on pages** with title, notes, optional due date (empty = due
  now) and a responsible backend user. Reminders are language independent.
- **Recurrence**: every *n* days, weeks, months or years. The next due date
  is counted from the day of completion; month ends are handled correctly
  (31 Aug + 6 months = 28/29 Feb).
- **Archive**: every completion is logged with date, person and optional
  comment. The archive survives the deletion of pages and reminders.
- **Page tree**: a pencil icon marks pages with reminders that are due for
  you, a person icon marks pages with due reminders nobody is responsible for.
- **Page module**: a panel above the content elements shows your due
  reminders with the actions *Done*, *Take over*, *Edit* and *Pause*, plus
  links to all reminders of the page and to the completion history.
- **List module**: filter *Only mine* / *Only due* and row actions
  *Done*, *Take over*, *Resume*, *Reopen* and *Completion history*.
- **Dashboard**: widgets *My reminders*, *All reminders*, *Without
  responsible person*, *Overdue*, *Recently completed*, a counter and a status
  chart – and the dashboard preset *Content maintenance*.

## Installation

```bash
composer require formatsoft/content-reminder
```

Then update the database schema, e.g.:

```bash
vendor/bin/typo3 extension:setup -e content_reminder
```

Optionally add the site set **Content Reminder** (`formatsoft/content-reminder`)
as a dependency of your site to configure the extension per site.

## Permissions

Permissions are based on standard TYPO3 access rights; there is no separate
permission management.

1. **Table permissions**: grant `tx_contentreminder_reminder` in
   *Tables (listing)* and *Tables (modify)* of the backend user group.
2. **Page permissions**: users need *Show page* to see reminders and
   *Edit content* to create, complete or take over reminders on a page.
3. **Custom options** (tab *Access Lists*, section *Content Reminder*):
   - *Assign reminders to other users* – assign reminders to colleagues
   - *Manage all reminders* – edit, complete and delete reminders of others

| Action | Allowed for |
|---|---|
| See reminders and history of a page | read access |
| Create a reminder for oneself / unassigned | write access |
| Assign a reminder to somebody else | *Assign reminders to other users* |
| Take over an unassigned reminder | write access |
| Take over a reminder assigned to somebody else | *Assign …* or *Manage all …* |
| Edit, pause, resume, reopen | creator, responsible person, *Manage all …* |
| Mark as done | responsible person; anybody with write access if unassigned; *Manage all …* |
| Delete | creator, *Manage all …* |

The rules are enforced for every write access through the DataHandler
(editing form, list module, clipboard, API). If a reminder is assigned to a
person without access to the page, it is saved with a warning.

## Configuration (site settings)

| Setting | Default | Description |
|---|---|---|
| `contentReminder.assignableGroups` | empty (all) | Comma-separated list of backend user group uids whose members can be assigned |

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

To test against TYPO3 v13 instead of the latest version:

```bash
composer update --with "typo3/cms-core:^13.4" --with "typo3/cms-backend:^13.4" --with "typo3/cms-dashboard:^13.4"
```

## License

GPL-2.0-or-later, see [LICENSE.txt](LICENSE.txt).
