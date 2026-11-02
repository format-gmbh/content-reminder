<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Upgrades;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

/**
 * The reminder list widgets are medium high since 1.0.0 (higher "large" variants exist
 * separately). Dashboards store the height of each widget once it has been moved, so
 * existing dashboards keep the former height without this wizard.
 *
 * Uses the EXT:install classes, which TYPO3 v14 still provides as subclasses of the
 * new core classes; this keeps one implementation for v13 and v14.
 */
#[UpgradeWizard('contentReminder_dashboardWidgetHeight')]
final class DashboardWidgetHeightUpgradeWizard implements UpgradeWizardInterface
{
    /** Widgets that are medium high now */
    public const MEDIUM_WIDGETS = [
        'contentReminderMine',
        'contentReminderAll',
        'contentReminderUnassigned',
        'contentReminderOverdue',
        'contentReminderRecentlyCompleted',
    ];

    /** Grid rows of a medium high widget (dashboard JavaScript, v13 and v14) */
    private const MEDIUM_ROWS = 2;

    private const TABLE = 'be_dashboards';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function getTitle(): string
    {
        return 'Content Reminder: medium height for reminder widgets on existing dashboards';
    }

    public function getDescription(): string
    {
        return 'The widgets "My reminders", "All reminders", "Reminders without responsible person", '
            . '"Overdue reminders" and "Recently completed" are medium high now. Higher variants can be '
            . 'added in the widget wizard. This wizard reduces the height stored in existing dashboards.';
    }

    public function updateNecessary(): bool
    {
        foreach ($this->fetchDashboards() as $widgets) {
            if ($this->reduceHeights($widgets) !== null) {
                return true;
            }
        }
        return false;
    }

    public function executeUpdate(): bool
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        foreach ($this->fetchDashboards() as $uid => $widgets) {
            $updated = $this->reduceHeights($widgets);
            if ($updated !== null) {
                $connection->update(self::TABLE, ['widgets' => json_encode($updated, JSON_THROW_ON_ERROR)], ['uid' => $uid]);
            }
        }
        return true;
    }

    /**
     * @return string[]
     */
    public function getPrerequisites(): array
    {
        return [];
    }

    /**
     * @param array<mixed> $widgets decoded field be_dashboards.widgets
     * @return array<mixed>|null the changed widgets, or null if nothing changes
     */
    public function reduceHeights(array $widgets): ?array
    {
        $changed = false;
        foreach ($widgets as $hash => $widget) {
            if (!is_array($widget) || !in_array($widget['identifier'] ?? null, self::MEDIUM_WIDGETS, true)) {
                continue;
            }
            foreach (is_array($widget['positions'] ?? null) ? $widget['positions'] : [] as $columns => $position) {
                if (is_array($position) && (int)($position['height'] ?? 0) > self::MEDIUM_ROWS) {
                    $widgets[$hash]['positions'][$columns]['height'] = self::MEDIUM_ROWS;
                    $changed = true;
                }
            }
        }
        return $changed ? $widgets : null;
    }

    /**
     * @return array<int, array<mixed>> dashboard uid => decoded widgets
     */
    private function fetchDashboards(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('uid', 'widgets')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->like('widgets', $queryBuilder->createNamedParameter('%contentReminder%')))
            ->executeQuery()
            ->fetchAllAssociative();
        $dashboards = [];
        foreach ($rows as $row) {
            $widgets = json_decode((string)$row['widgets'], true);
            if (is_array($widgets)) {
                $dashboards[(int)$row['uid']] = $widgets;
            }
        }
        return $dashboards;
    }
}
