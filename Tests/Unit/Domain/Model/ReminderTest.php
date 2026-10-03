<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Tests\Unit\Domain\Model;

use Formatsoft\ContentReminder\Domain\Model\RecurrenceUnit;
use Formatsoft\ContentReminder\Domain\Model\Reminder;
use Formatsoft\ContentReminder\Domain\Model\ReminderStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ReminderTest extends UnitTestCase
{
    #[Test]
    public function fromDatabaseRowMapsAllFields(): void
    {
        $subject = Reminder::fromDatabaseRow([
            'uid' => '12',
            'pid' => '34',
            'title' => 'Preise prüfen',
            'notes' => 'Preisliste 2027',
            'due_date' => '2026-09-15',
            'recurrence_unit' => 'month',
            'recurrence_value' => '6',
            'assignee' => '5',
            'creator' => '7',
            'status' => '1',
            'hidden' => '1',
        ]);

        self::assertSame(12, $subject->uid);
        self::assertSame(34, $subject->pageUid);
        self::assertSame('Preise prüfen', $subject->title);
        self::assertSame('Preisliste 2027', $subject->notes);
        self::assertSame('2026-09-15 00:00:00', $subject->dueDate?->format('Y-m-d H:i:s'));
        self::assertSame(RecurrenceUnit::Month, $subject->recurrenceUnit);
        self::assertSame(6, $subject->recurrenceValue);
        self::assertSame(5, $subject->assignee);
        self::assertSame(7, $subject->creator);
        self::assertSame(ReminderStatus::Done, $subject->status);
        self::assertTrue($subject->paused);
    }

    public static function emptyDueDateDataProvider(): \Generator
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
        yield 'zero date' => ['0000-00-00'];
        yield 'invalid' => ['not a date'];
    }

    #[Test]
    #[DataProvider('emptyDueDateDataProvider')]
    public function fromDatabaseRowTreatsMissingDueDateAsNull(?string $dueDate): void
    {
        self::assertNull(Reminder::fromDatabaseRow(['due_date' => $dueDate])->dueDate);
    }

    #[Test]
    public function fromDatabaseRowFallsBackToSafeDefaults(): void
    {
        $subject = Reminder::fromDatabaseRow(['recurrence_unit' => 'fortnight', 'recurrence_value' => 0, 'status' => 9]);

        self::assertSame(RecurrenceUnit::None, $subject->recurrenceUnit);
        self::assertSame(1, $subject->recurrenceValue);
        self::assertSame(ReminderStatus::Open, $subject->status);
        self::assertFalse($subject->isRecurring());
    }

    public static function dueDataProvider(): \Generator
    {
        // [due_date, status, hidden, expected isDue, expected isOverdue]
        yield 'no due date is due immediately' => [null, 0, 0, true, false];
        yield 'due yesterday is overdue' => ['2026-10-02', 0, 0, true, true];
        yield 'due today is due, not overdue' => ['2026-10-03', 0, 0, true, false];
        yield 'due tomorrow is not due' => ['2026-10-04', 0, 0, false, false];
        yield 'done is never due' => ['2026-10-02', 1, 0, false, false];
        yield 'paused is never due' => ['2026-10-02', 0, 1, false, false];
        yield 'paused without date is not due' => [null, 0, 1, false, false];
    }

    #[Test]
    #[DataProvider('dueDataProvider')]
    public function isDueAndIsOverdueFollowTheConcept(?string $dueDate, int $status, int $hidden, bool $expectedDue, bool $expectedOverdue): void
    {
        $subject = Reminder::fromDatabaseRow(['due_date' => $dueDate, 'status' => $status, 'hidden' => $hidden]);
        // Time of day must not matter
        $today = new \DateTimeImmutable('2026-10-03 17:45:00');

        self::assertSame($expectedDue, $subject->isDue($today));
        self::assertSame($expectedOverdue, $subject->isOverdue($today));
    }

    #[Test]
    public function assignmentHelpersIgnoreUserUidZero(): void
    {
        $unassigned = Reminder::fromDatabaseRow(['assignee' => 0, 'creator' => 0]);

        self::assertTrue($unassigned->isUnassigned());
        self::assertFalse($unassigned->isAssignedTo(0));
        self::assertFalse($unassigned->isCreatedBy(0));
    }
}
