<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Tests\Functional\EventListener;

use Formatsoft\ContentReminder\Domain\Repository\ReminderLogRepository;
use Formatsoft\ContentReminder\EventListener\PageLayoutPanel;
use Formatsoft\ContentReminder\EventListener\PageTreeMarker;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Controller\Event\AfterPageTreeItemsPreparedEvent;
use TYPO3\CMS\Backend\Dto\Tree\Status\StatusInformation;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Page tree marker (concept 2.4.1) and page module panel (concept 2.4.2).
 */
final class BackendViewsTest extends FunctionalTestCase
{
    private const EDITOR = 2;
    private const COLLEAGUE = 3;

    protected array $coreExtensionsToLoad = ['dashboard'];

    protected array $testExtensionsToLoad = ['formatsoft/content-reminder'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Permissions.csv');
        GeneralUtility::makeInstance(Context::class)
            ->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('2026-10-03 10:30:00')));
    }

    #[Test]
    public function pageTreeMarksPageWithOverdueReminderOfCurrentUser(): void
    {
        $this->loginAs(self::EDITOR);
        $items = $this->dispatchPageTreeEvent([1, 2, 3]);

        self::assertArrayNotHasKey('statusInformation', $items[0]);
        self::assertArrayNotHasKey('statusInformation', $items[2]);

        $status = $items[1]['statusInformation'][0];
        self::assertInstanceOf(StatusInformation::class, $status);
        self::assertSame(PageTreeMarker::ICON_DUE_FOR_USER, $status->icon);
        // Reminder 4 was due on 01.10. -> overdue on 03.10.
        self::assertSame(ContextualFeedbackSeverity::WARNING, $status->severity);
        // Reminders 3 and 4 are due for the editor, reminder 2 is unassigned
        self::assertStringContainsString('2', $status->label);
        self::assertStringContainsString('1', $status->label);
    }

    #[Test]
    public function pageTreeShowsPersonIconIfOnlyUnassignedRemindersAreDue(): void
    {
        $this->loginAs(self::COLLEAGUE);
        // Reminder 1 is assigned to the colleague, so remove it to leave only the unassigned one
        $this->getConnectionPool()->getConnectionForTable('tx_contentreminder_reminder')->delete('tx_contentreminder_reminder', ['uid' => 1]);

        $status = $this->dispatchPageTreeEvent([2])[0]['statusInformation'][0];

        self::assertSame(PageTreeMarker::ICON_DUE_UNASSIGNED, $status->icon);
        self::assertSame(ContextualFeedbackSeverity::INFO, $status->severity);
    }

    #[Test]
    public function panelShowsDueRemindersOfCurrentUserAndUnassignedOnes(): void
    {
        $this->loginAs(self::EDITOR);
        $html = $this->renderPanel(2);

        self::assertStringContainsString('Created by colleague, assigned to editor', $html);
        self::assertStringContainsString('Recurring, assigned to editor', $html);
        self::assertStringContainsString('Created by colleague, unassigned', $html);
        self::assertStringContainsString('data-content-reminder-action="takeOver" data-uid="2"', $html);
        self::assertStringContainsString('data-content-reminder-action="complete" data-uid="4"', $html);
        // Reminder 1 belongs to the colleague and is only counted in the footer
        self::assertStringNotContainsString('Created by editor, assigned to colleague', $html);
        self::assertStringContainsString('content-reminder-item--overdue', $html);
    }

    #[Test]
    public function panelShowsOnlyHintLineIfNothingIsDueForCurrentUser(): void
    {
        $this->loginAs(self::EDITOR);
        $this->getConnectionPool()->getConnectionForTable('tx_contentreminder_reminder')
            ->update('tx_contentreminder_reminder', ['assignee' => self::COLLEAGUE], ['pid' => 2]);

        $html = $this->renderPanel(2);

        self::assertStringContainsString('content-reminder-hint', $html);
        self::assertStringNotContainsString('content-reminder-item', $html);
    }

    #[Test]
    public function panelShowsHistoryLinkIfArchiveEntriesExist(): void
    {
        $this->loginAs(self::EDITOR);
        $this->getConnectionPool()->getConnectionForTable('tx_contentreminder_reminder')
            ->delete('tx_contentreminder_reminder', ['pid' => 2]);
        self::assertSame('', $this->renderPanel(2));

        (new ReminderLogRepository($this->getConnectionPool()))->add(9, 'Old', 2, 'Editable', self::EDITOR, 'Eddi Editor', new \DateTimeImmutable(), null, null, '');

        self::assertStringContainsString('data-content-reminder-history=', $this->renderPanel(2));
    }

    private function loginAs(int $userUid): void
    {
        $backendUser = $this->setUpBackendUser($userUid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    /**
     * @param list<int> $pageUids
     * @return list<array<string, mixed>>
     */
    private function dispatchPageTreeEvent(array $pageUids): array
    {
        $items = array_map(
            static fn(int $uid): array => ['identifier' => (string)$uid, '_page' => BackendUtility::getRecord('pages', $uid)],
            $pageUids
        );
        // Constructor: (request, searchQuery, items) in TYPO3 v14, (request, items) in v13
        $request = new ServerRequest('https://example.test/typo3/ajax/page/tree/fetchData');
        $event = (new Typo3Version())->getMajorVersion() >= 14
            ? new AfterPageTreeItemsPreparedEvent($request, null, $items)
            : new AfterPageTreeItemsPreparedEvent($request, $items);
        $this->get(PageTreeMarker::class)($event);
        return array_values($event->getItems());
    }

    private function renderPanel(int $pageUid): string
    {
        $uri = '/typo3/module/web/layout?id=' . $pageUid;
        $request = (new ServerRequest('https://example.test' . $uri))
            ->withQueryParams(['id' => $pageUid])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams([
                'HTTP_HOST' => 'example.test',
                'HTTPS' => 'on',
                'REQUEST_URI' => $uri,
                'SCRIPT_NAME' => '/typo3/index.php',
            ]));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $this->get(PageLayoutPanel::class)->render($request, $GLOBALS['BE_USER'], BackendUtility::getRecord('pages', $pageUid));
    }
}
