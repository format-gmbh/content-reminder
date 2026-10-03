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
use Formatsoft\ContentReminder\Domain\Model\PageDueSummary;
use Formatsoft\ContentReminder\Domain\Model\Reminder;
use Formatsoft\ContentReminder\Domain\Model\ReminderStatus;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\CompositeExpression;
use TYPO3\CMS\Core\Database\Query\QueryHelper;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;

/**
 * Read access to reminders. Write access goes through the DataHandler.
 *
 * "Due" means: open, not paused, and without due date or due today or earlier.
 * The default query restrictions exclude deleted and paused (hidden) reminders.
 */
final readonly class ReminderRepository
{
    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function findByUid(int $uid): ?Reminder
    {
        $queryBuilder = $this->createQueryBuilder(includePaused: true);
        $row = $queryBuilder
            ->select('*')
            ->from(Reminder::TABLE)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : Reminder::fromDatabaseRow($row);
    }

    /**
     * Reminders that are due for the given user, optionally restricted to one page.
     *
     * @return list<Reminder>
     */
    public function findDueForUser(int $userUid, \DateTimeImmutable $today, ?int $pageUid = null): array
    {
        if ($userUid <= 0) {
            return [];
        }
        return $this->findDueByAssignee($userUid, $today, $pageUid);
    }

    /**
     * Unassigned reminders that are due, optionally restricted to one page.
     *
     * @return list<Reminder>
     */
    public function findDueUnassigned(\DateTimeImmutable $today, ?int $pageUid = null): array
    {
        return $this->findDueByAssignee(0, $today, $pageUid);
    }

    /**
     * All reminders of a page that are not done, including paused ones.
     *
     * @return list<Reminder>
     */
    public function findOpenOnPage(int $pageUid): array
    {
        $queryBuilder = $this->createQueryBuilder(includePaused: true);
        $rows = $queryBuilder
            ->select('*')
            ->from(Reminder::TABLE)
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter(ReminderStatus::Open->value, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        return $this->sortByDueDate(array_map(Reminder::fromDatabaseRow(...), $rows));
    }

    /**
     * Open reminders across all pages for the dashboard, together with the page they belong to.
     *
     * $pagePermsClause (BackendUserAuthentication::getPagePermsClause()) only pre-filters by page
     * permissions; web mounts and table permissions must still be checked by the caller.
     *
     * @param 'open'|'due'|'overdue' $scope
     * @param int|null $assignee null = any, 0 = unassigned
     * @param int|null $limit null = no limit
     * @return list<array{reminder: Reminder, page: array<string, mixed>}>
     */
    public function findForOverview(
        string $scope,
        ?int $assignee,
        \DateTimeImmutable $today,
        string $pagePermsClause,
        bool $includePaused = false,
        ?int $limit = null,
    ): array {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(Reminder::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $todayParameter = $queryBuilder->createNamedParameter($today->format('Y-m-d'));

        $queryBuilder
            ->select('r.*')
            ->addSelect(
                'pages.pid AS page_pid',
                'pages.title AS page_title',
                'pages.perms_userid AS page_perms_userid',
                'pages.perms_groupid AS page_perms_groupid',
                'pages.perms_user AS page_perms_user',
                'pages.perms_group AS page_perms_group',
                'pages.perms_everybody AS page_perms_everybody',
            )
            ->from(Reminder::TABLE, 'r')
            ->join('r', 'pages', 'pages', $queryBuilder->expr()->eq('pages.uid', $queryBuilder->quoteIdentifier('r.pid')))
            ->where(
                $queryBuilder->expr()->eq('r.deleted', 0),
                $queryBuilder->expr()->eq('pages.deleted', 0),
                $queryBuilder->expr()->eq('r.status', $queryBuilder->createNamedParameter(ReminderStatus::Open->value, Connection::PARAM_INT)),
            )
            ->orderBy('r.due_date', 'ASC')
            ->addOrderBy('r.uid', 'ASC');

        $pagePermsClause = QueryHelper::stripLogicalOperatorPrefix($pagePermsClause);
        if ($pagePermsClause !== '') {
            $queryBuilder->andWhere($pagePermsClause);
        }
        if (!$includePaused) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq('r.hidden', 0));
        }
        if ($assignee !== null) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq('r.assignee', $queryBuilder->createNamedParameter($assignee, Connection::PARAM_INT)));
        }
        if ($scope === 'due') {
            $queryBuilder->andWhere($queryBuilder->expr()->or(
                $queryBuilder->expr()->isNull('r.due_date'),
                $queryBuilder->expr()->lte('r.due_date', $todayParameter),
            ));
        } elseif ($scope === 'overdue') {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->isNotNull('r.due_date'),
                $queryBuilder->expr()->lt('r.due_date', $todayParameter),
            );
        }
        if ($limit !== null) {
            $queryBuilder->setMaxResults($limit);
        }

        $result = [];
        foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $row) {
            $result[] = [
                'reminder' => Reminder::fromDatabaseRow($row),
                'page' => [
                    'uid' => (int)$row['pid'],
                    'pid' => (int)$row['page_pid'],
                    'title' => (string)$row['page_title'],
                    'perms_userid' => (int)$row['page_perms_userid'],
                    'perms_groupid' => (int)$row['page_perms_groupid'],
                    'perms_user' => (int)$row['page_perms_user'],
                    'perms_group' => (int)$row['page_perms_group'],
                    'perms_everybody' => (int)$row['page_perms_everybody'],
                ],
            ];
        }
        // Reminders without due date first, independent of the DBMS' NULL ordering
        usort(
            $result,
            static fn(array $a, array $b): int => [$a['reminder']->dueDate !== null, $a['reminder']->dueDate, $a['reminder']->title, $a['reminder']->uid]
                <=> [$b['reminder']->dueDate !== null, $b['reminder']->dueDate, $b['reminder']->title, $b['reminder']->uid]
        );
        return $result;
    }

    /**
     * Open, not paused reminders on the given pages that are due until the given day
     * (inclusive) or have no due date. Used for the weekly mail.
     *
     * @param list<int> $pageUids
     * @return list<Reminder>
     */
    public function findDueOnPagesUntil(array $pageUids, \DateTimeImmutable $until): array
    {
        $pageUids = array_values(array_unique(array_filter(array_map(intval(...), $pageUids), static fn(int $uid): bool => $uid > 0)));
        $reminders = [];
        foreach (array_chunk($pageUids, 1000) as $chunk) {
            $queryBuilder = $this->createQueryBuilder();
            $rows = $queryBuilder
                ->select('*')
                ->from(Reminder::TABLE)
                ->where(
                    $queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($chunk, ArrayParameterType::INTEGER)),
                    $queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter(ReminderStatus::Open->value, Connection::PARAM_INT)),
                    $this->dueConstraint($queryBuilder, $queryBuilder->createNamedParameter($until->format('Y-m-d'))),
                )
                ->executeQuery()
                ->fetchAllAssociative();
            foreach ($rows as $row) {
                $reminders[] = Reminder::fromDatabaseRow($row);
            }
        }
        return $this->sortByDueDate($reminders);
    }

    /**
     * All reminders of a page, including done and paused ones.
     */
    public function countOnPage(int $pageUid): int
    {
        if ($pageUid <= 0) {
            return 0;
        }
        $queryBuilder = $this->createQueryBuilder(includePaused: true);
        return (int)$queryBuilder
            ->count('uid')
            ->from(Reminder::TABLE)
            ->where($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Number of due reminders per page for the page tree marker: due for the
     * given user, overdue for the given user, and due without assignee.
     * One query for all given pages; pages without due reminders are omitted.
     *
     * @param list<int> $pageUids
     * @return array<int, PageDueSummary> indexed by page uid
     */
    public function summarizeDueOnPages(int $userUid, \DateTimeImmutable $today, array $pageUids): array
    {
        $pageUids = array_values(array_unique(array_filter(array_map(intval(...), $pageUids), static fn(int $uid): bool => $uid > 0)));
        if ($userUid <= 0 || $pageUids === []) {
            return [];
        }

        $summaries = [];
        // Keep the IN() list at a size every DBMS can handle
        foreach (array_chunk($pageUids, 1000) as $chunk) {
            $queryBuilder = $this->createQueryBuilder();
            $user = $queryBuilder->createNamedParameter($userUid, Connection::PARAM_INT);
            $todayParameter = $queryBuilder->createNamedParameter($today->format('Y-m-d'));
            $assignee = $queryBuilder->quoteIdentifier('assignee');
            $dueDate = $queryBuilder->quoteIdentifier('due_date');

            $rows = $queryBuilder
                ->select('pid')
                ->addSelectLiteral(
                    sprintf('SUM(CASE WHEN %s = %s THEN 1 ELSE 0 END) AS %s', $assignee, $user, $queryBuilder->quoteIdentifier('due_for_user')),
                    sprintf(
                        'SUM(CASE WHEN %s = %s AND %s IS NOT NULL AND %s < %s THEN 1 ELSE 0 END) AS %s',
                        $assignee,
                        $user,
                        $dueDate,
                        $dueDate,
                        $todayParameter,
                        $queryBuilder->quoteIdentifier('overdue_for_user')
                    ),
                    sprintf('SUM(CASE WHEN %s = 0 THEN 1 ELSE 0 END) AS %s', $assignee, $queryBuilder->quoteIdentifier('due_unassigned')),
                )
                ->from(Reminder::TABLE)
                ->where(
                    $queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($chunk, ArrayParameterType::INTEGER)),
                    $queryBuilder->expr()->in('assignee', $queryBuilder->createNamedParameter([0, $userUid], ArrayParameterType::INTEGER)),
                    $queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter(ReminderStatus::Open->value, Connection::PARAM_INT)),
                    $this->dueConstraint($queryBuilder, $todayParameter),
                )
                ->groupBy('pid')
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($rows as $row) {
                $pageUid = (int)$row['pid'];
                $summaries[$pageUid] = new PageDueSummary(
                    pageUid: $pageUid,
                    dueForUser: (int)$row['due_for_user'],
                    overdueForUser: (int)$row['overdue_for_user'],
                    dueUnassigned: (int)$row['due_unassigned'],
                );
            }
        }

        return $summaries;
    }

    /**
     * @return list<Reminder>
     */
    private function findDueByAssignee(int $assignee, \DateTimeImmutable $today, ?int $pageUid): array
    {
        $queryBuilder = $this->createQueryBuilder();
        $constraints = [
            $queryBuilder->expr()->eq('assignee', $queryBuilder->createNamedParameter($assignee, Connection::PARAM_INT)),
            $queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter(ReminderStatus::Open->value, Connection::PARAM_INT)),
            $this->dueConstraint($queryBuilder, $queryBuilder->createNamedParameter($today->format('Y-m-d'))),
        ];
        if ($pageUid !== null) {
            $constraints[] = $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT));
        }

        $rows = $queryBuilder
            ->select('*')
            ->from(Reminder::TABLE)
            ->where(...$constraints)
            ->executeQuery()
            ->fetchAllAssociative();

        return $this->sortByDueDate(array_map(Reminder::fromDatabaseRow(...), $rows));
    }

    /**
     * due_date IS NULL OR due_date <= today
     */
    private function dueConstraint(QueryBuilder $queryBuilder, string $todayParameter): CompositeExpression
    {
        return $queryBuilder->expr()->or(
            $queryBuilder->expr()->isNull('due_date'),
            $queryBuilder->expr()->lte('due_date', $todayParameter),
        );
    }

    /**
     * Reminders without due date first (they are due immediately), then by due date and title.
     * Sorted in PHP because the position of NULL values in ORDER BY differs between DBMS.
     *
     * @param list<Reminder> $reminders
     * @return list<Reminder>
     */
    private function sortByDueDate(array $reminders): array
    {
        usort(
            $reminders,
            static fn(Reminder $a, Reminder $b): int => [$a->dueDate !== null, $a->dueDate, $a->title, $a->uid]
                <=> [$b->dueDate !== null, $b->dueDate, $b->title, $b->uid]
        );
        return $reminders;
    }

    private function createQueryBuilder(bool $includePaused = false): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(Reminder::TABLE);
        if ($includePaused) {
            $queryBuilder->getRestrictions()->removeByType(HiddenRestriction::class);
        }
        return $queryBuilder;
    }
}
