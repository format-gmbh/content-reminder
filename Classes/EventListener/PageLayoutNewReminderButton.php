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
use Formatsoft\ContentReminder\Service\ReminderPermissionService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Button "Create reminder for this page" in the button bar of the page module,
 * next to the "internal note" button of EXT:sys_note. Shown if the user may create
 * reminders on the page.
 */
#[AsEventListener(identifier: 'content-reminder/page-layout-new-reminder-button')]
final readonly class PageLayoutNewReminderButton
{
    private const MODULE = 'web_layout';

    public function __construct(
        private ReminderPermissionService $permissionService,
        private IconFactory $iconFactory,
        private UriBuilder $uriBuilder,
    ) {}

    public function __invoke(ModifyButtonBarEvent $event): void
    {
        // getRequest() exists since TYPO3 v14
        $request = method_exists($event, 'getRequest') ? $event->getRequest() : ($GLOBALS['TYPO3_REQUEST'] ?? null);
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$request instanceof ServerRequestInterface || !$user instanceof BackendUserAuthentication) {
            return;
        }
        $module = $request->getAttribute('module');
        $normalizedParams = $request->getAttribute('normalizedParams');
        $parsedBody = $request->getParsedBody();
        $pageUid = (int)((is_array($parsedBody) ? $parsedBody['id'] ?? null : null) ?? $request->getQueryParams()['id'] ?? 0);
        if ($pageUid <= 0 || $module?->getIdentifier() !== self::MODULE || $normalizedParams === null) {
            return;
        }
        $page = BackendUtility::getRecord('pages', $pageUid);
        if (!is_array($page) || !$this->permissionService->canCreate($user, $page)) {
            return;
        }

        $title = $this->getLanguageService()->sL('LLL:EXT:content_reminder/Resources/Private/Language/locallang_be.xlf:button.newReminder');
        $uri = (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [Reminder::TABLE => [$pageUid => 'new']],
            'module' => self::MODULE,
            'returnUrl' => $normalizedParams->getRequestUri(),
        ]);
        // ComponentFactory exists since TYPO3 v14; ButtonBar::makeLinkButton() is deprecated there
        $button = class_exists(ComponentFactory::class)
            ? GeneralUtility::makeInstance(ComponentFactory::class)->createLinkButton()
            : $event->getButtonBar()->makeLinkButton();
        $button
            ->setTitle($title)
            ->setIcon($this->iconFactory->getIcon('content-reminder-reminder', IconSize::SMALL))
            ->setHref($uri);

        // Same group as the "internal note" button of EXT:sys_note
        $buttons = $event->getButtons();
        $buttons[ButtonBar::BUTTON_POSITION_RIGHT][2][] = $button;
        ksort($buttons[ButtonBar::BUTTON_POSITION_RIGHT]);
        $event->setButtons($buttons);
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
