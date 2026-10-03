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
 * Number of due reminders on one page from the point of view of one backend user.
 * Used for the page tree marker.
 */
final readonly class PageDueSummary
{
    public function __construct(
        public int $pageUid,
        public int $dueForUser,
        public int $overdueForUser,
        public int $dueUnassigned,
    ) {}

    public function hasDueForUser(): bool
    {
        return $this->dueForUser > 0;
    }

    public function hasOverdueForUser(): bool
    {
        return $this->overdueForUser > 0;
    }

    public function hasDueUnassigned(): bool
    {
        return $this->dueUnassigned > 0;
    }
}
