<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\StaleContent;

use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Site settings "contentReminder.stale.*" (concept 6.3).
 */
final readonly class StaleContentSettings
{
    /**
     * @param list<int> $excludePages roots of subtrees that are not checked
     */
    public function __construct(
        public bool $enabled = false,
        public int $months = 24,
        public array $excludePages = [],
        public int $limit = 50,
    ) {}

    public static function fromSite(Site $site): self
    {
        $settings = $site->getSettings();
        return new self(
            enabled: (bool)$settings->get('contentReminder.stale.enabled', false),
            months: max(1, (int)$settings->get('contentReminder.stale.months', 24)),
            excludePages: array_values(array_filter(
                GeneralUtility::intExplode(',', (string)$settings->get('contentReminder.stale.excludePages', ''), true),
                static fn(int $uid): bool => $uid > 0
            )),
            limit: max(1, (int)$settings->get('contentReminder.stale.limit', 50)),
        );
    }

    /**
     * Pages whose last change is before this point in time are outdated.
     */
    public function getThreshold(\DateTimeImmutable $today): \DateTimeImmutable
    {
        return $today->sub(new \DateInterval('P' . $this->months . 'M'));
    }
}
