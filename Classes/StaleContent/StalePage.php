<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\StaleContent;

/**
 * A page whose content has not been changed for too long.
 */
final readonly class StalePage
{
    public function __construct(
        public int $uid,
        public string $title,
        public \DateTimeImmutable $lastChange,
    ) {}
}
