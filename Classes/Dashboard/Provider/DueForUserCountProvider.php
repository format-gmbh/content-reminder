<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Dashboard\Provider;

use Formatsoft\ContentReminder\Dashboard\ReminderOverviewProvider;
use TYPO3\CMS\Dashboard\Widgets\NumberWithIconDataProviderInterface;

/**
 * Number of reminders due for the current user (core widget NumberWithIconWidget).
 */
final readonly class DueForUserCountProvider implements NumberWithIconDataProviderInterface
{
    public function __construct(
        private ReminderOverviewProvider $provider,
    ) {}

    public function getNumber(): int
    {
        return $this->provider->countDueForUser();
    }
}
