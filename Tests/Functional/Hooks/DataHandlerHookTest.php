<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Tests\Functional\Hooks;

use Formatsoft\ContentReminder\DataHandling\TrustedOperation;
use Formatsoft\ContentReminder\Domain\Model\Reminder;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Runs the DataHandler with real backend users, groups, page permissions and
 * web mounts, and checks that the hook enforces concept chapter 4.4.
 */
final class DataHandlerHookTest extends FunctionalTestCase
{
    private const ADMIN = 1;
    private const EDITOR = 2;
    private const COLLEAGUE = 3;
    private const DISTRIBUTOR = 4;
    private const LEAD = 5;
    private const OUTSIDER = 6;

    private const PAGE_EDITABLE = 2;
    private const PAGE_READ_ONLY = 3;

    protected array $coreExtensionsToLoad = ['dashboard'];

    protected array $testExtensionsToLoad = ['formatsoft/content-reminder'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Permissions.csv');
    }

    // ---------------------------------------------------------------
    // New records
    // ---------------------------------------------------------------

    #[Test]
    public function newReminderGetsCreatorOfCurrentUserAndIsAlwaysOpen(): void
    {
        $dataHandler = $this->runDataHandler(self::EDITOR, data: [
            Reminder::TABLE => ['NEW1' => ['pid' => self::PAGE_EDITABLE, 'title' => 'New', 'creator' => self::LEAD, 'status' => 1]],
        ]);

        $record = $this->getReminder($dataHandler->substNEWwithIDs['NEW1']);
        self::assertSame(self::EDITOR, (int)$record['creator']);
        self::assertSame(0, (int)$record['status']);
        self::assertSame([], $dataHandler->errorLog);
    }

    #[Test]
    public function editorMayAssignNewReminderToHimself(): void
    {
        $dataHandler = $this->runDataHandler(self::EDITOR, data: [
            Reminder::TABLE => ['NEW1' => ['pid' => self::PAGE_EDITABLE, 'title' => 'New', 'assignee' => self::EDITOR]],
        ]);

        self::assertSame(self::EDITOR, (int)$this->getReminder($dataHandler->substNEWwithIDs['NEW1'])['assignee']);
    }

    #[Test]
    public function newReminderAssignedToColleagueWithoutPermissionIsSavedUnassigned(): void
    {
        $dataHandler = $this->runDataHandler(self::EDITOR, data: [
            Reminder::TABLE => ['NEW1' => ['pid' => self::PAGE_EDITABLE, 'title' => 'New', 'assignee' => self::COLLEAGUE]],
        ]);

        self::assertSame(0, (int)$this->getReminder($dataHandler->substNEWwithIDs['NEW1'])['assignee']);
        self::assertCount(1, $dataHandler->errorLog);
    }

    #[Test]
    public function distributorMayAssignNewReminderToColleague(): void
    {
        $dataHandler = $this->runDataHandler(self::DISTRIBUTOR, data: [
            Reminder::TABLE => ['NEW1' => ['pid' => self::PAGE_EDITABLE, 'title' => 'New', 'assignee' => self::COLLEAGUE]],
        ]);

        self::assertSame(self::COLLEAGUE, (int)$this->getReminder($dataHandler->substNEWwithIDs['NEW1'])['assignee']);
        self::assertSame([], $dataHandler->errorLog);
        self::assertSame([], $this->getFlashMessages());
    }

    #[Test]
    public function reminderCannotBeCreatedOnPageWithoutEditContentPermission(): void
    {
        $dataHandler = $this->runDataHandler(self::EDITOR, data: [
            Reminder::TABLE => ['NEW1' => ['pid' => self::PAGE_READ_ONLY, 'title' => 'New']],
        ]);

        self::assertArrayNotHasKey('NEW1', $dataHandler->substNEWwithIDs);
        self::assertNotSame([], $dataHandler->errorLog);
    }

    #[Test]
    public function assigningPersonWithoutPageAccessShowsWarningButSaves(): void
    {
        $dataHandler = $this->runDataHandler(self::DISTRIBUTOR, data: [
            Reminder::TABLE => ['NEW1' => ['pid' => self::PAGE_EDITABLE, 'title' => 'Prices', 'assignee' => self::OUTSIDER]],
        ]);

        self::assertSame(self::OUTSIDER, (int)$this->getReminder($dataHandler->substNEWwithIDs['NEW1'])['assignee']);
        $messages = $this->getFlashMessages();
        self::assertCount(1, $messages);
        self::assertSame(ContextualFeedbackSeverity::WARNING, $messages[0]->getSeverity());
        self::assertStringContainsString('Otto Outsider', $messages[0]->getMessage());
        self::assertStringContainsString('Prices', $messages[0]->getMessage());
    }

    // ---------------------------------------------------------------
    // Updates
    // ---------------------------------------------------------------

    #[Test]
    public function assigneeMayEditContent(): void
    {
        $this->runDataHandler(self::EDITOR, data: [Reminder::TABLE => [3 => ['title' => 'Changed', 'due_date' => '2026-12-24']]]);

        $record = $this->getReminder(3);
        self::assertSame('Changed', $record['title']);
        self::assertSame('2026-12-24', $record['due_date']);
    }

    #[Test]
    public function creatorMayEditContent(): void
    {
        $this->runDataHandler(self::EDITOR, data: [Reminder::TABLE => [1 => ['title' => 'Changed']]]);

        self::assertSame('Changed', $this->getReminder(1)['title']);
    }

    #[Test]
    public function editorMayNotEditForeignReminder(): void
    {
        $dataHandler = $this->runDataHandler(self::EDITOR, data: [Reminder::TABLE => [2 => ['title' => 'Changed', 'notes' => 'x']]]);

        $record = $this->getReminder(2);
        self::assertSame('Created by colleague, unassigned', $record['title']);
        self::assertSame('', (string)$record['notes']);
        self::assertCount(1, $dataHandler->errorLog);
    }

    #[Test]
    public function leadMayEditForeignReminder(): void
    {
        $this->runDataHandler(self::LEAD, data: [Reminder::TABLE => [2 => ['title' => 'Changed']]]);

        self::assertSame('Changed', $this->getReminder(2)['title']);
    }

    #[Test]
    public function statusAndCreatorCannotBeChangedManually(): void
    {
        $dataHandler = $this->runDataHandler(self::ADMIN, data: [Reminder::TABLE => [3 => ['status' => 1, 'creator' => self::ADMIN]]]);

        $record = $this->getReminder(3);
        self::assertSame(0, (int)$record['status']);
        self::assertSame(self::COLLEAGUE, (int)$record['creator']);
        self::assertCount(2, $dataHandler->errorLog);
    }

    #[Test]
    public function trustedOperationMayChangeStatus(): void
    {
        $this->get(TrustedOperation::class)->run(
            fn() => $this->runDataHandler(self::EDITOR, data: [Reminder::TABLE => [3 => ['status' => 1]]])
        );

        self::assertSame(1, (int)$this->getReminder(3)['status']);
    }

    #[Test]
    public function editorMayTakeOverUnassignedReminderWithoutEditPermission(): void
    {
        $this->runDataHandler(self::EDITOR, data: [Reminder::TABLE => [2 => ['assignee' => self::EDITOR]]]);

        self::assertSame(self::EDITOR, (int)$this->getReminder(2)['assignee']);
    }

    #[Test]
    public function editorMayNotTakeOverReminderOfColleague(): void
    {
        $dataHandler = $this->runDataHandler(self::COLLEAGUE, data: [Reminder::TABLE => [3 => ['assignee' => self::COLLEAGUE]]]);

        self::assertSame(self::EDITOR, (int)$this->getReminder(3)['assignee']);
        self::assertCount(1, $dataHandler->errorLog);
    }

    #[Test]
    public function assigneeMayPause(): void
    {
        $this->runDataHandler(self::EDITOR, data: [Reminder::TABLE => [3 => ['hidden' => 1]]]);
        self::assertSame(1, (int)$this->getReminder(3)['hidden']);
    }

    #[Test]
    public function foreignEditorMayNotPause(): void
    {
        $dataHandler = $this->runDataHandler(self::EDITOR, data: [Reminder::TABLE => [2 => ['hidden' => 1]]]);

        self::assertSame(0, (int)$this->getReminder(2)['hidden']);
        self::assertCount(1, $dataHandler->errorLog);
    }

    // ---------------------------------------------------------------
    // Commands
    // ---------------------------------------------------------------

    #[Test]
    public function onlyCreatorOrLeadMayDelete(): void
    {
        // Editor is assignee, but not creator of reminder 3
        $dataHandler = $this->runDataHandler(self::EDITOR, cmd: [Reminder::TABLE => [3 => ['delete' => 1]]]);
        self::assertSame(0, (int)$this->getReminder(3)['deleted']);
        self::assertCount(1, $dataHandler->errorLog);

        $this->runDataHandler(self::LEAD, cmd: [Reminder::TABLE => [3 => ['delete' => 1]]]);
        self::assertSame(1, (int)$this->getReminder(3)['deleted']);
    }

    #[Test]
    public function copyBelongsToCopyingUserAndDropsAssignmentHeMayNotMake(): void
    {
        // Reminder 1 is assigned to the colleague; the editor may not assign others
        $dataHandler = $this->runDataHandler(self::EDITOR, cmd: [Reminder::TABLE => [1 => ['copy' => self::PAGE_EDITABLE]]]);

        $copyUid = $dataHandler->copyMappingArray_merged[Reminder::TABLE][1] ?? 0;
        self::assertGreaterThan(0, $copyUid);
        $copy = $this->getReminder($copyUid);
        self::assertSame(self::EDITOR, (int)$copy['creator']);
        self::assertSame(0, (int)$copy['assignee']);
        self::assertSame(1, (int)$copy['t3_origuid']);
        self::assertSame([], $dataHandler->errorLog);
    }

    #[Test]
    public function editorMayNotMoveForeignReminder(): void
    {
        $dataHandler = $this->runDataHandler(self::EDITOR, cmd: [Reminder::TABLE => [2 => ['move' => 1]]]);

        self::assertSame(self::PAGE_EDITABLE, (int)$this->getReminder(2)['pid']);
        self::assertCount(1, $dataHandler->errorLog);
    }

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $data
     * @param array<string, array<int|string, array<string, mixed>>> $cmd
     */
    private function runDataHandler(int $userUid, array $data = [], array $cmd = []): DataHandler
    {
        $backendUser = $this->setUpBackendUser($userUid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, $cmd, $backendUser);
        $dataHandler->process_datamap();
        $dataHandler->process_cmdmap();
        return $dataHandler;
    }

    /**
     * @return array<string, mixed>
     */
    private function getReminder(int $uid): array
    {
        // Without restrictions: paused (hidden) and deleted records must be readable, too
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable(Reminder::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $record = $queryBuilder
            ->select('*')
            ->from(Reminder::TABLE)
            ->where($queryBuilder->expr()->eq('uid', $uid))
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($record, 'Reminder ' . $uid . ' not found');
        return $record;
    }

    /**
     * @return list<\TYPO3\CMS\Core\Messaging\FlashMessage>
     */
    private function getFlashMessages(): array
    {
        return array_values($this->get(FlashMessageService::class)->getMessageQueueByIdentifier()->getAllMessages());
    }
}
