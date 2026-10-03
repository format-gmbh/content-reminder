<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Tests\Unit\Service;

use Formatsoft\ContentReminder\Domain\Model\RecurrenceUnit;
use Formatsoft\ContentReminder\Service\RecurrenceCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class RecurrenceCalculatorTest extends UnitTestCase
{
    private RecurrenceCalculator $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new RecurrenceCalculator();
    }

    #[Test]
    public function returnsNullForNonRecurringReminder(): void
    {
        self::assertNull(
            $this->subject->calculateNextDueDate(new \DateTimeImmutable('2026-03-15'), RecurrenceUnit::None, 6)
        );
    }

    public static function nextDueDateDataProvider(): \Generator
    {
        // Example from the concept: due 01.03., completed 15.03., every 6 months -> 15.09.
        yield 'counted from completion, 6 months' => ['2026-03-15', RecurrenceUnit::Month, 6, '2026-09-15'];
        yield '1 day' => ['2026-03-15', RecurrenceUnit::Day, 1, '2026-03-16'];
        yield '30 days across month end' => ['2026-01-15', RecurrenceUnit::Day, 30, '2026-02-14'];
        yield '2 weeks' => ['2026-03-15', RecurrenceUnit::Week, 2, '2026-03-29'];
        yield '1 month' => ['2026-01-10', RecurrenceUnit::Month, 1, '2026-02-10'];
        yield '12 months across year end' => ['2026-11-30', RecurrenceUnit::Month, 12, '2027-11-30'];
        yield '3 months across year end' => ['2026-11-30', RecurrenceUnit::Month, 3, '2027-02-28'];
        yield '1 year' => ['2026-03-15', RecurrenceUnit::Year, 1, '2027-03-15'];
        // Month ends are clamped instead of overflowing into the next month
        yield '31.08. + 6 months -> 28.02.' => ['2026-08-31', RecurrenceUnit::Month, 6, '2027-02-28'];
        yield '31.08. + 6 months -> 29.02. in leap year' => ['2027-08-31', RecurrenceUnit::Month, 6, '2028-02-29'];
        yield '31.01. + 1 month' => ['2026-01-31', RecurrenceUnit::Month, 1, '2026-02-28'];
        yield '31.03. + 1 month' => ['2026-03-31', RecurrenceUnit::Month, 1, '2026-04-30'];
        yield '29.02. + 1 year' => ['2028-02-29', RecurrenceUnit::Year, 1, '2029-02-28'];
        yield '29.02. + 4 years' => ['2028-02-29', RecurrenceUnit::Year, 4, '2032-02-29'];
    }

    #[Test]
    #[DataProvider('nextDueDateDataProvider')]
    public function calculatesNextDueDateFromCompletionDay(string $completedAt, RecurrenceUnit $unit, int $value, string $expected): void
    {
        $result = $this->subject->calculateNextDueDate(new \DateTimeImmutable($completedAt), $unit, $value);

        self::assertSame($expected, $result?->format('Y-m-d'));
    }

    #[Test]
    public function ignoresTimeOfCompletion(): void
    {
        $result = $this->subject->calculateNextDueDate(new \DateTimeImmutable('2026-03-15 23:59:59'), RecurrenceUnit::Day, 1);

        self::assertSame('2026-03-16 00:00:00', $result?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function keepsMidnightAcrossDaylightSavingTimeChange(): void
    {
        $timeZone = new \DateTimeZone('Europe/Berlin');
        // DST starts on 2026-03-29 in Europe/Berlin
        $result = $this->subject->calculateNextDueDate(new \DateTimeImmutable('2026-03-28 10:00', $timeZone), RecurrenceUnit::Day, 2);

        self::assertSame('2026-03-30 00:00:00', $result?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function throwsExceptionForIntervalBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1791014400);

        $this->subject->calculateNextDueDate(new \DateTimeImmutable('2026-03-15'), RecurrenceUnit::Month, 0);
    }
}
