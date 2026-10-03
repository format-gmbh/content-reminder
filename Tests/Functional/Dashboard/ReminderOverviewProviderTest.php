<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Tests\Functional\Dashboard;

use Formatsoft\ContentReminder\Dashboard\ReminderOverviewProvider;
use Formatsoft\ContentReminder\Domain\Repository\ReminderLogRepository;
use Formatsoft\ContentReminder\Domain\Repository\ReminderRepository;
use Formatsoft\ContentReminder\Service\Clock;
use Formatsoft\ContentReminder\Service\ReminderPermissionService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Dashboard data (concept 2.4.4): only reminders on pages the user may see.
 */
final class ReminderOverviewProviderTest extends FunctionalTestCase
{
    private const EDITOR = 2;
    private const OUTSIDER = 6;

    protected array $coreExtensionsToLoad = ['dashboard'];

    protected array $testExtensionsToLoad = ['formatsoft/content-reminder'];

    private ReminderOverviewProvider $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Permissions.csv');

        $context = GeneralUtility::makeInstance(Context::class);
        $context->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('2026-10-03 10:30:00')));

        $connectionPool = $this->getConnectionPool();
        $logRepository = new ReminderLogRepository($connectionPool);
        $logRepository->add(1, 'Old task', 2, 'Editable', 3, 'Carla Colleague', new \DateTimeImmutable('2026-10-02 09:00'), null, null, 'done');

        $this->subject = new ReminderOverviewProvider(
            new ReminderRepository($connectionPool),
            $logRepository,
            new ReminderPermissionService(),
            new Clock($context),
            $this->get(UriBuilder::class),
            $connectionPool,
        );
    }

    #[Test]
    public function myRemindersContainOnlyOwnOpenRemindersWithoutDateFirst(): void
    {
        $this->loginAs(self::EDITOR);

        $items = $this->subject->getItems('open', 'me', 10);

        self::assertSame([3, 4], array_map(static fn(array $item): int => $item['reminder']->uid, $items));
        self::assertSame('Editable', $items[0]['pageTitle']);
        self::assertTrue($items[1]['overdue']);
        self::assertTrue($items[1]['canComplete']);
        self::assertStringContainsString('id=2', $items[0]['layoutUrl']);
    }

    #[Test]
    public function unassignedAndOverdueScopesAreApplied(): void
    {
        $this->loginAs(self::EDITOR);

        $unassigned = $this->subject->getItems('open', 'unassigned', 10);
        self::assertSame([2], array_map(static fn(array $item): int => $item['reminder']->uid, $unassigned));
        self::assertTrue($unassigned[0]['canTakeOver']);

        $overdue = $this->subject->getItems('overdue', 'any', 10);
        self::assertSame([4], array_map(static fn(array $item): int => $item['reminder']->uid, $overdue));
        self::assertSame('Eddi Editor', $overdue[0]['assigneeName']);
    }

    #[Test]
    public function limitIsRespected(): void
    {
        $this->loginAs(self::EDITOR);

        self::assertCount(2, $this->subject->getItems('open', 'any', 2));
    }

    #[Test]
    public function userWithoutAccessToThePageSeesNothing(): void
    {
        $this->loginAs(self::OUTSIDER);

        self::assertSame([], $this->subject->getItems('open', 'any', 10));
        self::assertSame([], $this->subject->getRecentlyCompleted(10));
        self::assertSame(['notDue' => 0, 'due' => 0, 'overdue' => 0, 'paused' => 0], $this->subject->countByState());
    }

    #[Test]
    public function countsAndRecentlyCompletedForEditor(): void
    {
        $this->loginAs(self::EDITOR);

        self::assertSame(2, $this->subject->countDueForUser());
        // Reminders 1-3 have no due date (due), reminder 4 is overdue
        self::assertSame(['notDue' => 0, 'due' => 3, 'overdue' => 1, 'paused' => 0], $this->subject->countByState());

        $entries = $this->subject->getRecentlyCompleted(10);
        self::assertCount(1, $entries);
        self::assertSame('Old task', $entries[0]['reminder_title']);
        self::assertSame('Carla Colleague', $entries[0]['completed_by_name']);
    }

    private function loginAs(int $userUid): void
    {
        $backendUser = $this->setUpBackendUser($userUid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }
}
