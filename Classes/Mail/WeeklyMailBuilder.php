<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Mail;

use Doctrine\DBAL\ArrayParameterType;
use Formatsoft\ContentReminder\Domain\Model\Reminder;
use Formatsoft\ContentReminder\Domain\Repository\ReminderRepository;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Collects the weekly mails of one site (concept 6.2):
 * - one mail per responsible person with their overdue reminders and the reminders
 *   due until today + lookahead days (reminders without due date count as due now),
 * - unassigned reminders go to the collective address of the site, if configured,
 * - persons without valid email address, disabled users and users who opted out are skipped,
 * - nothing is sent if there are no reminders.
 */
class WeeklyMailBuilder
{
    /**
     * Name of the user setting (be_users.uc) for opting out of the weekly mail. Registered in
     * Configuration/TCA/Overrides/be_users.php (v14.2+) and EventListener\RegisterLegacyUserSetting.
     */
    public const OPT_OUT_SETTING = 'contentReminderMailOptOut';

    public function __construct(
        private readonly SitePageCollector $pageCollector,
        private readonly ReminderRepository $reminderRepository,
        private readonly ConnectionPool $connectionPool,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return list<WeeklyMail>
     */
    public function build(
        int $rootPageUid,
        string $siteTitle,
        string $siteLanguage,
        WeeklyMailSettings $settings,
        \DateTimeImmutable $today,
    ): array {
        $today = $today->setTime(0, 0);
        $pages = $this->pageCollector->collect($rootPageUid);
        if ($pages === []) {
            return [];
        }
        $until = $today->modify('+' . $settings->lookaheadDays . ' days');
        $byAssignee = [];
        foreach ($this->reminderRepository->findDueOnPagesUntil(array_keys($pages), $until) as $reminder) {
            $byAssignee[$reminder->assignee][] = $reminder;
        }

        $mails = [];
        $users = $this->fetchUsers(array_filter(array_keys($byAssignee)));
        foreach ($byAssignee as $assignee => $reminders) {
            if ($assignee === 0) {
                if ($settings->unassignedRecipient !== '' && GeneralUtility::validEmail($settings->unassignedRecipient)) {
                    $mails[] = $this->createMail($settings->unassignedRecipient, '', $siteLanguage, true, $siteTitle, $reminders, $pages, $settings, $today);
                }
                continue;
            }
            $user = $users[$assignee] ?? null;
            if ($user === null) {
                $this->logger->info('Weekly mail skipped: backend user {uid} does not exist or is disabled', ['uid' => $assignee]);
                continue;
            }
            if ($this->hasOptedOut($user)) {
                continue;
            }
            $email = trim((string)$user['email']);
            if (!GeneralUtility::validEmail($email)) {
                $this->logger->warning('Weekly mail skipped: backend user {uid} has no valid email address', ['uid' => $assignee]);
                continue;
            }
            $mails[] = $this->createMail(
                $email,
                trim((string)$user['realName']) ?: (string)$user['username'],
                $this->normalizeLanguage((string)$user['lang']),
                false,
                $siteTitle,
                $reminders,
                $pages,
                $settings,
                $today
            );
        }
        return $mails;
    }

    /**
     * @param list<Reminder> $reminders
     * @param array<int, string> $pages
     */
    private function createMail(
        string $email,
        string $name,
        string $language,
        bool $unassignedDigest,
        string $siteTitle,
        array $reminders,
        array $pages,
        WeeklyMailSettings $settings,
        \DateTimeImmutable $today,
    ): WeeklyMail {
        $overdue = [];
        $upcoming = [];
        $dateFormat = $language === 'de' ? 'd.m.Y' : 'Y-m-d';
        foreach ($reminders as $reminder) {
            $item = [
                'uid' => $reminder->uid,
                'title' => $reminder->title,
                'notes' => $reminder->notes,
                'pageUid' => $reminder->pageUid,
                'pageTitle' => $pages[$reminder->pageUid] ?? '',
                'dueDate' => $reminder->dueDate?->format($dateFormat) ?? '',
                'overdue' => $reminder->dueDate !== null && $reminder->dueDate < $today,
                'url' => $settings->getPageModuleUrl($reminder->pageUid),
            ];
            if ($item['overdue']) {
                $overdue[] = $item;
            } else {
                $upcoming[] = $item;
            }
        }
        return new WeeklyMail($email, $name, $language, $unassignedDigest, $siteTitle, $overdue, $upcoming);
    }

    /**
     * Active backend users (not deleted, not disabled, within start/end time).
     *
     * @param array<int> $uids
     * @return array<int, array<string, mixed>>
     */
    private function fetchUsers(array $uids): array
    {
        if ($uids === []) {
            return [];
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $rows = $queryBuilder
            ->select('uid', 'username', 'realName', 'email', 'lang', 'uc')
            ->from('be_users')
            ->where($queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter(array_values($uids), ArrayParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAllAssociative();
        $users = [];
        foreach ($rows as $row) {
            $users[(int)$row['uid']] = $row;
        }
        return $users;
    }

    /**
     * The opt-out is stored in the user settings (uc), which TYPO3 v13 and v14 both write.
     *
     * @param array<string, mixed> $user
     */
    private function hasOptedOut(array $user): bool
    {
        $uc = is_string($user['uc'] ?? null) && $user['uc'] !== ''
            ? unserialize($user['uc'], ['allowed_classes' => false])
            : [];
        return is_array($uc) && !empty($uc[self::OPT_OUT_SETTING]);
    }

    /**
     * Mails are available in German and English; everything else falls back to English.
     */
    private function normalizeLanguage(string $language): string
    {
        // "default" is English; "de", "de_DE" or "de-AT" are German
        $language = strtolower($language);
        return $language === 'de' || str_starts_with($language, 'de_') || str_starts_with($language, 'de-') ? 'de' : 'en';
    }
}
