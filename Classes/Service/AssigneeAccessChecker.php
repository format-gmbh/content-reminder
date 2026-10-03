<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Checks whether another backend user would be able to work on reminders of a page.
 * Used to warn when a reminder is assigned to somebody without access (concept 4.5).
 */
class AssigneeAccessChecker
{
    public function __construct(
        private readonly ReminderPermissionService $permissionService,
    ) {}

    /**
     * @param array<string, mixed> $page
     * @return bool false also if the user does not exist or is disabled
     */
    public function hasWriteAccess(int $userUid, array $page): bool
    {
        if ($userUid <= 0) {
            return false;
        }
        $user = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $user->setBeUserByUid($userUid);
        if (!is_array($user->user) || (int)($user->user['uid'] ?? 0) !== $userUid) {
            return false;
        }
        $user->fetchGroupData();

        return $this->permissionService->canCreate($user, $page);
    }

    /**
     * @return string Real name, or username if no real name is set
     */
    public function getDisplayName(int $userUid): string
    {
        $user = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $record = $user->getRawUserByUid($userUid);
        if (!is_array($record)) {
            return '#' . $userUid;
        }
        return trim((string)($record['realName'] ?? '')) ?: (string)($record['username'] ?? '#' . $userUid);
    }
}
