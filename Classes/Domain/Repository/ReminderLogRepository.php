<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Domain\Repository;

use Doctrine\DBAL\ArrayParameterType;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryHelper;

/**
 * Archive of completed reminders (tx_contentreminder_reminder_log).
 *
 * The table is read-only for the DataHandler, entries are written here directly.
 * They live on pid 0 and keep copies of titles and names (concept 3.2).
 */
final readonly class ReminderLogRepository
{
    public const TABLE = 'tx_contentreminder_reminder_log';

    /**
     * Default retention for the scheduler task "Table garbage collection" (also used with
     * "all tables"). Generous, since the log is the archive of completions.
     */
    public const GARBAGE_COLLECTION_DAYS = 730;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @return int uid of the new entry
     */
    public function add(
        int $reminderUid,
        string $reminderTitle,
        int $pageUid,
        string $pageTitle,
        int $completedBy,
        string $completedByName,
        \DateTimeImmutable $completedAt,
        ?\DateTimeImmutable $dueDate,
        ?\DateTimeImmutable $nextDueDate,
        string $comment,
    ): int {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'pid' => 0,
            'crdate' => $completedAt->getTimestamp(),
            'reminder' => $reminderUid,
            'reminder_title' => mb_substr($reminderTitle, 0, 255),
            'page' => $pageUid,
            'page_title' => mb_substr($pageTitle, 0, 255),
            'completed_by' => $completedBy,
            'completed_by_name' => mb_substr($completedByName, 0, 255),
            'completed_at' => $completedAt->getTimestamp(),
            'due_date' => $dueDate?->format('Y-m-d'),
            'next_due_date' => $nextDueDate?->format('Y-m-d'),
            'comment' => $comment,
        ]);
        return (int)$connection->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>> newest first
     */
    public function findByPage(int $pageUid, int $limit = 100): array
    {
        return $this->findBy('page', $pageUid, $limit);
    }

    /**
     * @return list<array<string, mixed>> newest first
     */
    public function findByReminder(int $reminderUid, int $limit = 100): array
    {
        return $this->findBy('reminder', $reminderUid, $limit);
    }

    /**
     * Latest archive entries on pages matching the page permission clause, with the page's
     * permission fields (page_*) so that the caller can check access per page.
     *
     * @return list<array<string, mixed>> newest first
     */
    public function findRecent(string $pagePermsClause, int $limit): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder
            ->select('log.*')
            ->addSelect(
                'pages.pid AS page_pid',
                'pages.perms_userid AS page_perms_userid',
                'pages.perms_groupid AS page_perms_groupid',
                'pages.perms_user AS page_perms_user',
                'pages.perms_group AS page_perms_group',
                'pages.perms_everybody AS page_perms_everybody',
            )
            ->from(self::TABLE, 'log')
            ->join('log', 'pages', 'pages', $queryBuilder->expr()->eq('pages.uid', $queryBuilder->quoteIdentifier('log.page')))
            ->where($queryBuilder->expr()->eq('pages.deleted', 0))
            ->orderBy('log.completed_at', 'DESC')
            ->addOrderBy('log.uid', 'DESC')
            ->setMaxResults($limit);
        $pagePermsClause = QueryHelper::stripLogicalOperatorPrefix($pagePermsClause);
        if ($pagePermsClause !== '') {
            $queryBuilder->andWhere($pagePermsClause);
        }
        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * Archive entries of the given pages for the backend module, newest first.
     *
     * @param list<int> $pageUids
     * @return list<array<string, mixed>>
     */
    public function findForPages(array $pageUids, ?int $completedBy, ?\DateTimeImmutable $since, int $limit): array
    {
        $pageUids = array_values(array_unique(array_filter(array_map(intval(...), $pageUids), static fn(int $uid): bool => $uid > 0)));
        $entries = [];
        foreach (array_chunk($pageUids, 1000) as $chunk) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $constraints = [$queryBuilder->expr()->in('page', $queryBuilder->createNamedParameter($chunk, ArrayParameterType::INTEGER))];
            if ($completedBy !== null) {
                $constraints[] = $queryBuilder->expr()->eq('completed_by', $queryBuilder->createNamedParameter($completedBy, Connection::PARAM_INT));
            }
            if ($since !== null) {
                $constraints[] = $queryBuilder->expr()->gte('completed_at', $queryBuilder->createNamedParameter($since->getTimestamp(), Connection::PARAM_INT));
            }
            $entries = [...$entries, ...$queryBuilder
                ->select('*')
                ->from(self::TABLE)
                ->where(...$constraints)
                ->orderBy('completed_at', 'DESC')
                ->addOrderBy('uid', 'DESC')
                ->setMaxResults($limit + 1)
                ->executeQuery()
                ->fetchAllAssociative()];
        }
        usort($entries, static fn(array $a, array $b): int => [(int)$b['completed_at'], (int)$b['uid']] <=> [(int)$a['completed_at'], (int)$a['uid']]);
        return $entries;
    }

    public function countByPage(int $pageUid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return (int)$queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('page', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findBy(string $field, int $value, int $limit): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter($value, Connection::PARAM_INT)))
            ->orderBy('completed_at', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
