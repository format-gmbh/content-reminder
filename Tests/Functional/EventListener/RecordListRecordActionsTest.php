<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Tests\Functional\EventListener;

use Formatsoft\ContentReminder\Domain\Model\Reminder;
use Formatsoft\ContentReminder\EventListener\RecordListRecordActions;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\RecordList\DatabaseRecordList;
use TYPO3\CMS\Backend\RecordList\Event\ModifyRecordListRecordActionsEvent;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\Components\ComponentGroup;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Domain\RecordFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Row actions in the list module (concept 2.4.3). The event API differs between
 * TYPO3 v13 (HTML strings, record as array) and v14 (components, RecordInterface);
 * the test builds the event matching the installed version.
 */
final class RecordListRecordActionsTest extends FunctionalTestCase
{
    private const EDITOR = 2;

    protected array $coreExtensionsToLoad = ['dashboard'];

    protected array $testExtensionsToLoad = ['formatsoft/content-reminder'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Permissions.csv');
        $backendUser = $this->setUpBackendUser(self::EDITOR);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    #[Test]
    public function ownReminderGetsCompleteActionAndPlaceholderForTakeOver(): void
    {
        // Reminder 3 is assigned to the editor
        $actions = $this->dispatch(3);

        self::assertStringContainsString('data-content-reminder-action="complete"', $actions['primary']['contentReminderComplete']);
        self::assertStringContainsString('disabled', $actions['primary']['contentReminderTakeOver']);
        self::assertStringContainsString('data-content-reminder-history=', $actions['secondary']['contentReminderHistory']);
        self::assertArrayNotHasKey('contentReminderReopen', $actions['secondary']);
    }

    #[Test]
    public function unassignedReminderGetsTakeOverAction(): void
    {
        $actions = $this->dispatch(2);

        self::assertStringContainsString('data-content-reminder-action="takeOver"', $actions['primary']['contentReminderTakeOver']);
        self::assertStringContainsString('data-content-reminder-action="complete"', $actions['primary']['contentReminderComplete']);
    }

    #[Test]
    public function primaryActionsAreAlwaysInTheSameOrder(): void
    {
        $own = array_keys($this->dispatch(3)['primary']);
        $unassigned = array_keys($this->dispatch(2)['primary']);

        self::assertSame(['contentReminderComplete', 'contentReminderTakeOver'], $own);
        self::assertSame($own, $unassigned);
    }

    #[Test]
    public function otherTablesAreNotTouched(): void
    {
        $actions = $this->dispatch(1, 'pages');

        self::assertSame([], $actions['primary']);
        self::assertSame([], $actions['secondary']);
    }

    /**
     * @return array{primary: array<string, string>, secondary: array<string, string>} rendered actions by name
     */
    private function dispatch(int $uid, string $table = Reminder::TABLE): array
    {
        $row = BackendUtility::getRecord($table, $uid);
        self::assertIsArray($row);
        $recordList = $this->createStub(DatabaseRecordList::class);
        $listener = $this->get(RecordListRecordActions::class);

        if ((new Typo3Version())->getMajorVersion() < 14) {
            $event = new ModifyRecordListRecordActionsEvent(['primary' => [], 'secondary' => []], $table, $row, $recordList);
            $listener($event);
            return [
                'primary' => $event->getActionGroup('primary') ?? [],
                'secondary' => $event->getActionGroup('secondary') ?? [],
            ];
        }

        $primary = new ComponentGroup('primary');
        $secondary = new ComponentGroup('secondary');
        $record = $this->get(RecordFactory::class)->createRawRecord($table, $row);
        $listener(new ModifyRecordListRecordActionsEvent($primary, $secondary, $record, $recordList, new ServerRequest('https://example.test/')));

        // Empty slots are rendered like the core does it: as disabled placeholder
        $placeholder = $this->get(ComponentFactory::class)->createGenericButton()->setTag('span')->setClasses('disabled');
        $render = static fn(ComponentGroup $group): array => array_map(
            static fn($component): string => $component->render(),
            $group->getItems($placeholder)
        );
        return ['primary' => $render($primary), 'secondary' => $render($secondary)];
    }
}
