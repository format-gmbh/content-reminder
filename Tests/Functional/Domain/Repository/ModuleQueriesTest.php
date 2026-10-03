<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Tests\Functional\Domain\Repository;

use Formatsoft\ContentReminder\Domain\Model\Reminder;
use Formatsoft\ContentReminder\Domain\Repository\ReminderLogRepository;
use Formatsoft\ContentReminder\Domain\Repository\ReminderRepository;
use Formatsoft\ContentReminder\Mail\SitePageCollector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Queries of the backend module: page subtrees, state filter, archive filter.
 */
final class ModuleQueriesTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['dashboard'];

    protected array $testExtensionsToLoad = ['formatsoft/content-reminder'];

    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/WeeklyMail.csv');
        $this->today = new \DateTimeImmutable('2026-10-03');
    }

    #[Test]
    public function subtreesContainChildrenButNoDeletedPages(): void
    {
        $collector = new SitePageCollector($this->getConnectionPool());

        self::assertSame([1, 2, 3], $this->sorted(array_keys($collector->collectSubtrees([1]))));
        self::assertSame([2, 3], $this->sorted(array_keys($collector->collectSubtrees([2]))));
        // Root 0 = whole tree
        self::assertSame([1, 2, 3, 10], $this->sorted(array_keys($collector->collectSubtrees([0]))));
    }

    public static function stateDataProvider(): \Generator
    {
        // Reminders on pages 1-3, see Fixtures/WeeklyMail.csv
        yield 'open includes paused, excludes done' => ['open', null, [1, 2, 3, 4, 6, 9, 10, 11, 12, 13]];
        yield 'due' => ['due', null, [1, 2, 9, 10, 11, 12, 13]];
        yield 'overdue' => ['overdue', null, [1]];
        yield 'upcoming (30 days)' => ['upcoming', null, [1, 2, 3, 4, 9, 10, 11, 12, 13]];
        yield 'paused' => ['paused', null, [6]];
        yield 'done' => ['done', null, [5]];
        yield 'all' => ['all', null, [1, 2, 3, 4, 5, 6, 9, 10, 11, 12, 13]];
        yield 'open, unassigned' => ['open', 0, [9]];
        yield 'open, assigned to user 2' => ['open', 2, [1, 2, 3, 4, 6]];
    }

    /**
     * @param list<int> $expected
     */
    #[Test]
    #[DataProvider('stateDataProvider')]
    public function stateAndAssigneeFiltersAreApplied(string $state, ?int $assignee, array $expected): void
    {
        $reminders = (new ReminderRepository($this->getConnectionPool()))->findForPages([1, 2, 3], $state, $assignee, $this->today, 100);

        self::assertSame($expected, $this->sorted(array_map(static fn(Reminder $reminder): int => $reminder->uid, $reminders)));
    }

    #[Test]
    public function archiveIsFilteredByPagePersonAndPeriod(): void
    {
        $repository = new ReminderLogRepository($this->getConnectionPool());
        $repository->add(1, 'Old', 2, 'Prices', 2, 'Eddi', new \DateTimeImmutable('2026-01-10'), null, null, '');
        $repository->add(1, 'Recent', 2, 'Prices', 2, 'Eddi', new \DateTimeImmutable('2026-09-20'), null, null, '');
        $repository->add(3, 'Colleague', 2, 'Prices', 3, 'Carla', new \DateTimeImmutable('2026-09-25'), null, null, '');
        $repository->add(8, 'Other site', 10, 'Other', 2, 'Eddi', new \DateTimeImmutable('2026-09-25'), null, null, '');

        $titles = static fn(array $entries): array => array_column($entries, 'reminder_title');

        self::assertSame(['Colleague', 'Recent', 'Old'], $titles($repository->findForPages([1, 2, 3], null, null, 100)));
        self::assertSame(['Recent', 'Old'], $titles($repository->findForPages([1, 2, 3], 2, null, 100)));
        self::assertSame(['Colleague', 'Recent'], $titles($repository->findForPages([1, 2, 3], null, $this->today->modify('-90 days'), 100)));
    }

    /**
     * @param list<int> $values
     * @return list<int>
     */
    private function sorted(array $values): array
    {
        sort($values);
        return $values;
    }
}
