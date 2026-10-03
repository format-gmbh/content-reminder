<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\EventListener;

use Formatsoft\ContentReminder\Domain\Model\RecurrenceUnit;
use Formatsoft\ContentReminder\Domain\Model\Reminder;
use Formatsoft\ContentReminder\Domain\Repository\ReminderLogRepository;
use Formatsoft\ContentReminder\Domain\Repository\ReminderRepository;
use Formatsoft\ContentReminder\Service\Clock;
use Formatsoft\ContentReminder\Service\ReminderPermissionService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * Panel above the content elements in the page module (concept 2.4.2).
 *
 * Shows the reminders due for the current user and due unassigned reminders, plus a
 * footer with a link to all other reminders of the page and to the history.
 * Variants: full panel, hint line only, history link only, nothing.
 */
#[AsEventListener(identifier: 'content-reminder/page-layout-panel')]
final readonly class PageLayoutPanel
{
    private const LL = 'LLL:EXT:content_reminder/Resources/Private/Language/locallang_be.xlf:';

    public function __construct(
        private ReminderRepository $reminderRepository,
        private ReminderLogRepository $logRepository,
        private ReminderPermissionService $permissionService,
        private Clock $clock,
        private ViewFactoryInterface $viewFactory,
        private UriBuilder $uriBuilder,
        private ModuleProvider $moduleProvider,
        private PageRenderer $pageRenderer,
        private LanguageServiceFactory $languageServiceFactory,
    ) {}

    public function __invoke(ModifyPageLayoutContentEvent $event): void
    {
        $request = $event->getRequest();
        $user = $GLOBALS['BE_USER'] ?? null;
        $page = $this->getPage((int)($request->getQueryParams()['id'] ?? 0));
        if (!$user instanceof BackendUserAuthentication || $page === null || !$this->permissionService->canView($user, $page)) {
            return;
        }
        $content = $this->render($request, $user, $page);
        if ($content !== '') {
            $event->addHeaderContent($content);
        }
    }

    /**
     * @param array<string, mixed> $page
     */
    public function render(ServerRequestInterface $request, BackendUserAuthentication $user, array $page): string
    {
        $pageUid = (int)$page['uid'];
        $today = $this->clock->today();

        $dueForUser = $this->reminderRepository->findDueForUser((int)$user->getUserId(), $today, $pageUid);
        $dueUnassigned = $this->permissionService->canCreate($user, $page)
            ? $this->reminderRepository->findDueUnassigned($today, $pageUid)
            : [];
        $shownUids = array_map(static fn(Reminder $reminder): int => $reminder->uid, [...$dueForUser, ...$dueUnassigned]);
        $otherCount = count(array_filter(
            $this->reminderRepository->findOpenOnPage($pageUid),
            static fn(Reminder $reminder): bool => !in_array($reminder->uid, $shownUids, true)
        ));
        $historyCount = $this->logRepository->countByPage($pageUid);

        if ($shownUids === [] && $otherCount === 0 && $historyCount === 0) {
            return '';
        }

        $languageService = $this->getLanguageService($user);
        $returnUrl = (string)($request->getAttribute('normalizedParams')?->getRequestUri() ?? '');

        $this->pageRenderer->loadJavaScriptModule('@formatsoft/content-reminder/reminder-actions.js');
        $this->pageRenderer->addCssFile('EXT:content_reminder/Resources/Public/Css/backend.css');
        $this->pageRenderer->addInlineLanguageLabelFile('EXT:content_reminder/Resources/Private/Language/locallang_be.xlf', 'js.');

        $view = $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: ['EXT:content_reminder/Resources/Private/Templates'],
            partialRootPaths: ['EXT:content_reminder/Resources/Private/Partials'],
            request: $request,
        ));
        $view->assignMultiple([
            'pageUid' => $pageUid,
            'dueForUser' => $this->buildItems($dueForUser, $user, $page, $today, $returnUrl, $languageService),
            'dueUnassigned' => $this->buildItems($dueUnassigned, $user, $page, $today, $returnUrl, $languageService),
            'otherCount' => $otherCount,
            'historyCount' => $historyCount,
            'listUrl' => (string)$this->uriBuilder->buildUriFromRoute($this->getRecordsModuleIdentifier(), ['id' => $pageUid, 'table' => Reminder::TABLE]),
            'historyUrl' => (string)$this->uriBuilder->buildUriFromRoute('ajax_content_reminder_history', ['page' => $pageUid]),
            'newUrl' => $this->permissionService->canCreate($user, $page)
                ? (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
                    'edit' => [Reminder::TABLE => [$pageUid => 'new']],
                    'returnUrl' => $returnUrl,
                ])
                : '',
        ]);
        return $view->render('PageLayout/Panel');
    }

    /**
     * @param list<Reminder> $reminders
     * @param array<string, mixed> $page
     * @return list<array<string, mixed>>
     */
    private function buildItems(array $reminders, BackendUserAuthentication $user, array $page, \DateTimeImmutable $today, string $returnUrl, LanguageService $languageService): array
    {
        $items = [];
        foreach ($reminders as $reminder) {
            $canEdit = $this->permissionService->canEdit($user, $reminder, $page);
            $items[] = [
                'reminder' => $reminder,
                'dueDate' => $reminder->dueDate?->format($GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'] ?? 'd.m.Y') ?? '',
                'overdue' => $reminder->isOverdue($today),
                'recurrence' => $this->getRecurrenceLabel($reminder, $languageService),
                'canComplete' => $this->permissionService->canComplete($user, $reminder, $page),
                'canTakeOver' => $this->permissionService->canTakeOver($user, $reminder, $page),
                'canPause' => $this->permissionService->canPause($user, $reminder, $page),
                'editUrl' => $canEdit
                    ? (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
                        'edit' => [Reminder::TABLE => [$reminder->uid => 'edit']],
                        'returnUrl' => $returnUrl,
                    ])
                    : '',
            ];
        }
        return $items;
    }

    private function getRecurrenceLabel(Reminder $reminder, LanguageService $languageService): string
    {
        if ($reminder->recurrenceUnit === RecurrenceUnit::None) {
            return '';
        }
        $key = 'recurrence.' . $reminder->recurrenceUnit->value . ($reminder->recurrenceValue === 1 ? '.one' : '.other');
        return sprintf($languageService->sL(self::LL . $key), $reminder->recurrenceValue);
    }

    /**
     * The list module is called "records" in TYPO3 v14 and "web_list" in v13.
     */
    private function getRecordsModuleIdentifier(): string
    {
        return $this->moduleProvider->isModuleRegistered('records') ? 'records' : 'web_list';
    }

    /**
     * @return array<string, mixed>|null page in default language
     */
    private function getPage(int $pageUid): ?array
    {
        if ($pageUid <= 0) {
            return null;
        }
        $page = BackendUtility::getRecord('pages', $pageUid);
        if (is_array($page) && (int)($page['l10n_parent'] ?? 0) > 0) {
            $page = BackendUtility::getRecord('pages', (int)$page['l10n_parent']);
        }
        return is_array($page) ? $page : null;
    }

    private function getLanguageService(BackendUserAuthentication $user): LanguageService
    {
        return $GLOBALS['LANG'] ?? $this->languageServiceFactory->createFromUserPreferences($user);
    }
}
