<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Tests\Functional\StaleContent;

use Formatsoft\ContentReminder\Domain\Model\Reminder;
use Formatsoft\ContentReminder\Domain\Model\ReminderOrigin;
use Formatsoft\ContentReminder\Domain\Repository\ReminderRepository;
use Formatsoft\ContentReminder\Mail\SitePageCollector;
use Formatsoft\ContentReminder\Service\ReminderActionService;
use Formatsoft\ContentReminder\StaleContent\StaleContentSettings;
use Formatsoft\ContentReminder\StaleContent\StalePage;
use Formatsoft\ContentReminder\StaleContent\StalePageFinder;
use Formatsoft\ContentReminder\StaleContent\StaleReminderCreator;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Automatic reminders for outdated content (concept 6.3, E35–E40).
 * Today is 2026-10-03; with 24 months, pages without changes since 2024-10-03 are outdated.
 */
final class StaleContentTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['dashboard'];

    protected array $testExtensionsToLoad = ['formatsoft/content-reminder'];

    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/StaleContent.csv');
        $this->today = new \DateTimeImmutable('2026-10-03 00:00:00');
        GeneralUtility::makeInstance(Context::class)
            ->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('2026-10-03 10:30:00')));
    }

    #[Test]
    public function findsOutdatedStandardPagesOldestFirst(): void
    {
        $pages = $this->find(new StaleContentSettings(enabled: true, months: 24, excludePages: [6]));

        // 13: no records, created 2022; 2, 8, 12, 17, 18, 20: content from 2023
        self::assertSame([13, 2, 8, 12, 17, 18, 20], $this->uids($pages));
        self::assertSame('2022-01-01', $pages[0]->lastChange->format('Y-m-d'));
        self::assertSame('2023-01-01', $pages[1]->lastChange->format('Y-m-d'));
    }

    #[Test]
    public function changedPagePropertiesDoNotCountAsContentChange(): void
    {
        $uids = $this->uids($this->find(new StaleContentSettings(enabled: true)));

        self::assertContains(8, $uids, 'page properties changed');
        self::assertContains(18, $uids, 'page properties of the translation changed');
        self::assertContains(20, $uids, 'only a subpage changed');
    }

    #[Test]
    public function recordsOfOtherTablesAndTranslationsCount(): void
    {
        $uids = $this->uids($this->find(new StaleContentSettings(enabled: true)));

        self::assertNotContains(9, $uids, 'file reference changed recently');
        self::assertNotContains(14, $uids, 'translated content element changed recently');
        self::assertNotContains(1, $uids, 'content element changed recently');
        self::assertContains(12, $uids, 'deleted content elements do not count');
    }

    #[Test]
    public function hiddenFoldersExcludedAndDeletedPagesAreSkipped(): void
    {
        $uids = $this->uids($this->find(new StaleContentSettings(enabled: true, excludePages: [6])));

        foreach ([3 => 'hidden', 4 => 'folder', 5 => 'page property', 6 => 'excluded subtree', 7 => 'below excluded subtree', 16 => 'deleted'] as $uid => $reason) {
            self::assertNotContains($uid, $uids, $reason);
        }
        // Without the site setting, the subtree is checked
        self::assertContains(7, $this->uids($this->find(new StaleContentSettings(enabled: true))));
    }

    #[Test]
    public function openOrRecentlyCompletedAutomaticRemindersPreventNewOnes(): void
    {
        $uids = $this->uids($this->find(new StaleContentSettings(enabled: true)));

        self::assertNotContains(10, $uids, 'open automatic reminder exists');
        self::assertNotContains(11, $uids, 'automatic reminder completed recently');
        self::assertContains(17, $uids, 'manual reminders do not count');
    }

    #[Test]
    public function monthsAndLimitAreApplied(): void
    {
        // 60 months: only pages without changes since 2021-10-03 → none (oldest change is 2022)
        self::assertSame([], $this->find(new StaleContentSettings(enabled: true, months: 60)));
        // 54 months: since 2022-04-03 → only page 13 (2022-01-01)
        self::assertSame([13], $this->uids($this->find(new StaleContentSettings(enabled: true, months: 54))));
        // Limit: the oldest pages first
        self::assertSame([13, 2], $this->uids($this->find(new StaleContentSettings(enabled: true, excludePages: [6]), 2)));
    }

    #[Test]
    public function createsUnassignedReminderWithoutDueDate(): void
    {
        $user = $this->setUpBackendUser(1);
        $creator = $this->get(StaleReminderCreator::class);

        $uid = $creator->create(new StalePage(2, 'Old content', new \DateTimeImmutable('2023-01-01')), 'de', $user);

        $row = $this->getConnectionPool()->getConnectionForTable(Reminder::TABLE)
            ->select(['*'], Reminder::TABLE, ['uid' => $uid])->fetchAssociative();
        self::assertIsArray($row);
        self::assertSame(2, (int)$row['pid']);
        self::assertSame('Veralteter Inhalt – bitte überprüfen', $row['title']);
        self::assertStringContainsString('01.01.2023', (string)$row['notes']);
        self::assertSame(ReminderOrigin::StaleContent->value, (int)$row['origin']);
        self::assertSame(0, (int)$row['assignee']);
        self::assertSame(0, (int)$row['creator']);
        self::assertNull($row['due_date']);
        self::assertSame(0, (int)$row['status']);

        // English texts for sites with another default language
        $uid = $creator->create(new StalePage(8, 'Only page properties changed', new \DateTimeImmutable('2023-01-01')), 'en', $user);
        $title = $this->getConnectionPool()->getConnectionForTable(Reminder::TABLE)
            ->select(['title'], Reminder::TABLE, ['uid' => $uid])->fetchOne();
        self::assertSame('Outdated content – please review', $title);
    }

    #[Test]
    public function createdReminderIsNotDuplicatedAndCompletionCountsAsReview(): void
    {
        $user = $this->setUpBackendUser(1);
        $settings = new StaleContentSettings(enabled: true, excludePages: [6]);
        $uid = $this->get(StaleReminderCreator::class)->create($this->find($settings)[1], 'en', $user);
        self::assertNotContains(2, $this->uids($this->find($settings)), 'open reminder prevents a duplicate');

        $reminder = $this->get(ReminderRepository::class)->findByUid($uid);
        self::assertNotNull($reminder);
        $this->get(ReminderActionService::class)->complete($reminder, $user, 'Checked, still correct');

        self::assertNotContains(2, $this->uids($this->find($settings)), 'completion today counts as review');
    }

    /**
     * @return list<StalePage>
     */
    private function find(StaleContentSettings $settings, ?int $limit = null): array
    {
        $finder = new StalePageFinder($this->getConnectionPool(), new SitePageCollector($this->getConnectionPool()));
        return $finder->find(1, $settings, $this->today, $limit ?? $settings->limit);
    }

    /**
     * @param list<StalePage> $pages
     * @return list<int>
     */
    private function uids(array $pages): array
    {
        return array_map(static fn(StalePage $page): int => $page->uid, $pages);
    }
}
