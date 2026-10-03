<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Tests\Unit\Service;

use Formatsoft\ContentReminder\Domain\Model\Reminder;
use Formatsoft\ContentReminder\Service\ReminderPermissionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Covers the permission matrix of concept chapter 4.4.
 */
final class ReminderPermissionServiceTest extends UnitTestCase
{
    private const ME = 10;
    private const COLLEAGUE = 20;
    private const OTHER = 30;

    private const PAGE = ['uid' => 1, 'pid' => 0, 'perms_userid' => 1, 'perms_groupid' => 1, 'perms_user' => 31, 'perms_group' => 31, 'perms_everybody' => 0];

    private ReminderPermissionService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new ReminderPermissionService();
    }

    // ---------------------------------------------------------------
    // Levels 1 and 2: table and page permissions
    // ---------------------------------------------------------------

    #[Test]
    public function readerCanViewButNotCreate(): void
    {
        $reader = $this->user(tables: ['select'], pagePerms: Permission::PAGE_SHOW);

        self::assertTrue($this->subject->canView($reader, self::PAGE));
        self::assertFalse($this->subject->canCreate($reader, self::PAGE));
    }

    #[Test]
    public function nothingIsAllowedWithoutTablePermissions(): void
    {
        $user = $this->user(tables: [], pagePerms: Permission::ALL, options: ['assignOthers', 'manageAll']);
        $reminder = $this->reminder(assignee: self::ME, creator: self::ME);

        self::assertFalse($this->subject->canView($user, self::PAGE));
        self::assertFalse($this->subject->canCreate($user, self::PAGE));
        self::assertFalse($this->subject->canEdit($user, $reminder, self::PAGE));
        self::assertFalse($this->subject->canComplete($user, $reminder, self::PAGE));
        self::assertFalse($this->subject->canDelete($user, $reminder, self::PAGE));
    }

    #[Test]
    public function nothingIsAllowedWithoutPageAccess(): void
    {
        // Pages outside the web mounts also result in no permissions (calcPerms returns 0)
        $user = $this->user(tables: ['select', 'modify'], pagePerms: Permission::NOTHING, options: ['assignOthers', 'manageAll']);
        $reminder = $this->reminder(assignee: self::ME, creator: self::ME);

        self::assertFalse($this->subject->canView($user, self::PAGE));
        self::assertFalse($this->subject->canCreate($user, self::PAGE));
        self::assertFalse($this->subject->canEdit($user, $reminder, self::PAGE));
        self::assertFalse($this->subject->canTakeOver($user, $this->reminder(), self::PAGE));
        self::assertFalse($this->subject->canComplete($user, $reminder, self::PAGE));
        self::assertFalse($this->subject->canDelete($user, $reminder, self::PAGE));
    }

    #[Test]
    public function showPermissionAloneDoesNotAllowWriting(): void
    {
        $user = $this->user(tables: ['select', 'modify'], pagePerms: Permission::PAGE_SHOW);

        self::assertTrue($this->subject->canView($user, self::PAGE));
        self::assertFalse($this->subject->canCreate($user, self::PAGE));
        self::assertFalse($this->subject->canComplete($user, $this->reminder(assignee: self::ME), self::PAGE));
    }

    #[Test]
    public function userWithoutUidGetsNothing(): void
    {
        $user = $this->user(uid: null, tables: ['select', 'modify'], pagePerms: Permission::ALL);

        self::assertFalse($this->subject->canView($user, self::PAGE));
        self::assertFalse($this->subject->canCreate($user, self::PAGE));
    }

    // ---------------------------------------------------------------
    // Assignment
    // ---------------------------------------------------------------

    public static function assignNewDataProvider(): \Generator
    {
        yield 'editor: unassigned' => [[], 0, true];
        yield 'editor: oneself' => [[], self::ME, true];
        yield 'editor: colleague' => [[], self::COLLEAGUE, false];
        yield 'manageAll alone: colleague' => [['manageAll'], self::COLLEAGUE, false];
        yield 'assignOthers: colleague' => [['assignOthers'], self::COLLEAGUE, true];
    }

    /**
     * @param list<string> $options
     */
    #[Test]
    #[DataProvider('assignNewDataProvider')]
    public function canAssignNew(array $options, int $assignee, bool $expected): void
    {
        self::assertSame($expected, $this->subject->canAssignNew($this->editor($options), self::PAGE, $assignee));
    }

    public static function changeAssigneeDataProvider(): \Generator
    {
        // [options, current assignee, new assignee, expected]
        yield 'unchanged assignee' => [[], self::COLLEAGUE, self::COLLEAGUE, true];
        yield 'editor takes over unassigned' => [[], 0, self::ME, true];
        yield 'editor takes over from colleague' => [[], self::COLLEAGUE, self::ME, false];
        yield 'assignOthers takes over from colleague' => [['assignOthers'], self::COLLEAGUE, self::ME, true];
        yield 'manageAll takes over from colleague' => [['manageAll'], self::COLLEAGUE, self::ME, true];
        yield 'assignee gives reminder back' => [[], self::ME, 0, true];
        yield 'editor unassigns colleague' => [[], self::COLLEAGUE, 0, false];
        yield 'assignOthers unassigns colleague' => [['assignOthers'], self::COLLEAGUE, 0, true];
        yield 'manageAll unassigns colleague' => [['manageAll'], self::COLLEAGUE, 0, true];
        yield 'editor passes own reminder to colleague' => [[], self::ME, self::COLLEAGUE, false];
        yield 'assignOthers passes to colleague' => [['assignOthers'], self::ME, self::COLLEAGUE, true];
        yield 'assignOthers reassigns between colleagues' => [['assignOthers'], self::COLLEAGUE, self::OTHER, true];
        yield 'manageAll alone cannot reassign to colleague' => [['manageAll'], self::COLLEAGUE, self::OTHER, false];
    }

    /**
     * @param list<string> $options
     */
    #[Test]
    #[DataProvider('changeAssigneeDataProvider')]
    public function canChangeAssignee(array $options, int $currentAssignee, int $newAssignee, bool $expected): void
    {
        $reminder = $this->reminder(assignee: $currentAssignee, creator: self::OTHER);

        self::assertSame($expected, $this->subject->canChangeAssignee($this->editor($options), $reminder, self::PAGE, $newAssignee));
    }

    public static function takeOverDataProvider(): \Generator
    {
        // [options, assignee, status, expected]
        yield 'editor: unassigned' => [[], 0, 0, true];
        yield 'editor: assigned to colleague' => [[], self::COLLEAGUE, 0, false];
        yield 'assignOthers: assigned to colleague' => [['assignOthers'], self::COLLEAGUE, 0, true];
        yield 'manageAll: assigned to colleague' => [['manageAll'], self::COLLEAGUE, 0, true];
        yield 'already mine' => [['assignOthers', 'manageAll'], self::ME, 0, false];
        yield 'done reminder' => [[], 0, 1, false];
    }

    /**
     * @param list<string> $options
     */
    #[Test]
    #[DataProvider('takeOverDataProvider')]
    public function canTakeOver(array $options, int $assignee, int $status, bool $expected): void
    {
        $reminder = $this->reminder(assignee: $assignee, status: $status);

        self::assertSame($expected, $this->subject->canTakeOver($this->editor($options), $reminder, self::PAGE));
    }

    // ---------------------------------------------------------------
    // Edit, complete, delete
    // ---------------------------------------------------------------

    public static function editDataProvider(): \Generator
    {
        // [options, assignee, creator, expected]
        yield 'creator' => [[], self::COLLEAGUE, self::ME, true];
        yield 'assignee' => [[], self::ME, self::COLLEAGUE, true];
        yield 'neither' => [[], self::COLLEAGUE, self::OTHER, false];
        yield 'neither, unassigned' => [[], 0, self::OTHER, false];
        yield 'assignOthers is not enough' => [['assignOthers'], self::COLLEAGUE, self::OTHER, false];
        yield 'manageAll' => [['manageAll'], self::COLLEAGUE, self::OTHER, true];
    }

    /**
     * @param list<string> $options
     */
    #[Test]
    #[DataProvider('editDataProvider')]
    public function canEdit(array $options, int $assignee, int $creator, bool $expected): void
    {
        $reminder = $this->reminder(assignee: $assignee, creator: $creator);

        self::assertSame($expected, $this->subject->canEdit($this->editor($options), $reminder, self::PAGE));
    }

    public static function completeDataProvider(): \Generator
    {
        // [options, assignee, status, expected]
        yield 'assignee' => [[], self::ME, 0, true];
        yield 'unassigned' => [[], 0, 0, true];
        yield 'assigned to colleague' => [[], self::COLLEAGUE, 0, false];
        yield 'assignOthers is not enough' => [['assignOthers'], self::COLLEAGUE, 0, false];
        yield 'manageAll' => [['manageAll'], self::COLLEAGUE, 0, true];
        yield 'already done' => [['manageAll'], self::ME, 1, false];
    }

    /**
     * @param list<string> $options
     */
    #[Test]
    #[DataProvider('completeDataProvider')]
    public function canComplete(array $options, int $assignee, int $status, bool $expected): void
    {
        $reminder = $this->reminder(assignee: $assignee, creator: self::OTHER, status: $status);

        self::assertSame($expected, $this->subject->canComplete($this->editor($options), $reminder, self::PAGE));
    }

    public static function deleteDataProvider(): \Generator
    {
        // [options, assignee, creator, expected]
        yield 'creator' => [[], self::COLLEAGUE, self::ME, true];
        yield 'assignee only' => [[], self::ME, self::COLLEAGUE, false];
        yield 'manageAll' => [['manageAll'], self::COLLEAGUE, self::OTHER, true];
    }

    /**
     * @param list<string> $options
     */
    #[Test]
    #[DataProvider('deleteDataProvider')]
    public function canDelete(array $options, int $assignee, int $creator, bool $expected): void
    {
        $reminder = $this->reminder(assignee: $assignee, creator: $creator);

        self::assertSame($expected, $this->subject->canDelete($this->editor($options), $reminder, self::PAGE));
    }

    // ---------------------------------------------------------------
    // Reopen, pause, resume follow "edit" plus state rules
    // ---------------------------------------------------------------

    #[Test]
    public function reopenRequiresDoneReminderAndEditPermission(): void
    {
        $editor = $this->editor();

        self::assertTrue($this->subject->canReopen($editor, $this->reminder(assignee: self::ME, status: 1), self::PAGE));
        self::assertFalse($this->subject->canReopen($editor, $this->reminder(assignee: self::ME, status: 0), self::PAGE));
        self::assertFalse($this->subject->canReopen($editor, $this->reminder(assignee: self::COLLEAGUE, creator: self::OTHER, status: 1), self::PAGE));
    }

    #[Test]
    public function pauseAndResumeDependOnPausedState(): void
    {
        $editor = $this->editor();
        $active = $this->reminder(assignee: self::ME);
        $paused = $this->reminder(assignee: self::ME, hidden: 1);

        self::assertTrue($this->subject->canPause($editor, $active, self::PAGE));
        self::assertFalse($this->subject->canResume($editor, $active, self::PAGE));
        self::assertFalse($this->subject->canPause($editor, $paused, self::PAGE));
        self::assertTrue($this->subject->canResume($editor, $paused, self::PAGE));
        self::assertFalse($this->subject->canPause($editor, $this->reminder(assignee: self::ME, status: 1), self::PAGE));
    }

    // ---------------------------------------------------------------
    // Admins
    // ---------------------------------------------------------------

    #[Test]
    public function adminMayDoEverythingButStateRulesStillApply(): void
    {
        $admin = $this->user(admin: true, tables: [], pagePerms: Permission::NOTHING);
        $foreign = $this->reminder(assignee: self::COLLEAGUE, creator: self::OTHER);

        self::assertTrue($this->subject->canView($admin, self::PAGE));
        self::assertTrue($this->subject->canAssignNew($admin, self::PAGE, self::COLLEAGUE));
        self::assertTrue($this->subject->canEdit($admin, $foreign, self::PAGE));
        self::assertTrue($this->subject->canChangeAssignee($admin, $foreign, self::PAGE, self::OTHER));
        self::assertTrue($this->subject->canTakeOver($admin, $foreign, self::PAGE));
        self::assertTrue($this->subject->canComplete($admin, $foreign, self::PAGE));
        self::assertTrue($this->subject->canDelete($admin, $foreign, self::PAGE));
        self::assertFalse($this->subject->canComplete($admin, $this->reminder(status: 1), self::PAGE));
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Editor role: select + modify on the table, show + edit content on the page.
     *
     * @param list<string> $options
     */
    private function editor(array $options = []): BackendUserAuthentication
    {
        return $this->user(
            tables: ['select', 'modify'],
            pagePerms: Permission::PAGE_SHOW | Permission::CONTENT_EDIT,
            options: $options
        );
    }

    /**
     * @param list<'select'|'modify'> $tables
     * @param list<string> $options custom options without the "content_reminder:" prefix
     */
    private function user(
        ?int $uid = self::ME,
        bool $admin = false,
        array $tables = [],
        int $pagePerms = Permission::NOTHING,
        array $options = [],
    ): BackendUserAuthentication {
        $user = $this->createStub(BackendUserAuthentication::class);
        $user->method('getUserId')->willReturn($uid);
        $user->method('isAdmin')->willReturn($admin);
        $user->method('doesUserHaveAccess')->willReturnCallback(
            static fn(array $row, int $perms): bool => ($pagePerms & $perms) === $perms
        );
        $user->method('check')->willReturnCallback(
            static fn(string $type, string $value): bool => match ($type) {
                'tables_select' => $value === Reminder::TABLE && in_array('select', $tables, true),
                'tables_modify' => $value === Reminder::TABLE && in_array('modify', $tables, true),
                'custom_options' => in_array(substr($value, strlen('content_reminder:')), $options, true)
                    && str_starts_with($value, 'content_reminder:'),
                default => false,
            }
        );
        return $user;
    }

    private function reminder(int $assignee = 0, int $creator = 0, int $status = 0, int $hidden = 0): Reminder
    {
        return Reminder::fromDatabaseRow([
            'uid' => 1,
            'pid' => 1,
            'title' => 'Test',
            'assignee' => $assignee,
            'creator' => $creator,
            'status' => $status,
            'hidden' => $hidden,
        ]);
    }
}
