<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Service;

use Formatsoft\ContentReminder\DataHandling\TrustedOperation;
use Formatsoft\ContentReminder\Domain\Model\Reminder;
use Formatsoft\ContentReminder\Domain\Model\ReminderStatus;
use Formatsoft\ContentReminder\Domain\Repository\ReminderLogRepository;
use Formatsoft\ContentReminder\Exception\ActionNotAllowedException;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The actions offered in the page module panel, the list module and the dashboard.
 *
 * Every action checks its permission first and then writes through the DataHandler
 * inside a TrustedOperation, so that protected fields (status) may be changed while
 * table and page permissions are still enforced by the DataHandler.
 */
class ReminderActionService
{
    public function __construct(
        private readonly ReminderPermissionService $permissionService,
        private readonly RecurrenceCalculator $recurrenceCalculator,
        private readonly ReminderLogRepository $logRepository,
        private readonly TrustedOperation $trustedOperation,
        private readonly Clock $clock,
    ) {}

    /**
     * Mark as done. One-off reminders get status "done"; recurring ones stay open and
     * get the next due date counted from today (E3). Both write an archive entry.
     *
     * @return \DateTimeImmutable|null next due date of a recurring reminder
     */
    public function complete(Reminder $reminder, BackendUserAuthentication $user, string $comment = ''): ?\DateTimeImmutable
    {
        $page = $this->getPage($reminder);
        if (!$this->permissionService->canComplete($user, $reminder, $page)) {
            throw new ActionNotAllowedException('Completing reminder ' . $reminder->uid . ' is not allowed.', 1791100001);
        }

        $now = $this->clock->now();
        $nextDueDate = $this->recurrenceCalculator->calculateNextDueDate($now, $reminder->recurrenceUnit, $reminder->recurrenceValue);
        $fields = $nextDueDate !== null
            ? ['due_date' => $nextDueDate->format('Y-m-d')]
            : ['status' => ReminderStatus::Done->value];
        $this->update($reminder, $fields, $user);

        $this->logRepository->add(
            reminderUid: $reminder->uid,
            reminderTitle: $reminder->title,
            pageUid: $reminder->pageUid,
            pageTitle: (string)($page['title'] ?? ''),
            completedBy: (int)$user->getUserId(),
            completedByName: $this->getUserName($user),
            completedAt: $now,
            dueDate: $reminder->dueDate,
            nextDueDate: $nextDueDate,
            comment: trim($comment),
        );

        return $nextDueDate;
    }

    public function takeOver(Reminder $reminder, BackendUserAuthentication $user): void
    {
        if (!$this->permissionService->canTakeOver($user, $reminder, $this->getPage($reminder))) {
            throw new ActionNotAllowedException('Taking over reminder ' . $reminder->uid . ' is not allowed.', 1791100002);
        }
        $this->update($reminder, ['assignee' => (int)$user->getUserId()], $user);
    }

    public function pause(Reminder $reminder, BackendUserAuthentication $user): void
    {
        if (!$this->permissionService->canPause($user, $reminder, $this->getPage($reminder))) {
            throw new ActionNotAllowedException('Pausing reminder ' . $reminder->uid . ' is not allowed.', 1791100003);
        }
        $this->update($reminder, ['hidden' => 1], $user);
    }

    public function resume(Reminder $reminder, BackendUserAuthentication $user): void
    {
        if (!$this->permissionService->canResume($user, $reminder, $this->getPage($reminder))) {
            throw new ActionNotAllowedException('Resuming reminder ' . $reminder->uid . ' is not allowed.', 1791100004);
        }
        $this->update($reminder, ['hidden' => 0], $user);
    }

    public function reopen(Reminder $reminder, BackendUserAuthentication $user): void
    {
        if (!$this->permissionService->canReopen($user, $reminder, $this->getPage($reminder))) {
            throw new ActionNotAllowedException('Reopening reminder ' . $reminder->uid . ' is not allowed.', 1791100005);
        }
        $this->update($reminder, ['status' => ReminderStatus::Open->value], $user);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function update(Reminder $reminder, array $fields, BackendUserAuthentication $user): void
    {
        $errors = $this->trustedOperation->run(static function () use ($reminder, $fields, $user): array {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([Reminder::TABLE => [$reminder->uid => $fields]], [], $user);
            $dataHandler->process_datamap();
            return $dataHandler->errorLog;
        });
        if ($errors !== []) {
            throw new \RuntimeException(
                'Updating reminder ' . $reminder->uid . ' failed: ' . implode('; ', $errors),
                1791100010
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getPage(Reminder $reminder): array
    {
        return BackendUtility::getRecord('pages', $reminder->pageUid) ?? [];
    }

    private function getUserName(BackendUserAuthentication $user): string
    {
        $realName = trim((string)($user->user['realName'] ?? ''));
        return $realName !== '' ? $realName : (string)($user->user['username'] ?? '');
    }
}
