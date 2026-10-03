<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Mail;

/**
 * One weekly mail: one recipient, one site (E6).
 */
final readonly class WeeklyMail
{
    /**
     * @param list<array{uid: int, title: string, notes: string, pageUid: int, pageTitle: string, dueDate: string, overdue: bool, url: string}> $overdue
     * @param list<array{uid: int, title: string, notes: string, pageUid: int, pageTitle: string, dueDate: string, overdue: bool, url: string}> $upcoming
     *        due today/without date and due within the lookahead period
     */
    public function __construct(
        public string $recipientEmail,
        public string $recipientName,
        public string $language,
        public bool $unassignedDigest,
        public string $siteTitle,
        public array $overdue,
        public array $upcoming,
    ) {}

    public function count(): int
    {
        return count($this->overdue) + count($this->upcoming);
    }
}
