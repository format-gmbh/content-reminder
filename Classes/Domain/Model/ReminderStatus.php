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
 * Stored status of a reminder. "Overdue" is not a status, it is derived
 * from the due date.
 */
enum ReminderStatus: int
{
    case Open = 0;
    case Done = 1;

    public static function fromDatabaseValue(mixed $value): self
    {
        return self::tryFrom((int)$value) ?? self::Open;
    }
}
