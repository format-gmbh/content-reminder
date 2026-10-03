<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Tests\Functional\Domain\Repository;

use Formatsoft\ContentReminder\Domain\Model\Reminder;
use Formatsoft\ContentReminder\Domain\Repository\ReminderRepository;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ReminderRepositoryTest extends FunctionalTestCase
{
    private const USER = 5;

    protected array $coreExtensionsToLoad = ['dashboard'];

    protected array $testExtensionsToLoad = ['formatsoft/content-reminder'];

    private ReminderRepository $subject;

    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Reminders.csv');
        $this->subject = new ReminderRepository(GeneralUtility::makeInstance(ConnectionPool::class));
        $this->today = new \DateTimeImmutable('2026-10-03 14:00:00');
    }

    #[Test]
    public function findDueForUserReturnsOpenUnpausedRemindersWithoutDateOrDueTodayOrEarlier(): void
    {
        $result = $this->subject->findDueForUser(self::USER, $this->today);

        // Without date first, then by due date, then by title
        self::assertSame([1, 2, 10, 3], $this->uids($result));
    }

    #[Test]
    public function findDueForUserCanBeRestrictedToOnePage(): void
    {
        self::assertSame([1, 2, 3], $this->uids($this->subject->findDueForUser(self::USER, $this->today, 10)));
        self::assertSame([10], $this->uids($this->subject->findDueForUser(self::USER, $this->today, 20)));
    }

    #[Test]
    public function findDueForUserReturnsNothingForInvalidUser(): void
    {
        self::assertSame([], $this->subject->findDueForUser(0, $this->today));
    }

    #[Test]
    public function findDueUnassignedReturnsOnlyDueRemindersWithoutAssignee(): void
    {
        self::assertSame([13, 9], $this->uids($this->subject->findDueUnassigned($this->today)));
        self::assertSame([9], $this->uids($this->subject->findDueUnassigned($this->today, 10)));
    }

    #[Test]
    public function findOpenOnPageIncludesPausedAndNotYetDueButNoDoneOrDeleted(): void
    {
        // Without date first, then by due date, then by title
        self::assertSame([1, 8, 2, 6, 9, 3, 4], $this->uids($this->subject->findOpenOnPage(10)));
    }

    #[Test]
    public function findByUidAlsoFindsPausedReminders(): void
    {
        $reminder = $this->subject->findByUid(6);

        self::assertInstanceOf(Reminder::class, $reminder);
        self::assertTrue($reminder->paused);
        self::assertNull($this->subject->findByUid(7));
    }

    #[Test]
    public function summarizeDueOnPagesCountsPerPageInOneQuery(): void
    {
        $result = $this->subject->summarizeDueOnPages(self::USER, $this->today, [10, 20, 30, 40, 50]);

        self::assertSame([10, 20, 40], array_keys($result));

        self::assertSame(3, $result[10]->dueForUser);
        self::assertSame(1, $result[10]->overdueForUser);
        self::assertSame(1, $result[10]->dueUnassigned);

        self::assertSame(1, $result[20]->dueForUser);
        self::assertSame(0, $result[20]->overdueForUser);
        self::assertSame(0, $result[20]->dueUnassigned);

        // Page 30 only has a colleague's reminder, page 50 has none
        self::assertSame(0, $result[40]->dueForUser);
        self::assertSame(1, $result[40]->dueUnassigned);
    }

    #[Test]
    public function summarizeDueOnPagesReturnsNothingWithoutPages(): void
    {
        self::assertSame([], $this->subject->summarizeDueOnPages(self::USER, $this->today, []));
    }

    /**
     * @param list<Reminder> $reminders
     * @return list<int>
     */
    private function uids(array $reminders): array
    {
        return array_map(static fn(Reminder $reminder): int => $reminder->uid, $reminders);
    }
}
