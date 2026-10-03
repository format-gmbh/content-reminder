<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Service;

use Formatsoft\ContentReminder\Domain\Model\RecurrenceUnit;

/**
 * Calculates the next due date of a recurring reminder.
 *
 * The interval is counted from the day of completion (not from the previous
 * due date). Time of day is ignored. When adding months or years, the day is
 * clamped to the last day of the target month (31.08. + 6 months = 28./29.02.).
 */
final class RecurrenceCalculator
{
    /**
     * @return \DateTimeImmutable|null Next due date at 00:00, or null if the reminder does not recur
     */
    public function calculateNextDueDate(
        \DateTimeImmutable $completedAt,
        RecurrenceUnit $unit,
        int $value,
    ): ?\DateTimeImmutable {
        if ($unit === RecurrenceUnit::None) {
            return null;
        }
        if ($value < 1) {
            throw new \InvalidArgumentException(
                sprintf('Recurrence value must be at least 1, %d given.', $value),
                1791014400
            );
        }

        $day = $completedAt->setTime(0, 0);

        return match ($unit) {
            RecurrenceUnit::Day => $day->modify(sprintf('+%d days', $value)),
            RecurrenceUnit::Week => $day->modify(sprintf('+%d weeks', $value)),
            RecurrenceUnit::Month => $this->addMonths($day, $value),
            RecurrenceUnit::Year => $this->addMonths($day, 12 * $value),
        };
    }

    /**
     * Unlike DateTime::modify('+n months'), this never overflows into the following month.
     */
    private function addMonths(\DateTimeImmutable $date, int $months): \DateTimeImmutable
    {
        $monthIndex = (int)$date->format('n') - 1 + $months;
        $year = (int)$date->format('Y') + intdiv($monthIndex, 12);
        $month = $monthIndex % 12 + 1;

        $firstOfTargetMonth = $date->setDate($year, $month, 1);
        $lastDayOfTargetMonth = (int)$firstOfTargetMonth->format('t');

        return $date->setDate($year, $month, min((int)$date->format('j'), $lastDayOfTargetMonth));
    }
}
