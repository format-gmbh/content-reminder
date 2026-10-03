<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\EventListener;

use Formatsoft\ContentReminder\Domain\Model\PageDueSummary;
use Formatsoft\ContentReminder\Domain\Model\Reminder;
use Formatsoft\ContentReminder\Domain\Repository\ReminderRepository;
use Formatsoft\ContentReminder\Service\Clock;
use Formatsoft\ContentReminder\Service\ReminderPermissionService;
use TYPO3\CMS\Backend\Controller\Event\AfterPageTreeItemsPreparedEvent;
use TYPO3\CMS\Backend\Dto\Tree\Status\StatusInformation;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * Marks pages in the page tree (concept 2.4.1):
 * - pencil icon for pages with reminders due for the current user (warning if overdue)
 * - person icon for pages with due unassigned reminders, if the user may take them over
 * One database query for all items of a tree request.
 */
#[AsEventListener(identifier: 'content-reminder/page-tree-marker')]
final readonly class PageTreeMarker
{
    public const ICON_DUE_FOR_USER = 'actions-open';
    public const ICON_DUE_UNASSIGNED = 'actions-user';

    private const LL = 'LLL:EXT:content_reminder/Resources/Private/Language/locallang_be.xlf:';

    public function __construct(
        private ReminderRepository $reminderRepository,
        private ReminderPermissionService $permissionService,
        private Clock $clock,
        private LanguageServiceFactory $languageServiceFactory,
    ) {}

    public function __invoke(AfterPageTreeItemsPreparedEvent $event): void
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication || (int)$user->getUserId() <= 0) {
            return;
        }
        // Without read access to the table there is nothing to show
        if (!$user->isAdmin() && !$user->check('tables_select', Reminder::TABLE)) {
            return;
        }

        $items = $event->getItems();
        $pageUids = [];
        foreach ($items as $item) {
            $pageUid = $this->getPageUid($item);
            if ($pageUid > 0) {
                $pageUids[] = $pageUid;
            }
        }
        $summaries = $this->reminderRepository->summarizeDueOnPages((int)$user->getUserId(), $this->clock->today(), $pageUids);
        if ($summaries === []) {
            return;
        }

        $languageService = $this->getLanguageService($user);
        foreach ($items as $key => $item) {
            $summary = $summaries[$this->getPageUid($item)] ?? null;
            if ($summary === null) {
                continue;
            }
            $showUnassigned = $summary->hasDueUnassigned()
                && is_array($item['_page'] ?? null)
                && $this->permissionService->canCreate($user, $item['_page']);
            $statusInformation = $this->createStatusInformation($summary, $showUnassigned, $languageService);
            if ($statusInformation !== null) {
                $items[$key]['statusInformation'][] = $statusInformation;
            }
        }
        $event->setItems($items);
    }

    private function createStatusInformation(PageDueSummary $summary, bool $showUnassigned, LanguageService $languageService): ?StatusInformation
    {
        $labels = [];
        if ($summary->hasDueForUser()) {
            $labels[] = sprintf($languageService->sL(self::LL . 'pageTree.dueForUser'), $summary->dueForUser);
        }
        if ($showUnassigned) {
            $labels[] = sprintf($languageService->sL(self::LL . 'pageTree.dueUnassigned'), $summary->dueUnassigned);
        }
        if ($labels === []) {
            return null;
        }

        return new StatusInformation(
            label: implode(', ', $labels),
            severity: $summary->hasOverdueForUser() ? ContextualFeedbackSeverity::WARNING : ContextualFeedbackSeverity::INFO,
            // The pencil (own reminders) takes precedence over the person icon
            icon: $summary->hasDueForUser() ? self::ICON_DUE_FOR_USER : self::ICON_DUE_UNASSIGNED,
        );
    }

    /**
     * Uid of the live page in default language; reminders are attached there (E2).
     *
     * @param array<string, mixed> $item
     */
    private function getPageUid(array $item): int
    {
        $page = $item['_page'] ?? [];
        if (!is_array($page)) {
            return 0;
        }
        if ((int)($page['t3ver_oid'] ?? 0) > 0) {
            return (int)$page['t3ver_oid'];
        }
        return (int)($page['uid'] ?? 0);
    }

    private function getLanguageService(BackendUserAuthentication $user): LanguageService
    {
        return $GLOBALS['LANG'] ?? $this->languageServiceFactory->createFromUserPreferences($user);
    }
}
