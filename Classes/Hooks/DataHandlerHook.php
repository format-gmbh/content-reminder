<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Hooks;

use Formatsoft\ContentReminder\DataHandling\TrustedOperation;
use Formatsoft\ContentReminder\Domain\Model\Reminder;
use Formatsoft\ContentReminder\Domain\Model\ReminderStatus;
use Formatsoft\ContentReminder\Domain\Repository\ReminderRepository;
use Formatsoft\ContentReminder\Service\AssigneeAccessChecker;
use Formatsoft\ContentReminder\Service\ReminderPermissionService;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\SysLog\Action\Database as SystemLogDatabaseAction;
use TYPO3\CMS\Core\SysLog\Error as SystemLogErrorClassification;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * Enforces the permission matrix (concept 4.4) for every write access through the
 * DataHandler: editing form, list module, clipboard, API. The extension's own actions
 * run inside a TrustedOperation and are checked before they reach the DataHandler.
 *
 * Denied changes are removed and reported via the DataHandler log (shown as error
 * message in the backend). Allowed assignments to persons without access to the page
 * only trigger a warning (E11).
 */
final readonly class DataHandlerHook
{
    /** Fields that may only be changed by the extension's actions */
    private const PROTECTED_FIELDS = ['creator', 'status', 't3_origuid'];

    /** Fields governed by the "edit" permission */
    private const CONTENT_FIELDS = ['title', 'notes', 'due_date', 'recurrence_unit', 'recurrence_value'];

    private const LL = 'LLL:EXT:content_reminder/Resources/Private/Language/locallang_be.xlf:';

    public function __construct(
        private ReminderPermissionService $permissionService,
        private ReminderRepository $reminderRepository,
        private AssigneeAccessChecker $assigneeAccessChecker,
        private TrustedOperation $trustedOperation,
        private FlashMessageService $flashMessageService,
        private LanguageServiceFactory $languageServiceFactory,
    ) {}

    /**
     * @param array<string, mixed>|null $fieldArray for updates: only the changed fields
     */
    public function processDatamap_postProcessFieldArray(string $status, string $table, string|int $id, ?array &$fieldArray, DataHandler $dataHandler): void
    {
        if ($table !== Reminder::TABLE || !is_array($fieldArray) || $this->trustedOperation->isActive()) {
            return;
        }
        if ($status === 'new') {
            $this->processNewRecord($fieldArray, $dataHandler);
        } else {
            $this->processUpdate((int)$id, $fieldArray, $dataHandler);
        }
    }

    public function processCmdmap(string $command, string $table, string|int $id, mixed $value, bool &$commandIsProcessed, DataHandler $dataHandler, mixed $pasteUpdate): void
    {
        if ($table !== Reminder::TABLE || $commandIsProcessed || $this->trustedOperation->isActive()) {
            return;
        }
        if ($command !== 'delete' && $command !== 'move') {
            // "copy" creates a new record and is handled in processNewRecord()
            return;
        }
        $reminder = $this->reminderRepository->findByUid((int)$id);
        $page = $reminder !== null ? $this->getPage($reminder->pageUid) : null;
        if ($reminder === null || $page === null) {
            return;
        }
        $user = $dataHandler->BE_USER;

        if ($command === 'delete' && !$this->permissionService->canDelete($user, $reminder, $page)) {
            $commandIsProcessed = true;
            $this->logError($dataHandler, $reminder->uid, SystemLogDatabaseAction::DELETE, 'datahandler.error.deleteNotAllowed', ['title' => $reminder->title]);
            return;
        }

        if ($command === 'move') {
            $targetPage = $this->getPage($this->resolveTargetPageUid((int)$value));
            if (!$this->permissionService->canEdit($user, $reminder, $page)
                || $targetPage === null
                || !$this->permissionService->canCreate($user, $targetPage)
            ) {
                $commandIsProcessed = true;
                $this->logError($dataHandler, $reminder->uid, SystemLogDatabaseAction::MOVE, 'datahandler.error.moveNotAllowed', ['title' => $reminder->title]);
            }
        }
    }

    /**
     * @param array<string, mixed>|null $fieldArray
     */
    private function processNewRecord(?array &$fieldArray, DataHandler $dataHandler): void
    {
        $user = $dataHandler->BE_USER;
        $page = $this->getPage((int)($fieldArray['pid'] ?? 0));
        if ($page === null) {
            // The DataHandler already refuses records on non-existing pages
            return;
        }
        $title = (string)($fieldArray['title'] ?? '');

        if (!$this->permissionService->canCreate($user, $page)) {
            $fieldArray = null;
            $this->logError($dataHandler, 0, SystemLogDatabaseAction::INSERT, 'datahandler.error.createNotAllowed', ['page' => (string)($page['title'] ?? $page['uid'])]);
            return;
        }

        $isCopy = (int)($fieldArray['t3_origuid'] ?? 0) > 0;
        $fieldArray['creator'] = (int)$user->getUserId();
        $fieldArray['status'] = ReminderStatus::Open->value;

        $assignee = (int)($fieldArray['assignee'] ?? 0);
        if (!$this->permissionService->canAssignNew($user, $page, $assignee)) {
            $fieldArray['assignee'] = $assignee = 0;
            // A copy (e.g. as part of a page copy) silently loses an assignment the user may not make
            if (!$isCopy) {
                $this->logError($dataHandler, 0, SystemLogDatabaseAction::INSERT, 'datahandler.error.assignNotAllowed', ['title' => $title]);
            }
        }
        $this->warnIfAssigneeHasNoAccess($user, $assignee, $page, $title);
    }

    /**
     * @param array<string, mixed>|null $fieldArray only the changed fields
     */
    private function processUpdate(int $uid, ?array &$fieldArray, DataHandler $dataHandler): void
    {
        $reminder = $this->reminderRepository->findByUid($uid);
        $page = $reminder !== null ? $this->getPage($reminder->pageUid) : null;
        if ($reminder === null || $page === null || $fieldArray === null) {
            return;
        }
        $user = $dataHandler->BE_USER;
        $context = ['title' => $reminder->title];

        foreach (self::PROTECTED_FIELDS as $field) {
            if (array_key_exists($field, $fieldArray)) {
                unset($fieldArray[$field]);
                $this->logError($dataHandler, $uid, SystemLogDatabaseAction::UPDATE, 'datahandler.error.protectedField', $context + ['field' => $field]);
            }
        }

        if (array_key_exists('assignee', $fieldArray)) {
            $newAssignee = (int)$fieldArray['assignee'];
            if (!$this->permissionService->canChangeAssignee($user, $reminder, $page, $newAssignee)) {
                unset($fieldArray['assignee']);
                $this->logError($dataHandler, $uid, SystemLogDatabaseAction::UPDATE, 'datahandler.error.changeAssigneeNotAllowed', $context);
            } else {
                $this->warnIfAssigneeHasNoAccess($user, $newAssignee, $page, $reminder->title);
            }
        }

        $changedContentFields = array_intersect(self::CONTENT_FIELDS, array_keys($fieldArray));
        if ($changedContentFields !== [] && !$this->permissionService->canEdit($user, $reminder, $page)) {
            foreach ($changedContentFields as $field) {
                unset($fieldArray[$field]);
            }
            $this->logError($dataHandler, $uid, SystemLogDatabaseAction::UPDATE, 'datahandler.error.editNotAllowed', $context);
        }

        if (array_key_exists('hidden', $fieldArray)) {
            $allowed = (bool)$fieldArray['hidden']
                ? $this->permissionService->canPause($user, $reminder, $page)
                : $this->permissionService->canResume($user, $reminder, $page);
            if (!$allowed) {
                unset($fieldArray['hidden']);
                $this->logError($dataHandler, $uid, SystemLogDatabaseAction::UPDATE, 'datahandler.error.pauseNotAllowed', $context);
            }
        }

        // Nothing left but the timestamp: skip the update completely
        if (array_diff(array_keys($fieldArray), ['tstamp']) === []) {
            $fieldArray = [];
        }
    }

    /**
     * @param array<string, mixed> $page
     */
    private function warnIfAssigneeHasNoAccess(BackendUserAuthentication $user, int $assignee, array $page, string $title): void
    {
        if ($assignee <= 0 || $assignee === (int)$user->getUserId()) {
            return;
        }
        if ($this->assigneeAccessChecker->hasWriteAccess($assignee, $page)) {
            return;
        }
        $languageService = $this->getLanguageService($user);
        $this->flashMessageService->getMessageQueueByIdentifier()->enqueue(new FlashMessage(
            sprintf(
                $languageService->sL(self::LL . 'datahandler.warning.assigneeWithoutAccess'),
                $this->assigneeAccessChecker->getDisplayName($assignee),
                (string)($page['title'] ?? $page['uid']),
                $title
            ),
            $languageService->sL(self::LL . 'datahandler.warning.assigneeWithoutAccess.title'),
            ContextualFeedbackSeverity::WARNING,
            true
        ));
    }

    /**
     * Target of a move: a positive value is a page uid, a negative value means
     * "after the reminder with this uid" and therefore its page.
     */
    private function resolveTargetPageUid(int $target): int
    {
        if ($target >= 0) {
            return $target;
        }
        return $this->reminderRepository->findByUid(abs($target))?->pageUid ?? 0;
    }

    /**
     * Page record in default language (reminders are language independent, E2).
     *
     * @return array<string, mixed>|null
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

    /**
     * @param array<string, string|int> $data placeholders like {title} in the message
     */
    private function logError(DataHandler $dataHandler, int $uid, int $action, string $labelKey, array $data): void
    {
        $dataHandler->log(
            Reminder::TABLE,
            $uid,
            $action,
            null,
            SystemLogErrorClassification::USER_ERROR,
            $this->getLanguageService($dataHandler->BE_USER)->sL(self::LL . $labelKey),
            null,
            $data
        );
    }

    private function getLanguageService(BackendUserAuthentication $user): LanguageService
    {
        return $GLOBALS['LANG'] ?? $this->languageServiceFactory->createFromUserPreferences($user);
    }
}
