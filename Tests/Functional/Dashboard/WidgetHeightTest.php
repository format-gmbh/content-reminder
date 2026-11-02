<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Tests\Functional\Dashboard;

use Formatsoft\ContentReminder\Upgrades\DashboardWidgetHeightUpgradeWizard;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Dashboard\WidgetRegistry;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * List widgets are medium high, "large" variants can be chosen separately; the upgrade
 * wizard reduces the height stored in existing dashboards.
 */
final class WidgetHeightTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['dashboard', 'install'];

    protected array $testExtensionsToLoad = ['formatsoft/content-reminder'];

    #[Test]
    public function listWidgetsAreMediumHighWithLargeVariants(): void
    {
        $heights = [];
        foreach ($this->get(WidgetRegistry::class)->getAllWidgets() as $identifier => $configuration) {
            if (str_starts_with((string)$identifier, 'contentReminder')) {
                $heights[(string)$identifier] = $configuration->getHeight();
            }
        }
        ksort($heights);

        self::assertSame([
            'contentReminderAll' => 'medium',
            'contentReminderAllLarge' => 'large',
            'contentReminderDueCount' => 'small',
            'contentReminderMine' => 'medium',
            'contentReminderMineLarge' => 'large',
            'contentReminderOverdue' => 'medium',
            'contentReminderRecentlyCompleted' => 'medium',
            'contentReminderRecentlyCompletedLarge' => 'large',
            'contentReminderStatus' => 'medium',
            'contentReminderUnassigned' => 'medium',
        ], $heights);
    }

    #[Test]
    public function upgradeWizardReducesStoredHeightOfReminderWidgetsOnly(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('be_dashboards');
        $moved = [
            'a' => ['identifier' => 'contentReminderMine', 'positions' => ['4' => ['height' => 3, 'width' => 2, 'y' => 0, 'x' => 0], '1' => ['height' => 3, 'width' => 1, 'y' => 0, 'x' => 0]]],
            'b' => ['identifier' => 'contentReminderOverdue', 'positions' => ['4' => ['height' => 2, 'width' => 2, 'y' => 0, 'x' => 2]]],
            'c' => ['identifier' => 'contentReminderMineLarge', 'positions' => ['4' => ['height' => 3, 'width' => 2, 'y' => 3, 'x' => 0]]],
            'd' => ['identifier' => 'recentDocuments', 'positions' => ['4' => ['height' => 3, 'width' => 2, 'y' => 3, 'x' => 2]]],
            'e' => ['identifier' => 'contentReminderRecentlyCompleted', 'settings' => []],
        ];
        $connection->insert('be_dashboards', ['uid' => 1, 'identifier' => 'moved', 'title' => 'Moved widgets', 'widgets' => json_encode($moved)]);
        $connection->insert('be_dashboards', ['uid' => 2, 'identifier' => 'other', 'title' => 'Other widgets', 'widgets' => json_encode(['x' => ['identifier' => 'sysLogErrors']])]);

        $wizard = new DashboardWidgetHeightUpgradeWizard($this->getConnectionPool());
        self::assertTrue($wizard->updateNecessary());
        self::assertTrue($wizard->executeUpdate());
        self::assertFalse($wizard->updateNecessary());

        $widgets = json_decode((string)$connection->select(['widgets'], 'be_dashboards', ['uid' => 1])->fetchOne(), true);
        self::assertSame(2, $widgets['a']['positions']['4']['height'], 'medium widget reduced');
        self::assertSame(2, $widgets['a']['positions']['1']['height'], 'every column layout reduced');
        self::assertSame(['height' => 2, 'width' => 2, 'y' => 0, 'x' => 0], $widgets['a']['positions']['4'], 'position unchanged');
        self::assertSame(3, $widgets['c']['positions']['4']['height'], 'large variant unchanged');
        self::assertSame(3, $widgets['d']['positions']['4']['height'], 'widgets of other extensions unchanged');
        self::assertSame($moved['e'], $widgets['e'], 'widget without stored positions unchanged');
    }
}
