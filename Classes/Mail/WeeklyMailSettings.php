<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Mail;

use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Settings of the weekly mail for one site (site set "Content Reminder").
 */
final readonly class WeeklyMailSettings
{
    public const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public function __construct(
        public bool $enabled = true,
        public string $weekday = 'monday',
        public string $fromAddress = '',
        public string $fromName = '',
        public string $unassignedRecipient = '',
        public int $lookaheadDays = 7,
        public string $backendUrl = '',
    ) {}

    public static function fromSite(Site $site): self
    {
        $settings = $site->getSettings();
        $weekday = strtolower((string)$settings->get('contentReminder.mail.weekday', 'monday'));
        $backendUrl = trim((string)$settings->get('contentReminder.backendUrl', ''));
        if ($backendUrl === '') {
            $backendUrl = self::deriveBackendUrl($site);
        }

        return new self(
            enabled: (bool)$settings->get('contentReminder.mail.enabled', true),
            weekday: in_array($weekday, self::WEEKDAYS, true) ? $weekday : 'monday',
            fromAddress: trim((string)$settings->get('contentReminder.mail.fromAddress', '')),
            fromName: trim((string)$settings->get('contentReminder.mail.fromName', '')),
            unassignedRecipient: trim((string)$settings->get('contentReminder.mail.unassignedRecipient', '')),
            lookaheadDays: max(0, (int)$settings->get('contentReminder.mail.lookaheadDays', 7)),
            backendUrl: rtrim($backendUrl, '/'),
        );
    }

    public function isSendDay(\DateTimeImmutable $day): bool
    {
        return strtolower($day->format('l')) === $this->weekday;
    }

    /**
     * Link into the page module for a page, or '' if no backend URL is known.
     */
    public function getPageModuleUrl(int $pageUid): string
    {
        return $this->backendUrl === '' ? '' : $this->backendUrl . '/module/web/layout?id=' . $pageUid;
    }

    /**
     * "https://example.org/typo3" from the site base, '' if the base has no host.
     */
    private static function deriveBackendUrl(Site $site): string
    {
        $base = $site->getBase();
        if ($base->getHost() === '') {
            return '';
        }
        $entryPoint = trim((string)($GLOBALS['TYPO3_CONF_VARS']['BE']['entryPoint'] ?? '/typo3'), '/') ?: 'typo3';
        return ($base->getScheme() ?: 'https') . '://' . $base->getAuthority() . '/' . $entryPoint;
    }
}
