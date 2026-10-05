<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\StaleContent;

use Doctrine\DBAL\ArrayParameterType;
use Formatsoft\ContentReminder\Domain\Model\Reminder;
use Formatsoft\ContentReminder\Domain\Model\ReminderOrigin;
use Formatsoft\ContentReminder\Domain\Model\ReminderStatus;
use Formatsoft\ContentReminder\Domain\Repository\ReminderLogRepository;
use Formatsoft\ContentReminder\Mail\SitePageCollector;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Finds the pages of a site whose content has not been changed for the configured
 * number of months (concept 6.3, E35–E40).
 *
 * Last change of a page = the latest of
 * - the change date of all records stored on the page (all tables with ctrl.tstamp,
 *   e.g. content elements incl. translations, file references), but not the page
 *   properties themselves (E35, E36)
 * - the last completion of an automatic reminder on the page, so that a page that
 *   was reviewed without changes does not get a new reminder right away
 * - the creation date of the page
 */
class StalePageFinder
{
    private const DOKTYPE_DEFAULT = 1;

    /** Tables that do not count as content of a page */
    private const IGNORED_TABLES = ['pages', Reminder::TABLE, ReminderLogRepository::TABLE];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly SitePageCollector $pageCollector,
    ) {}

    /**
     * @return list<StalePage> oldest first, at most $limit pages
     */
    public function find(int $rootPageUid, StaleContentSettings $settings, \DateTimeImmutable $today, int $limit): array
    {
        $pageUids = array_keys($this->pageCollector->collect($rootPageUid));
        if ($settings->excludePages !== []) {
            $pageUids = array_values(array_diff($pageUids, array_keys($this->pageCollector->collectSubtrees($settings->excludePages))));
        }
        $pages = $this->fetchCandidatePages($pageUids);
        if ($pages === []) {
            return [];
        }
        $candidateUids = array_keys($pages);
        $withOpenReminder = $this->findPagesWithOpenStaleReminder($candidateUids);
        $lastChanges = $this->collectLastChanges($candidateUids);
        $threshold = $settings->getThreshold($today)->getTimestamp();

        $stalePages = [];
        foreach ($pages as $uid => $page) {
            $lastChange = max($page['crdate'], $lastChanges[$uid] ?? 0);
            if ($lastChange >= $threshold || isset($withOpenReminder[$uid])) {
                continue;
            }
            $stalePages[] = new StalePage($uid, $page['title'], (new \DateTimeImmutable())->setTimestamp($lastChange));
        }
        usort(
            $stalePages,
            static fn(StalePage $a, StalePage $b): int => [$a->lastChange, $a->uid] <=> [$b->lastChange, $b->uid]
        );
        return array_slice($stalePages, 0, max(0, $limit));
    }

    /**
     * Standard pages that are neither hidden nor excluded in their page properties (E37, E40).
     *
     * @param list<int> $pageUids
     * @return array<int, array{title: string, crdate: int}>
     */
    private function fetchCandidatePages(array $pageUids): array
    {
        $pages = [];
        foreach (array_chunk($pageUids, 1000) as $chunk) {
            $queryBuilder = $this->createQueryBuilder('pages');
            $rows = $queryBuilder
                ->select('uid', 'title', 'crdate')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($chunk, ArrayParameterType::INTEGER)),
                    $queryBuilder->expr()->eq('doktype', $queryBuilder->createNamedParameter(self::DOKTYPE_DEFAULT, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('hidden', 0),
                    $queryBuilder->expr()->eq('deleted', 0),
                    $queryBuilder->expr()->eq('tx_contentreminder_stale_exclude', 0),
                )
                ->executeQuery()
                ->fetchAllAssociative();
            foreach ($rows as $row) {
                $pages[(int)$row['uid']] = ['title' => (string)$row['title'], 'crdate' => (int)$row['crdate']];
            }
        }
        ksort($pages);
        return $pages;
    }

    /**
     * Pages that already have an open (or paused) automatic reminder: no duplicates.
     *
     * @param list<int> $pageUids
     * @return array<int, true>
     */
    private function findPagesWithOpenStaleReminder(array $pageUids): array
    {
        $pages = [];
        foreach (array_chunk($pageUids, 1000) as $chunk) {
            $queryBuilder = $this->createQueryBuilder(Reminder::TABLE);
            $rows = $queryBuilder
                ->select('pid')
                ->from(Reminder::TABLE)
                ->where(
                    $queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($chunk, ArrayParameterType::INTEGER)),
                    $queryBuilder->expr()->eq('origin', $queryBuilder->createNamedParameter(ReminderOrigin::StaleContent->value, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter(ReminderStatus::Open->value, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('deleted', 0),
                )
                ->executeQuery()
                ->fetchFirstColumn();
            foreach ($rows as $pid) {
                $pages[(int)$pid] = true;
            }
        }
        return $pages;
    }

    /**
     * @param list<int> $pageUids
     * @return array<int, int> page uid => timestamp of the last change
     */
    private function collectLastChanges(array $pageUids): array
    {
        $lastChanges = [];
        foreach (array_chunk($pageUids, 1000) as $chunk) {
            foreach ($this->getContentTables() as $table => $tableConfig) {
                $this->mergeLatest($lastChanges, $this->fetchLatestRecordChanges($table, $tableConfig, $chunk));
            }
            $this->mergeLatest($lastChanges, $this->fetchLatestStaleCompletions($chunk));
        }
        return $lastChanges;
    }

    /**
     * @param array{tstamp: string, delete: string, versioning: bool} $tableConfig
     * @param list<int> $pageUids
     * @return array<int, int>
     */
    private function fetchLatestRecordChanges(string $table, array $tableConfig, array $pageUids): array
    {
        $queryBuilder = $this->createQueryBuilder($table);
        $queryBuilder
            ->selectLiteral('MAX(' . $queryBuilder->quoteIdentifier($tableConfig['tstamp']) . ') AS ' . $queryBuilder->quoteIdentifier('last_change'))
            ->addSelect('pid')
            ->from($table)
            ->where($queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($pageUids, ArrayParameterType::INTEGER)))
            ->groupBy('pid');
        if ($tableConfig['delete'] !== '') {
            $queryBuilder->andWhere($queryBuilder->expr()->eq($tableConfig['delete'], 0));
        }
        if ($tableConfig['versioning']) {
            // Only live records; changes in workspaces are not published yet
            $queryBuilder->andWhere($queryBuilder->expr()->eq('t3ver_wsid', 0));
        }
        return $this->toLatestByPage($queryBuilder->executeQuery()->fetchAllAssociative());
    }

    /**
     * Completions of automatic reminders count as review of the page. Deleted reminders
     * are included: their completion was a review all the same.
     *
     * @param list<int> $pageUids
     * @return array<int, int>
     */
    private function fetchLatestStaleCompletions(array $pageUids): array
    {
        $queryBuilder = $this->createQueryBuilder(ReminderLogRepository::TABLE);
        $rows = $queryBuilder
            ->selectLiteral('MAX(' . $queryBuilder->quoteIdentifier('log.completed_at') . ') AS ' . $queryBuilder->quoteIdentifier('last_change'))
            ->addSelect('reminder.pid')
            ->from(ReminderLogRepository::TABLE, 'log')
            ->join('log', Reminder::TABLE, 'reminder', $queryBuilder->expr()->eq('reminder.uid', $queryBuilder->quoteIdentifier('log.reminder')))
            ->where(
                $queryBuilder->expr()->in('reminder.pid', $queryBuilder->createNamedParameter($pageUids, ArrayParameterType::INTEGER)),
                $queryBuilder->expr()->eq('reminder.origin', $queryBuilder->createNamedParameter(ReminderOrigin::StaleContent->value, Connection::PARAM_INT)),
            )
            ->groupBy('reminder.pid')
            ->executeQuery()
            ->fetchAllAssociative();
        return $this->toLatestByPage($rows);
    }

    /**
     * Tables whose records belong to a page and have a change date.
     *
     * @return array<string, array{tstamp: string, delete: string, versioning: bool}>
     */
    private function getContentTables(): array
    {
        $tables = [];
        foreach ($GLOBALS['TCA'] ?? [] as $table => $configuration) {
            $ctrl = is_array($configuration) ? ($configuration['ctrl'] ?? []) : [];
            $tstamp = (string)($ctrl['tstamp'] ?? '');
            if ($tstamp === '' || in_array($table, self::IGNORED_TABLES, true) || (int)($ctrl['rootLevel'] ?? 0) === 1) {
                continue;
            }
            $tables[(string)$table] = [
                'tstamp' => $tstamp,
                'delete' => (string)($ctrl['delete'] ?? ''),
                'versioning' => (bool)($ctrl['versioningWS'] ?? false),
            ];
        }
        return $tables;
    }

    /**
     * @param list<array<string, mixed>> $rows with "pid" and "last_change"
     * @return array<int, int>
     */
    private function toLatestByPage(array $rows): array
    {
        $latest = [];
        foreach ($rows as $row) {
            $latest[(int)$row['pid']] = (int)$row['last_change'];
        }
        return $latest;
    }

    /**
     * @param array<int, int> $lastChanges
     * @param array<int, int> $changes
     */
    private function mergeLatest(array &$lastChanges, array $changes): void
    {
        foreach ($changes as $pageUid => $timestamp) {
            $lastChanges[$pageUid] = max($lastChanges[$pageUid] ?? 0, $timestamp);
        }
    }

    private function createQueryBuilder(string $table): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        return $queryBuilder;
    }
}
