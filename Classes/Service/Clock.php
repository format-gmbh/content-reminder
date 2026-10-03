<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Service;

use TYPO3\CMS\Core\Context\Context;

/**
 * Current time as seen by TYPO3 (date aspect of the context, i.e. EXEC_TIME),
 * so that tests can control it.
 */
class Clock
{
    public function __construct(
        private readonly Context $context,
    ) {}

    public function now(): \DateTimeImmutable
    {
        $now = $this->context->getPropertyFromAspect('date', 'full');
        if (!$now instanceof \DateTimeImmutable) {
            return new \DateTimeImmutable();
        }
        // The date aspect may carry UTC; due dates are calendar days in the server time zone
        return $now->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    public function today(): \DateTimeImmutable
    {
        return $this->now()->setTime(0, 0);
    }
}
