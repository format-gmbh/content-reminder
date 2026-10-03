<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Dashboard;

use Formatsoft\ContentReminder\Domain\Model\RecurrenceUnit;
use Formatsoft\ContentReminder\Domain\Model\Reminder;
use Formatsoft\ContentReminder\Domain\Repository\ReminderLogRepository;
use Formatsoft\ContentReminder\Domain\Repository\ReminderRepository;
use Formatsoft\ContentReminder\Service\Clock;
use Formatsoft\ContentReminder\Service\ReminderPermissionService;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Data for the dashboard widgets (concept 2.4.4). Only reminders on pages the current
 * backend user may see are returned: page permissions and web mounts are checked per
 * page via ReminderPermissionService::canView().
 */
class ReminderOverviewProvider
{
    private const LL = 'LLL:EXT:content_reminder/Resources/Private/Language/locallang_be.xlf:';

    /** @var array<int, string> */
    private array $userNames = [];

    public function __construct(
        private readonly ReminderRepository $reminderRepository,
        private readonly ReminderLogRepository $logRepository,
        private readonly ReminderPermissionService $permissionService,
        private readonly Clock $clock,
        private readonly UriBuilder $uriBuilder,
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param 'open'|'due'|'overdue' $scope
     * @param 'me'|'unassigned'|'any' $assignee
     * @param int|null $limit null = all
     * @return list<array<string, mixed>>
     */
    public function getItems(string $scope, string $assignee, ?int $limit): array
    {
        $user = $this->getBackendUser();
        if (!$this->mayReadReminders($user)) {
            return [];
        }
        $today = $this->clock->today();
        $assigneeUid = match ($assignee) {
            'me' => (int)$user->getUserId(),
            'unassigned' => 0,
            default => null,
        };

        $items = [];
        // Fetch more than needed, because some pages may be outside the user's web mounts
        $rows = $this->reminderRepository->findForOverview($scope, $assigneeUid, $today, $user->getPagePermsClause(Permission::PAGE_SHOW), false, $limit !== null ? $limit * 3 : null);
        foreach ($rows as ['reminder' => $reminder, 'page' => $page]) {
            if (!$this->permissionService->canView($user, $page)) {
                continue;
            }
            $items[] = $this->buildItem($reminder, $page, $user, $today);
            if ($limit !== null && count($items) >= $limit) {
                break;
            }
        }
        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRecentlyCompleted(int $limit): array
    {
        $user = $this->getBackendUser();
        if (!$this->mayReadReminders($user)) {
            return [];
        }
        $entries = [];
        foreach ($this->logRepository->findRecent($user->getPagePermsClause(Permission::PAGE_SHOW), $limit * 3) as $row) {
            if (!$this->permissionService->canView($user, $this->extractPage($row, (int)$row['page']))) {
                continue;
            }
            $row['layoutUrl'] = $this->getLayoutUrl((int)$row['page']);
            $entries[] = $row;
            if (count($entries) >= $limit) {
                break;
            }
        }
        return $entries;
    }

    /**
     * Due (incl. overdue) reminders of the current user.
     */
    public function countDueForUser(): int
    {
        return count($this->getItems('due', 'me', null));
    }

    /**
     * Open reminders of all accessible pages by state.
     *
     * @return array{notDue: int, due: int, overdue: int, paused: int}
     */
    public function countByState(): array
    {
        $counts = ['notDue' => 0, 'due' => 0, 'overdue' => 0, 'paused' => 0];
        $user = $this->getBackendUser();
        if (!$this->mayReadReminders($user)) {
            return $counts;
        }
        $today = $this->clock->today();
        $rows = $this->reminderRepository->findForOverview('open', null, $today, $user->getPagePermsClause(Permission::PAGE_SHOW), true);
        foreach ($rows as ['reminder' => $reminder, 'page' => $page]) {
            if (!$this->permissionService->canView($user, $page)) {
                continue;
            }
            $state = match (true) {
                $reminder->paused => 'paused',
                $reminder->isOverdue($today) => 'overdue',
                $reminder->isDue($today) => 'due',
                default => 'notDue',
            };
            $counts[$state]++;
        }
        return $counts;
    }

    /**
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private function buildItem(Reminder $reminder, array $page, BackendUserAuthentication $user, \DateTimeImmutable $today): array
    {
        return [
            'reminder' => $reminder,
            'pageUid' => $reminder->pageUid,
            'pageTitle' => (string)$page['title'],
            'layoutUrl' => $this->getLayoutUrl($reminder->pageUid),
            'dueDate' => $reminder->dueDate?->format($GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'] ?? 'd.m.Y') ?? '',
            'due' => $reminder->isDue($today),
            'overdue' => $reminder->isOverdue($today),
            'recurrence' => $this->getRecurrenceLabel($reminder),
            'assigneeName' => $reminder->assignee > 0 ? $this->getUserName($reminder->assignee) : '',
            'canComplete' => $this->permissionService->canComplete($user, $reminder, $page),
            'canTakeOver' => $this->permissionService->canTakeOver($user, $reminder, $page),
        ];
    }

    /**
     * @param array<string, mixed> $row row with page_* fields
     * @return array<string, mixed>
     */
    private function extractPage(array $row, int $pageUid): array
    {
        return [
            'uid' => $pageUid,
            'pid' => (int)($row['page_pid'] ?? 0),
            'perms_userid' => (int)($row['page_perms_userid'] ?? 0),
            'perms_groupid' => (int)($row['page_perms_groupid'] ?? 0),
            'perms_user' => (int)($row['page_perms_user'] ?? 0),
            'perms_group' => (int)($row['page_perms_group'] ?? 0),
            'perms_everybody' => (int)($row['page_perms_everybody'] ?? 0),
        ];
    }

    private function getRecurrenceLabel(Reminder $reminder): string
    {
        if ($reminder->recurrenceUnit === RecurrenceUnit::None) {
            return '';
        }
        $key = 'recurrence.' . $reminder->recurrenceUnit->value . ($reminder->recurrenceValue === 1 ? '.one' : '.other');
        return sprintf($this->getLanguageService()->sL(self::LL . $key), $reminder->recurrenceValue);
    }

    private function getUserName(int $userUid): string
    {
        if (!isset($this->userNames[$userUid])) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
            $queryBuilder->getRestrictions()->removeAll();
            $row = $queryBuilder
                ->select('username', 'realName')
                ->from('be_users')
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($userUid, Connection::PARAM_INT)))
                ->executeQuery()
                ->fetchAssociative();
            $this->userNames[$userUid] = is_array($row)
                ? (trim((string)$row['realName']) ?: (string)$row['username'])
                : '#' . $userUid;
        }
        return $this->userNames[$userUid];
    }

    private function getLayoutUrl(int $pageUid): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute('web_layout', ['id' => $pageUid]);
    }

    private function mayReadReminders(BackendUserAuthentication $user): bool
    {
        return (int)$user->getUserId() > 0
            && ($user->isAdmin() || $user->check('tables_select', Reminder::TABLE));
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
