<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Controller;

use Formatsoft\ContentReminder\Domain\Repository\ReminderLogRepository;
use Formatsoft\ContentReminder\Domain\Repository\ReminderRepository;
use Formatsoft\ContentReminder\Exception\ActionNotAllowedException;
use Formatsoft\ContentReminder\Service\ReminderActionService;
use Formatsoft\ContentReminder\Service\ReminderPermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * Backend AJAX endpoints for the reminder actions and the history modal.
 * Routes: Configuration/Backend/AjaxRoutes.php (CSRF protection by the backend router).
 */
#[AsController]
final readonly class ReminderAjaxController
{
    private const LL = 'LLL:EXT:content_reminder/Resources/Private/Language/locallang_be.xlf:';

    public function __construct(
        private ReminderRepository $reminderRepository,
        private ReminderLogRepository $logRepository,
        private ReminderActionService $actionService,
        private ReminderPermissionService $permissionService,
        private ViewFactoryInterface $viewFactory,
    ) {}

    /**
     * POST uid, action (complete|takeOver|pause|resume|reopen), comment (complete only)
     */
    public function actionAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $action = (string)($body['action'] ?? '');
        $reminder = $this->reminderRepository->findByUid((int)($body['uid'] ?? 0));
        $languageService = $this->getLanguageService();
        if ($reminder === null) {
            return $this->error($languageService->sL(self::LL . 'action.error.notFound'), 404);
        }

        $user = $this->getBackendUser();
        try {
            match ($action) {
                'complete' => $this->actionService->complete($reminder, $user, (string)($body['comment'] ?? '')),
                'takeOver' => $this->actionService->takeOver($reminder, $user),
                'pause' => $this->actionService->pause($reminder, $user),
                'resume' => $this->actionService->resume($reminder, $user),
                'reopen' => $this->actionService->reopen($reminder, $user),
                default => throw new \InvalidArgumentException('Unknown action "' . $action . '"', 1791100020),
            };
        } catch (ActionNotAllowedException) {
            return $this->error($languageService->sL(self::LL . 'action.error.notAllowed'), 403);
        } catch (\InvalidArgumentException) {
            return $this->error($languageService->sL(self::LL . 'action.error.unknownAction'), 400);
        } catch (\RuntimeException) {
            return $this->error($languageService->sL(self::LL . 'action.error.failed'), 500);
        }

        return new JsonResponse([
            'success' => true,
            'message' => sprintf($languageService->sL(self::LL . 'action.success.' . $action), $reminder->title),
        ]);
    }

    /**
     * GET page (history of a page) or reminder (history of one reminder); returns HTML for a modal.
     */
    public function historyAction(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $reminderUid = (int)($params['reminder'] ?? 0);
        $pageUid = (int)($params['page'] ?? 0);
        if ($reminderUid > 0) {
            // A deleted reminder still has its history; access is checked on its page
            $entries = $this->logRepository->findByReminder($reminderUid);
            $pageUid = (int)($entries[0]['page'] ?? $this->reminderRepository->findByUid($reminderUid)->pageUid ?? 0);
        } else {
            $entries = $this->logRepository->findByPage($pageUid);
        }

        $page = BackendUtility::getRecord('pages', $pageUid);
        if (!is_array($page) || !$this->permissionService->canView($this->getBackendUser(), $page)) {
            return new HtmlResponse('', 403);
        }

        $view = $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: ['EXT:content_reminder/Resources/Private/Templates'],
            request: $request,
        ));
        $view->assignMultiple([
            'entries' => $entries,
            'singleReminder' => $reminderUid > 0,
            'dateFormat' => $GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'] ?? 'd.m.Y',
            'dateTimeFormat' => ($GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'] ?? 'd.m.Y') . ' ' . ($GLOBALS['TYPO3_CONF_VARS']['SYS']['hhmm'] ?? 'H:i'),
        ]);
        return new HtmlResponse($view->render('Ajax/History'));
    }

    private function error(string $message, int $status): ResponseInterface
    {
        return new JsonResponse(['success' => false, 'message' => $message], $status);
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
