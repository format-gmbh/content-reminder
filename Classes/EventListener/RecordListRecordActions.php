<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\EventListener;

use Formatsoft\ContentReminder\Domain\Model\Reminder;
use Formatsoft\ContentReminder\Domain\Repository\ReminderRepository;
use Formatsoft\ContentReminder\Service\ReminderPermissionService;
use TYPO3\CMS\Backend\RecordList\Event\ModifyRecordListRecordActionsEvent;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ActionGroup;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Domain\RecordInterface;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Row actions for reminders in the list module (concept 2.4.3):
 * primary: complete, take over; secondary: resume, reopen, history.
 *
 * TYPO3 v14 works with button components, v13 with HTML strings; both are supported.
 */
#[AsEventListener(identifier: 'content-reminder/record-list-record-actions')]
final readonly class RecordListRecordActions
{
    private const LL = 'LLL:EXT:content_reminder/Resources/Private/Language/locallang_be.xlf:';
    private const PRIMARY_ACTIONS = ['contentReminderComplete', 'contentReminderTakeOver'];

    public function __construct(
        private ReminderRepository $reminderRepository,
        private ReminderPermissionService $permissionService,
        private IconFactory $iconFactory,
        private UriBuilder $uriBuilder,
    ) {}

    public function __invoke(ModifyRecordListRecordActionsEvent $event): void
    {
        // TYPO3 v14 passes a RecordInterface and works with button components,
        // v13 passes the record as array and works with HTML strings
        $record = $event->getRecord();
        $isV14 = $record instanceof RecordInterface;
        [$table, $uid] = $isV14
            ? [$record->getMainType(), $record->getUid()]
            : [$event->getTable(), (int)($record['uid'] ?? 0)];
        $user = $GLOBALS['BE_USER'] ?? null;
        if ($table !== Reminder::TABLE || !$user instanceof BackendUserAuthentication) {
            return;
        }
        $reminder = $this->reminderRepository->findByUid($uid);
        $page = $reminder !== null ? BackendUtility::getRecord('pages', $reminder->pageUid) : null;
        if ($reminder === null || !is_array($page)) {
            return;
        }

        $actions = $this->collectActions($reminder, $user, $page);
        if (!$isV14) {
            foreach (self::PRIMARY_ACTIONS as $name) {
                $html = isset($actions[$name]) ? $this->renderHtml($actions[$name]) : $this->renderPlaceholderHtml();
                $event->setAction($html, $name, 'primary');
                unset($actions[$name]);
            }
            foreach ($actions as $name => $action) {
                $event->setAction($this->renderHtml($action), $name, 'secondary');
            }
            return;
        }
        // Primary actions always occupy their slot in a fixed order (empty placeholder
        // if not allowed), so that the buttons of all rows stay aligned
        foreach (self::PRIMARY_ACTIONS as $name) {
            if (isset($actions[$name])) {
                $this->addComponent($event, $name, $actions[$name]);
                unset($actions[$name]);
            } else {
                $event->setAction(null, $name, ActionGroup::primary);
            }
        }
        foreach ($actions as $name => $action) {
            $this->addComponent($event, $name, $action);
        }
    }

    /**
     * @param array<string, mixed> $page
     * @return array<string, array{icon: string, label: string, primary: bool, attributes: array<string, string>}>
     */
    private function collectActions(Reminder $reminder, BackendUserAuthentication $user, array $page): array
    {
        $languageService = $this->getLanguageService();
        $base = ['data-uid' => (string)$reminder->uid, 'data-title' => $reminder->title];
        $actions = [];

        if ($this->permissionService->canComplete($user, $reminder, $page)) {
            $actions['contentReminderComplete'] = [
                'icon' => 'actions-check',
                'label' => $languageService->sL(self::LL . 'panel.action.complete'),
                'primary' => true,
                'attributes' => $base + [
                    'data-content-reminder-action' => 'complete',
                    'data-recurring' => $reminder->isRecurring() ? '1' : '0',
                ],
            ];
        }
        if ($this->permissionService->canTakeOver($user, $reminder, $page)) {
            $actions['contentReminderTakeOver'] = [
                'icon' => 'actions-user',
                'label' => $languageService->sL(self::LL . 'panel.action.takeOver'),
                'primary' => true,
                'attributes' => $base + ['data-content-reminder-action' => 'takeOver'],
            ];
        }
        if ($this->permissionService->canResume($user, $reminder, $page)) {
            $actions['contentReminderResume'] = [
                'icon' => 'actions-play',
                'label' => $languageService->sL(self::LL . 'list.action.resume'),
                'primary' => false,
                'attributes' => $base + ['data-content-reminder-action' => 'resume'],
            ];
        }
        if ($this->permissionService->canReopen($user, $reminder, $page)) {
            $actions['contentReminderReopen'] = [
                'icon' => 'actions-refresh',
                'label' => $languageService->sL(self::LL . 'list.action.reopen'),
                'primary' => false,
                'attributes' => $base + ['data-content-reminder-action' => 'reopen'],
            ];
        }
        $actions['contentReminderHistory'] = [
            'icon' => 'actions-history',
            'label' => $languageService->sL(self::LL . 'list.action.history'),
            'primary' => false,
            'attributes' => [
                'data-content-reminder-history' => (string)$this->uriBuilder->buildUriFromRoute('ajax_content_reminder_history', ['reminder' => $reminder->uid]),
            ],
        ];
        return $actions;
    }

    /**
     * @param array{icon: string, label: string, primary: bool, attributes: array<string, string>} $action
     */
    private function addComponent(ModifyRecordListRecordActionsEvent $event, string $name, array $action): void
    {
        $button = GeneralUtility::makeInstance(ComponentFactory::class)->createGenericButton()
            ->setTag('button')
            ->setIcon($this->iconFactory->getIcon($action['icon'], IconSize::SMALL))
            ->setLabel($action['label'])
            ->setTitle($action['label'])
            ->setAttributes(['type' => 'button', ...$action['attributes']]);
        $event->setAction($button, $name, $action['primary'] ? ActionGroup::primary : ActionGroup::secondary);
    }

    /**
     * @param array{icon: string, label: string, primary: bool, attributes: array<string, string>} $action
     */
    private function renderHtml(array $action): string
    {
        return sprintf(
            '<button type="button" class="btn btn-default" title="%s" %s>%s</button>',
            htmlspecialchars($action['label']),
            GeneralUtility::implodeAttributes($action['attributes'], true),
            $this->iconFactory->getIcon($action['icon'], IconSize::SMALL)->render()
        );
    }

    /**
     * Empty slot like the core uses for actions that are not available (TYPO3 v13).
     */
    private function renderPlaceholderHtml(): string
    {
        return '<span class="btn btn-default disabled" aria-hidden="true">'
            . $this->iconFactory->getIcon('empty-empty', IconSize::SMALL)->render()
            . '</span>';
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
