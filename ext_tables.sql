#
# Columns are generated from TCA. This file only adds indexes.
#
CREATE TABLE tx_contentreminder_reminder (
	KEY page_status (pid, deleted, status),
	KEY assignee_due (assignee, status, due_date),
	KEY status_due (status, due_date)
);

CREATE TABLE tx_contentreminder_reminder_log (
	KEY page (page, completed_at),
	KEY reminder (reminder),
	KEY completed_at (completed_at)
);
