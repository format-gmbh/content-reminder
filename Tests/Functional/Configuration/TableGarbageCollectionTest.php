<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Tests\Functional\Configuration;

use Formatsoft\ContentReminder\Domain\Repository\ReminderLogRepository;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\TableGarbageCollectionTask;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The archive can be cleaned up by the scheduler task "Table garbage collection"
 * with a generous default retention (2 years).
 */
final class TableGarbageCollectionTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['dashboard', 'scheduler'];

    protected array $testExtensionsToLoad = ['formatsoft/content-reminder'];

    #[Test]
    public function archiveTableIsRegisteredWithCompletionDateAndTwoYears(): void
    {
        self::assertSame(
            ['dateField' => 'completed_at', 'expirePeriod' => 730],
            $this->getTableConfiguration()[ReminderLogRepository::TABLE] ?? null
        );
    }

    #[Test]
    public function taskForAllTablesRemovesOnlyEntriesOlderThanTwoYears(): void
    {
        $logRepository = new ReminderLogRepository($this->getConnectionPool());
        $logRepository->add(1, 'Completed three years ago', 1, 'Page', 1, 'Admin', new \DateTimeImmutable('-3 years'), null, null, '');
        $logRepository->add(1, 'Completed last year', 1, 'Page', 1, 'Admin', new \DateTimeImmutable('-1 year'), null, null, '');

        $task = GeneralUtility::makeInstance(TableGarbageCollectionTask::class);
        $task->allTables = true;
        self::assertTrue($task->execute());

        $titles = $this->getConnectionPool()->getConnectionForTable(ReminderLogRepository::TABLE)
            ->select(['reminder_title'], ReminderLogRepository::TABLE)
            ->fetchFirstColumn();
        self::assertSame(['Completed last year'], $titles);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getTableConfiguration(): array
    {
        if ((new Typo3Version())->getMajorVersion() < 14) {
            return $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][TableGarbageCollectionTask::class]['options']['tables'] ?? [];
        }
        return $GLOBALS['TCA']['tx_scheduler_task']['types'][TableGarbageCollectionTask::class]['taskOptions']['tables'] ?? [];
    }
}
