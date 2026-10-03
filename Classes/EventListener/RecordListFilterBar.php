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
use Formatsoft\ContentReminder\Service\RecordListFilter;
use TYPO3\CMS\Backend\Controller\Event\RenderAdditionalContentToRecordListEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * Filter bar "only mine" / "only due" above the list module (concept 2.4.3),
 * shown on pages with reminders. Also loads the JavaScript for the row actions.
 *
 * The event is dispatched before the list is rendered, so the toggled filter state
 * is stored before RecordListFilterQuery applies it.
 */
#[AsEventListener(identifier: 'content-reminder/record-list-filter-bar')]
final readonly class RecordListFilterBar
{
    private const LL = 'LLL:EXT:content_reminder/Resources/Private/Language/locallang_be.xlf:';

    public function __construct(
        private RecordListFilter $filter,
        private ReminderRepository $reminderRepository,
        private IconFactory $iconFactory,
        private PageRenderer $pageRenderer,
    ) {}

    public function __invoke(RenderAdditionalContentToRecordListEvent $event): void
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication || (!$user->isAdmin() && !$user->check('tables_select', Reminder::TABLE))) {
            return;
        }
        $request = $event->getRequest();
        $pageUid = (int)($request->getQueryParams()['id'] ?? 0);
        $state = $this->filter->getState($request, $user);
        $filterActive = $state['mine'] || $state['due'];
        if (!$filterActive && $this->reminderRepository->countOnPage($pageUid) === 0) {
            return;
        }

        $languageService = $this->getLanguageService();
        $buttons = [
            'mine' => ['icon' => 'actions-user', 'label' => $languageService->sL(self::LL . 'list.filter.mine')],
            'due' => ['icon' => 'actions-clock', 'label' => $languageService->sL(self::LL . 'list.filter.due')],
        ];
        $html = '<div class="content-reminder-filterbar">'
            . '<span class="content-reminder-filterbar__label">'
            . $this->iconFactory->getIcon('content-reminder-reminder', IconSize::SMALL)->render() . ' '
            . htmlspecialchars($languageService->sL(self::LL . 'list.filter.label'))
            . '</span>'
            . '<div class="btn-group" role="group">';
        foreach ($buttons as $filter => $button) {
            $active = $state[$filter];
            $html .= sprintf(
                '<a class="btn btn-default btn-sm%s" href="%s" aria-pressed="%s">%s %s</a>',
                $active ? ' active' : '',
                htmlspecialchars((string)$this->filter->buildToggleUri($request->getUri(), $filter, !$active)),
                $active ? 'true' : 'false',
                $this->iconFactory->getIcon($active ? 'actions-check-square' : $button['icon'], IconSize::SMALL)->render(),
                htmlspecialchars($button['label'])
            );
        }
        $html .= '</div>';
        if ($filterActive) {
            $html .= '<span class="badge badge-info">' . htmlspecialchars($languageService->sL(self::LL . 'list.filter.active')) . '</span>';
        }
        $html .= '</div>';
        $event->addContentAbove($html);

        $this->pageRenderer->addCssFile('EXT:content_reminder/Resources/Public/Css/backend.css');
        $this->pageRenderer->loadJavaScriptModule('@formatsoft/content-reminder/reminder-actions.js');
        $this->pageRenderer->addInlineLanguageLabelFile('EXT:content_reminder/Resources/Private/Language/locallang_be.xlf', 'js.');
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
