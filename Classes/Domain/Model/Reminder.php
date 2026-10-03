<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Domain\Model;

/**
 * Immutable representation of a tx_contentreminder_reminder record.
 *
 * Due dates are calendar days without time. They are represented as
 * DateTimeImmutable at 00:00 in the PHP default time zone.
 */
final readonly class Reminder
{
    public const TABLE = 'tx_contentreminder_reminder';

    public function __construct(
        public int $uid,
        public int $pageUid,
        public string $title,
        public string $notes,
        public ?\DateTimeImmutable $dueDate,
        public RecurrenceUnit $recurrenceUnit,
        public int $recurrenceValue,
        public int $assignee,
        public int $creator,
        public ReminderStatus $status,
        public bool $paused,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            uid: (int)($row['uid'] ?? 0),
            pageUid: (int)($row['pid'] ?? 0),
            title: (string)($row['title'] ?? ''),
            notes: (string)($row['notes'] ?? ''),
            dueDate: self::parseDate($row['due_date'] ?? null),
            recurrenceUnit: RecurrenceUnit::fromDatabaseValue($row['recurrence_unit'] ?? null),
            recurrenceValue: max(1, (int)($row['recurrence_value'] ?? 1)),
            assignee: (int)($row['assignee'] ?? 0),
            creator: (int)($row['creator'] ?? 0),
            status: ReminderStatus::fromDatabaseValue($row['status'] ?? 0),
            paused: (bool)($row['hidden'] ?? false),
        );
    }

    public function isOpen(): bool
    {
        return $this->status === ReminderStatus::Open;
    }

    public function isDone(): bool
    {
        return $this->status === ReminderStatus::Done;
    }

    public function isRecurring(): bool
    {
        return $this->recurrenceUnit !== RecurrenceUnit::None;
    }

    public function isUnassigned(): bool
    {
        return $this->assignee === 0;
    }

    public function isAssignedTo(int $userUid): bool
    {
        return $userUid > 0 && $this->assignee === $userUid;
    }

    public function isCreatedBy(int $userUid): bool
    {
        return $userUid > 0 && $this->creator === $userUid;
    }

    /**
     * Open, not paused and either without due date or due today or earlier.
     */
    public function isDue(\DateTimeImmutable $today): bool
    {
        if (!$this->isOpen() || $this->paused) {
            return false;
        }
        return $this->dueDate === null || $this->dueDate <= $today->setTime(0, 0);
    }

    /**
     * Due date lies before today. Reminders without due date are never overdue.
     */
    public function isOverdue(\DateTimeImmutable $today): bool
    {
        return $this->isDue($today)
            && $this->dueDate !== null
            && $this->dueDate < $today->setTime(0, 0);
    }

    private static function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
        return $date === false ? null : $date;
    }
}
