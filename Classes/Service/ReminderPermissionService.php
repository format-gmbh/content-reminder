<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Service;

use Formatsoft\ContentReminder\Domain\Model\Reminder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Single place for all permission decisions on reminders (concept chapter 4).
 *
 * A permission is granted only if all three levels allow it:
 * 1. table permissions (tables_select / tables_modify),
 * 2. page permissions incl. web mounts (show / edit content) on the reminder's page,
 * 3. the custom options "assignOthers" and "manageAll" where the matrix requires them.
 * Admins pass levels 1-3, but state rules (e.g. only open reminders can be completed) still apply.
 *
 * $page is the full record of the page (default language) the reminder belongs to,
 * as returned by BackendUtility::getRecord('pages', ...). It must contain the perms_* fields.
 */
final class ReminderPermissionService
{
    public const OPTION_ASSIGN_OTHERS = 'content_reminder:assignOthers';
    public const OPTION_MANAGE_ALL = 'content_reminder:manageAll';

    /**
     * See reminders (and the archive) of a page.
     *
     * @param array<string, mixed> $page
     */
    public function canView(BackendUserAuthentication $user, array $page): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        return $this->userUid($user) > 0
            && $user->check('tables_select', Reminder::TABLE)
            && $user->doesUserHaveAccess($page, Permission::PAGE_SHOW);
    }

    /**
     * Create a reminder on the page. Who it may be assigned to is checked by canAssignNew().
     *
     * @param array<string, mixed> $page
     */
    public function canCreate(BackendUserAuthentication $user, array $page): bool
    {
        return $this->canWrite($user, $page);
    }

    /**
     * Assignee for a new reminder: unassigned or oneself is always possible,
     * another person requires "assignOthers".
     *
     * @param array<string, mixed> $page
     */
    public function canAssignNew(BackendUserAuthentication $user, array $page, int $assignee): bool
    {
        if (!$this->canWrite($user, $page)) {
            return false;
        }
        return $assignee === 0
            || $assignee === $this->userUid($user)
            || $this->hasOption($user, self::OPTION_ASSIGN_OTHERS);
    }

    /**
     * Edit title, notes, due date, recurrence. Also governs reopen, pause and resume.
     *
     * @param array<string, mixed> $page
     */
    public function canEdit(BackendUserAuthentication $user, Reminder $reminder, array $page): bool
    {
        if (!$this->canWrite($user, $page)) {
            return false;
        }
        $userUid = $this->userUid($user);
        return $reminder->isCreatedBy($userUid)
            || $reminder->isAssignedTo($userUid)
            || $this->hasOption($user, self::OPTION_MANAGE_ALL);
    }

    /**
     * Change the responsible person of an existing reminder.
     *
     * @param array<string, mixed> $page
     */
    public function canChangeAssignee(BackendUserAuthentication $user, Reminder $reminder, array $page, int $newAssignee): bool
    {
        if ($newAssignee === $reminder->assignee) {
            return $this->canWrite($user, $page);
        }
        $userUid = $this->userUid($user);
        if ($newAssignee === $userUid) {
            return $this->canTakeOver($user, $reminder, $page);
        }
        if (!$this->canWrite($user, $page)) {
            return false;
        }
        if ($newAssignee === 0) {
            // Giving a reminder back is allowed for the current assignee
            return $reminder->isAssignedTo($userUid)
                || $this->hasOption($user, self::OPTION_ASSIGN_OTHERS)
                || $this->hasOption($user, self::OPTION_MANAGE_ALL);
        }
        return $this->hasOption($user, self::OPTION_ASSIGN_OTHERS);
    }

    /**
     * Make oneself the responsible person. Unassigned reminders can be taken over by
     * everybody with write access, assigned ones only with "assignOthers" or "manageAll".
     *
     * @param array<string, mixed> $page
     */
    public function canTakeOver(BackendUserAuthentication $user, Reminder $reminder, array $page): bool
    {
        if (!$reminder->isOpen() || !$this->canWrite($user, $page)) {
            return false;
        }
        $userUid = $this->userUid($user);
        if ($reminder->isAssignedTo($userUid)) {
            return false;
        }
        return $reminder->isUnassigned()
            || $this->hasOption($user, self::OPTION_ASSIGN_OTHERS)
            || $this->hasOption($user, self::OPTION_MANAGE_ALL);
    }

    /**
     * Mark as done: the assignee; everybody with write access if unassigned; otherwise "manageAll".
     *
     * @param array<string, mixed> $page
     */
    public function canComplete(BackendUserAuthentication $user, Reminder $reminder, array $page): bool
    {
        if (!$reminder->isOpen() || !$this->canWrite($user, $page)) {
            return false;
        }
        return $reminder->isAssignedTo($this->userUid($user))
            || $reminder->isUnassigned()
            || $this->hasOption($user, self::OPTION_MANAGE_ALL);
    }

    /**
     * @param array<string, mixed> $page
     */
    public function canReopen(BackendUserAuthentication $user, Reminder $reminder, array $page): bool
    {
        return $reminder->isDone() && $this->canEdit($user, $reminder, $page);
    }

    /**
     * @param array<string, mixed> $page
     */
    public function canPause(BackendUserAuthentication $user, Reminder $reminder, array $page): bool
    {
        return $reminder->isOpen() && !$reminder->paused && $this->canEdit($user, $reminder, $page);
    }

    /**
     * @param array<string, mixed> $page
     */
    public function canResume(BackendUserAuthentication $user, Reminder $reminder, array $page): bool
    {
        return $reminder->paused && $this->canEdit($user, $reminder, $page);
    }

    /**
     * Delete: the creator or "manageAll".
     *
     * @param array<string, mixed> $page
     */
    public function canDelete(BackendUserAuthentication $user, Reminder $reminder, array $page): bool
    {
        if (!$this->canWrite($user, $page)) {
            return false;
        }
        return $reminder->isCreatedBy($this->userUid($user))
            || $this->hasOption($user, self::OPTION_MANAGE_ALL);
    }

    /**
     * Levels 1 and 2 for all modifying actions: tables_modify and "edit content" on the page.
     *
     * @param array<string, mixed> $page
     */
    private function canWrite(BackendUserAuthentication $user, array $page): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        return $this->userUid($user) > 0
            && $user->check('tables_modify', Reminder::TABLE)
            && $user->doesUserHaveAccess($page, Permission::CONTENT_EDIT);
    }

    private function hasOption(BackendUserAuthentication $user, string $option): bool
    {
        return $user->isAdmin() || $user->check('custom_options', $option);
    }

    private function userUid(BackendUserAuthentication $user): int
    {
        return (int)$user->getUserId();
    }
}
