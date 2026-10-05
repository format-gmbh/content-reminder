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
 * How a reminder was created (field "origin").
 */
enum ReminderOrigin: int
{
    case Manual = 0;
    /** Created automatically for a page with outdated content (concept 6.3) */
    case StaleContent = 1;
}
