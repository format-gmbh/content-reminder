<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Controller;

use Formatsoft\ContentReminder\Domain\Model\RecurrenceUnit;
use Formatsoft\ContentReminder\Domain\Model\Reminder;
use Formatsoft\ContentReminder\Domain\Repository\ReminderLogRepository;
use Formatsoft\ContentReminder\Domain\Repository\ReminderRepository;
use Formatsoft\ContentReminder\Mail\SitePageCollector;
use Formatsoft\ContentReminder\Service\Clock;
use Formatsoft\ContentReminder\Service\ReminderPermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * Backend module "Reminders" (concept 6.1, E34): overview of the reminders and the
 * completion archive of the selected page and its subpages, with filters.
 * Without selected page: admins see all pages, editors their web mounts.
 */
#[AsController]
final readonly class ReminderModuleController
{
    public const MODULE = 'content_reminder';

    /** Allowed values of the filters, the first one is the default */
    public const FILTERS = [
        'view' => ['reminders', 'archive'],
        'depth' => ['subpages', 'page'],
        'state' => ['open', 'due', 'overdue', 'upcoming', 'paused', 'done', 'all'],
        'period' => ['90', '30', '365', 'all'],
    ];

    private const LIMIT = 500;
    private const LL = 'LLL:EXT:content_reminder/Resources/Private/Language/locallang_be.xlf:';

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private ReminderRepository $reminderRepository,
        private ReminderLogRepository $logRepository,
        private SitePageCollector $pageCollector,
        private ReminderPermissionService $permissionService,
        private Clock $clock,
        private UriBuilder $uriBuilder,
        private ConnectionPool $connectionPool,
        private PageRenderer $pageRenderer,
    ) {}

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->getBackendUser();
        $languageService = $this->getLanguageService();
        $pageUid = (int)($request->getQueryParams()['id'] ?? 0);
        $page = $pageUid > 0 ? BackendUtility::getRecord('pages', $pageUid) : null;
        if ($pageUid > 0 && (!is_array($page) || !$this->permissionService->canView($user, $page))) {
            $pageUid = 0;
            $page = null;
        }
        $filters = $this->updateFilters($request, $user);

        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($languageService->sL(self::LL . 'module.title'));
        $this->pageRenderer->addCssFile('EXT:content_reminder/Resources/Public/Css/backend.css');
        $this->pageRenderer->loadJavaScriptModule('@formatsoft/content-reminder/reminder-actions.js');
        $this->pageRenderer->addInlineLanguageLabelFile('EXT:content_reminder/Resources/Private/Language/locallang_be.xlf', 'js.');

        $pages = $this->collectPages($pageUid, $filters['depth'], $user);
        $data = $filters['view'] === 'archive'
            ? $this->getArchive($pages, $filters, $user)
            : $this->getReminders($pages, $filters, $user, $request);

        $view->assignMultiple([
            ...$data,
            'pageUid' => $pageUid,
            'pageTitle' => is_array($page) ? (string)$page['title'] : '',
            'filters' => $filters,
            'filterOptions' => self::FILTERS,
            'users' => $this->getUsers(),
            'formAction' => $this->getModuleUrlParts($pageUid),
            'viewUrls' => [
                'reminders' => $this->getModuleUrl($pageUid, ['view' => 'reminders']),
                'archive' => $this->getModuleUrl($pageUid, ['view' => 'archive']),
            ],
            'newUrl' => is_array($page) && $this->permissionService->canCreate($user, $page)
                ? (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
                    'edit' => [Reminder::TABLE => [$pageUid => 'new']],
                    'returnUrl' => $this->getModuleUrl($pageUid),
                ])
                : '',
            'limit' => self::LIMIT,
        ]);
        return $view->renderResponse('Module/Index');
    }

    /**
     * Applies filter changes from the request, falls back to stored or default values.
     *
     * @return array{view: string, depth: string, state: string, period: string, assignee: string}
     */
    private function updateFilters(ServerRequestInterface $request, BackendUserAuthentication $user): array
    {
        /** @var ModuleData|null $moduleData */
        $moduleData = $request->getAttribute('moduleData');
        $stored = $moduleData?->toArray() ?? [];
        $params = $request->getQueryParams();

        $filters = [];
        foreach (self::FILTERS as $name => $allowed) {
            $value = (string)($params[$name] ?? $stored[$name] ?? $allowed[0]);
            $filters[$name] = in_array($value, $allowed, true) ? $value : $allowed[0];
        }
        // "all", "me", "unassigned" or a backend user uid
        $assignee = (string)($params['assignee'] ?? $stored['assignee'] ?? 'all');
        $filters['assignee'] = in_array($assignee, ['all', 'me', 'unassigned'], true) || ctype_digit($assignee) ? $assignee : 'all';

        $storedFilters = array_map(
            static fn(mixed $value): string => is_scalar($value) ? (string)$value : '',
            array_intersect_key($stored, $filters)
        );
        if ($moduleData !== null && array_diff_assoc($filters, $storedFilters) !== []) {
            foreach ($filters as $name => $value) {
                $moduleData->set($name, $value);
            }
            $user->pushModuleData($moduleData->getModuleIdentifier(), $moduleData->toArray());
        }
        return $filters;
    }

    /**
     * @return array<int, string> page uid => title
     */
    private function collectPages(int $pageUid, string $depth, BackendUserAuthentication $user): array
    {
        if ($pageUid > 0) {
            if ($depth === 'page') {
                $page = BackendUtility::getRecord('pages', $pageUid, 'uid,title');
                return is_array($page) ? [$pageUid => (string)$page['title']] : [];
            }
            return $this->pageCollector->collectSubtrees([$pageUid]);
        }
        // No page selected: whole tree for admins, web mounts for editors
        return $this->pageCollector->collectSubtrees($user->isAdmin() ? [0] : array_map(intval(...), $user->getWebmounts()));
    }

    /**
     * @param array<int, string> $pages
     * @param array<string, string> $filters
     * @return array<string, mixed>
     */
    private function getReminders(array $pages, array $filters, BackendUserAuthentication $user, ServerRequestInterface $request): array
    {
        $today = $this->clock->today();
        $assignee = match ($filters['assignee']) {
            'all' => null,
            'me' => (int)$user->getUserId(),
            'unassigned' => 0,
            default => (int)$filters['assignee'],
        };
        $state = in_array($filters['state'], self::FILTERS['state'], true) ? $filters['state'] : 'open';
        $reminders = $this->reminderRepository->findForPages(array_keys($pages), $state, $assignee, $today, self::LIMIT);
        $returnUrl = (string)($request->getAttribute('normalizedParams')?->getRequestUri() ?? '');
        $userNames = $this->getUserNames();
        $pageRecords = [];

        $items = [];
        foreach ($reminders as $reminder) {
            $page = $pageRecords[$reminder->pageUid] ??= BackendUtility::getRecord('pages', $reminder->pageUid) ?? [];
            if ($page === [] || !$this->permissionService->canView($user, $page)) {
                continue;
            }
            $items[] = [
                'reminder' => $reminder,
                'pageTitle' => $pages[$reminder->pageUid] ?? '',
                'layoutUrl' => (string)$this->uriBuilder->buildUriFromRoute('web_layout', ['id' => $reminder->pageUid]),
                'dueDate' => $reminder->dueDate?->format($GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'] ?? 'd.m.Y') ?? '',
                'overdue' => $reminder->isOverdue($today),
                'state' => $this->getStateKey($reminder, $today),
                'recurrence' => $this->getRecurrenceLabel($reminder),
                'assigneeName' => $userNames[$reminder->assignee] ?? '',
                'canComplete' => $this->permissionService->canComplete($user, $reminder, $page),
                'canTakeOver' => $this->permissionService->canTakeOver($user, $reminder, $page),
                'canResume' => $this->permissionService->canResume($user, $reminder, $page),
                'canReopen' => $this->permissionService->canReopen($user, $reminder, $page),
                'editUrl' => $this->permissionService->canEdit($user, $reminder, $page)
                    ? (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
                        'edit' => [Reminder::TABLE => [$reminder->uid => 'edit']],
                        'returnUrl' => $returnUrl,
                    ])
                    : '',
                'historyUrl' => (string)$this->uriBuilder->buildUriFromRoute('ajax_content_reminder_history', ['reminder' => $reminder->uid]),
            ];
            if (count($items) >= self::LIMIT) {
                break;
            }
        }
        return ['items' => $items, 'limitReached' => count($reminders) > self::LIMIT];
    }

    /**
     * @param array<int, string> $pages
     * @param array<string, string> $filters
     * @return array<string, mixed>
     */
    private function getArchive(array $pages, array $filters, BackendUserAuthentication $user): array
    {
        $since = $filters['period'] === 'all' ? null : $this->clock->today()->modify('-' . $filters['period'] . ' days');
        $completedBy = match ($filters['assignee']) {
            'all', 'unassigned' => null,
            'me' => (int)$user->getUserId(),
            default => (int)$filters['assignee'],
        };
        $entries = $this->logRepository->findForPages(array_keys($pages), $completedBy, $since, self::LIMIT);
        $pageRecords = [];
        $visible = [];
        foreach ($entries as $entry) {
            $pageUid = (int)$entry['page'];
            $page = $pageRecords[$pageUid] ??= BackendUtility::getRecord('pages', $pageUid) ?? [];
            if ($page === [] || !$this->permissionService->canView($user, $page)) {
                continue;
            }
            $entry['layoutUrl'] = (string)$this->uriBuilder->buildUriFromRoute('web_layout', ['id' => $pageUid]);
            $visible[] = $entry;
            if (count($visible) >= self::LIMIT) {
                break;
            }
        }
        $sysConf = $GLOBALS['TYPO3_CONF_VARS']['SYS'];
        return [
            'entries' => $visible,
            'limitReached' => count($entries) > self::LIMIT,
            'dateFormat' => $sysConf['ddmmyy'] ?? 'd.m.Y',
            'dateTimeFormat' => ($sysConf['ddmmyy'] ?? 'd.m.Y') . ' ' . ($sysConf['hhmm'] ?? 'H:i'),
        ];
    }

    private function getStateKey(Reminder $reminder, \DateTimeImmutable $today): string
    {
        return match (true) {
            $reminder->isDone() => 'done',
            $reminder->paused => 'paused',
            $reminder->isOverdue($today) => 'overdue',
            $reminder->isDue($today) => 'due',
            default => 'notDue',
        };
    }

    private function getRecurrenceLabel(Reminder $reminder): string
    {
        if ($reminder->recurrenceUnit === RecurrenceUnit::None) {
            return '';
        }
        $key = 'recurrence.' . $reminder->recurrenceUnit->value . ($reminder->recurrenceValue === 1 ? '.one' : '.other');
        return sprintf($this->getLanguageService()->sL(self::LL . $key), $reminder->recurrenceValue);
    }

    /**
     * Active backend users for the person filter (without _cli_), uid => name.
     *
     * @return array<int, string>
     */
    private function getUsers(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $rows = $queryBuilder
            ->select('uid', 'username', 'realName')
            ->from('be_users')
            ->where($queryBuilder->expr()->notLike('username', $queryBuilder->createNamedParameter('\_cli\_%')))
            ->executeQuery()
            ->fetchAllAssociative();
        $users = [];
        foreach ($rows as $row) {
            $users[(int)$row['uid']] = trim((string)$row['realName']) ?: (string)$row['username'];
        }
        asort($users, SORT_NATURAL | SORT_FLAG_CASE);
        return $users;
    }

    /**
     * Names of all backend users incl. disabled ones (assignees of older reminders).
     *
     * @return array<int, string>
     */
    private function getUserNames(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $queryBuilder->getRestrictions()->removeAll();
        $names = [];
        foreach ($queryBuilder->select('uid', 'username', 'realName')->from('be_users')->executeQuery()->fetchAllAssociative() as $row) {
            $names[(int)$row['uid']] = trim((string)$row['realName']) ?: (string)$row['username'];
        }
        return $names;
    }

    /**
     * @param array<string, string> $parameters
     */
    private function getModuleUrl(int $pageUid, array $parameters = []): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute(self::MODULE, ['id' => $pageUid, ...$parameters]);
    }

    /**
     * Path and query parameters of the module URL for the GET filter form
     * (browsers drop the query string of the form action).
     *
     * @return array{path: string, parameters: array<int|string, string>}
     */
    private function getModuleUrlParts(int $pageUid): array
    {
        $uri = $this->uriBuilder->buildUriFromRoute(self::MODULE, ['id' => $pageUid]);
        parse_str($uri->getQuery(), $parameters);
        return ['path' => $uri->getPath(), 'parameters' => array_map(strval(...), array_filter($parameters, is_scalar(...)))];
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
