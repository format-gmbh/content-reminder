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
 * Unit of the interval after which a recurring reminder becomes due again.
 * The values are stored in tx_contentreminder_reminder.recurrence_unit.
 */
enum RecurrenceUnit: string
{
    case None = 'none';
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';

    public static function fromDatabaseValue(mixed $value): self
    {
        return self::tryFrom((string)$value) ?? self::None;
    }
}
