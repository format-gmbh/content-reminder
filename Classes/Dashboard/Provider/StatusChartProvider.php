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
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Dashboard\Widgets\ChartDataProviderInterface;

/**
 * Open reminders by state for the core widget DoughnutChartWidget.
 */
final readonly class StatusChartProvider implements ChartDataProviderInterface
{
    private const LL = 'LLL:EXT:content_reminder/Resources/Private/Language/locallang_be.xlf:widget.status.';

    /** Colors per state: not yet due, due, overdue, paused */
    private const COLORS = ['#3d8a5a', '#2f75b5', '#e8a33d', '#8c8c8c'];

    public function __construct(
        private ReminderOverviewProvider $provider,
    ) {}

    public function getChartData(): array
    {
        $counts = $this->provider->countByState();
        $languageService = $this->getLanguageService();

        return [
            'labels' => [
                $languageService->sL(self::LL . 'notDue'),
                $languageService->sL(self::LL . 'due'),
                $languageService->sL(self::LL . 'overdue'),
                $languageService->sL(self::LL . 'paused'),
            ],
            'datasets' => [
                [
                    'backgroundColor' => self::COLORS,
                    'border' => 0,
                    'data' => [$counts['notDue'], $counts['due'], $counts['overdue'], $counts['paused']],
                ],
            ],
        ];
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
