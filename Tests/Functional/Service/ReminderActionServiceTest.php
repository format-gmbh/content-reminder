<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Tests\Functional\Service;

use Formatsoft\ContentReminder\DataHandling\TrustedOperation;
use Formatsoft\ContentReminder\Domain\Model\Reminder;
use Formatsoft\ContentReminder\Domain\Repository\ReminderLogRepository;
use Formatsoft\ContentReminder\Domain\Repository\ReminderRepository;
use Formatsoft\ContentReminder\Exception\ActionNotAllowedException;
use Formatsoft\ContentReminder\Service\Clock;
use Formatsoft\ContentReminder\Service\RecurrenceCalculator;
use Formatsoft\ContentReminder\Service\ReminderActionService;
use Formatsoft\ContentReminder\Service\ReminderPermissionService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ReminderActionServiceTest extends FunctionalTestCase
{
    private const EDITOR = 2;

    protected array $coreExtensionsToLoad = ['dashboard'];

    protected array $testExtensionsToLoad = ['formatsoft/content-reminder'];

    private ReminderActionService $subject;

    private ReminderRepository $reminderRepository;

    private ReminderLogRepository $logRepository;

    private BackendUserAuthentication $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Permissions.csv');

        $context = GeneralUtility::makeInstance(Context::class);
        $context->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('2026-10-03 10:30:00')));

        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $this->reminderRepository = new ReminderRepository($connectionPool);
        $this->logRepository = new ReminderLogRepository($connectionPool);
        $this->subject = new ReminderActionService(
            new ReminderPermissionService(),
            new RecurrenceCalculator(),
            $this->logRepository,
            $this->get(TrustedOperation::class),
            new Clock($context),
        );

        $this->editor = $this->setUpBackendUser(self::EDITOR);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($this->editor);
    }

    #[Test]
    public function completingOneOffReminderSetsStatusDoneAndWritesArchiveEntry(): void
    {
        $nextDueDate = $this->subject->complete($this->reminder(3), $this->editor, '  Prices checked  ');

        self::assertNull($nextDueDate);
        self::assertTrue($this->reminder(3)->isDone());

        $entries = $this->logRepository->findByReminder(3);
        self::assertCount(1, $entries);
        self::assertSame(0, (int)$entries[0]['pid']);
        self::assertSame(2, (int)$entries[0]['page']);
        self::assertSame('Editable', $entries[0]['page_title']);
        self::assertSame('Created by colleague, assigned to editor', $entries[0]['reminder_title']);
        self::assertSame(self::EDITOR, (int)$entries[0]['completed_by']);
        self::assertSame('Eddi Editor', $entries[0]['completed_by_name']);
        self::assertSame('Prices checked', $entries[0]['comment']);
        self::assertNull($entries[0]['due_date']);
        self::assertNull($entries[0]['next_due_date']);
        self::assertSame(1, $this->logRepository->countByPage(2));
    }

    #[Test]
    public function completingRecurringReminderKeepsItOpenWithNextDueDateFromToday(): void
    {
        // Due 01.10., completed 03.10., every 6 months -> 03.04. of the next year (E3)
        $nextDueDate = $this->subject->complete($this->reminder(4), $this->editor);

        self::assertSame('2027-04-03', $nextDueDate?->format('Y-m-d'));
        $reminder = $this->reminder(4);
        self::assertTrue($reminder->isOpen());
        self::assertSame('2027-04-03', $reminder->dueDate?->format('Y-m-d'));

        $entries = $this->logRepository->findByReminder(4);
        self::assertSame('2026-10-01', $entries[0]['due_date']);
        self::assertSame('2027-04-03', $entries[0]['next_due_date']);
    }

    #[Test]
    public function completingReminderOfColleagueIsRefused(): void
    {
        try {
            $this->subject->complete($this->reminder(1), $this->editor);
            self::fail('Expected ActionNotAllowedException');
        } catch (ActionNotAllowedException) {
        }

        self::assertTrue($this->reminder(1)->isOpen());
        self::assertSame([], $this->logRepository->findByReminder(1));
    }

    #[Test]
    public function takeOverAssignsUnassignedReminderToCurrentUser(): void
    {
        $this->subject->takeOver($this->reminder(2), $this->editor);

        self::assertSame(self::EDITOR, $this->reminder(2)->assignee);
    }

    #[Test]
    public function pauseResumeAndReopenChangeTheState(): void
    {
        $this->subject->pause($this->reminder(3), $this->editor);
        self::assertTrue($this->reminder(3)->paused);

        $this->subject->resume($this->reminder(3), $this->editor);
        self::assertFalse($this->reminder(3)->paused);

        $this->subject->complete($this->reminder(3), $this->editor);
        $this->subject->reopen($this->reminder(3), $this->editor);
        self::assertTrue($this->reminder(3)->isOpen());
    }

    private function reminder(int $uid): Reminder
    {
        $reminder = $this->reminderRepository->findByUid($uid);
        self::assertNotNull($reminder);
        return $reminder;
    }
}
