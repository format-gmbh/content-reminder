<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Tests\Functional\Controller;

use Formatsoft\ContentReminder\Controller\ReminderModuleController;
use Formatsoft\ContentReminder\Domain\Repository\ReminderLogRepository;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Backend module "Reminders" (concept 6.1): rendering, filters, stored filter state
 * and read access per page. Uses the real route and module definition.
 */
final class ReminderModuleControllerTest extends FunctionalTestCase
{
    private const ADMIN = 1;
    private const EDITOR = 2;
    private const OUTSIDER = 6;

    // Reminders on page 2, see Fixtures/Permissions.csv
    private const ASSIGNED_TO_COLLEAGUE = 'Created by editor, assigned to colleague';
    private const UNASSIGNED = 'Created by colleague, unassigned';
    private const ASSIGNED_TO_EDITOR = 'Created by colleague, assigned to editor';
    private const RECURRING_OVERDUE = 'Recurring, assigned to editor';

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
    public function showsRemindersOfSelectedPageAndSubpages(): void
    {
        $html = $this->render(self::EDITOR, ['id' => 1]);

        self::assertStringContainsString('Root', $html);
        foreach ([self::ASSIGNED_TO_COLLEAGUE, self::UNASSIGNED, self::ASSIGNED_TO_EDITOR, self::RECURRING_OVERDUE] as $title) {
            self::assertStringContainsString($title, $html);
        }
        self::assertStringContainsString('content-reminder-badge--overdue', $html);
    }

    #[Test]
    public function onlyThisPageExcludesSubpages(): void
    {
        $html = $this->render(self::EDITOR, ['id' => 1, 'depth' => 'page']);

        self::assertStringNotContainsString(self::ASSIGNED_TO_EDITOR, $html);
        self::assertStringContainsString('module.empty', $this->stripTranslations($html));
    }

    #[Test]
    public function stateAndAssigneeFiltersAreApplied(): void
    {
        $overdue = $this->render(self::EDITOR, ['id' => 1, 'state' => 'overdue']);
        self::assertStringContainsString(self::RECURRING_OVERDUE, $overdue);
        self::assertStringNotContainsString(self::UNASSIGNED, $overdue);

        $mine = $this->render(self::EDITOR, ['id' => 1, 'state' => 'open', 'assignee' => 'me']);
        self::assertStringContainsString(self::ASSIGNED_TO_EDITOR, $mine);
        self::assertStringContainsString(self::RECURRING_OVERDUE, $mine);
        self::assertStringNotContainsString(self::ASSIGNED_TO_COLLEAGUE, $mine);
        self::assertStringNotContainsString(self::UNASSIGNED, $mine);
    }

    #[Test]
    public function filtersAreStoredPerUser(): void
    {
        $this->render(self::EDITOR, ['id' => 1, 'state' => 'overdue']);

        // Next request without filter parameters uses the stored filter
        $html = $this->render(self::EDITOR, ['id' => 1]);
        self::assertStringContainsString(self::RECURRING_OVERDUE, $html);
        self::assertStringNotContainsString(self::UNASSIGNED, $html);
        self::assertSame('overdue', $GLOBALS['BE_USER']->getModuleData(ReminderModuleController::MODULE)['state'] ?? null);
    }

    #[Test]
    public function invalidFilterValuesFallBackToDefaults(): void
    {
        $html = $this->render(self::EDITOR, ['id' => 1, 'state' => 'nonsense', 'assignee' => 'robert\'); DROP TABLE']);

        self::assertStringContainsString(self::UNASSIGNED, $html);
        self::assertStringContainsString(self::ASSIGNED_TO_COLLEAGUE, $html);
    }

    #[Test]
    public function actionsFollowThePermissions(): void
    {
        $html = $this->render(self::EDITOR, ['id' => 1]);

        // Unassigned reminder (uid 2): editor may take it over
        self::assertMatchesRegularExpression('/data-content-reminder-action="takeOver" data-uid="2"/', $html);
        // Reminder of the colleague (uid 1): no take over, no complete for the editor
        self::assertDoesNotMatchRegularExpression('/data-content-reminder-action="takeOver" data-uid="1"/', $html);
        self::assertDoesNotMatchRegularExpression('/data-content-reminder-action="complete" data-uid="1"/', $html);
        // Own reminder (uid 3): complete
        self::assertMatchesRegularExpression('/data-content-reminder-action="complete" data-uid="3"/', $html);
    }

    #[Test]
    public function userWithoutAccessToThePageSeesNoReminders(): void
    {
        // The outsider's web mount is page 4; page 1 is not accessible and is ignored
        $html = $this->render(self::OUTSIDER, ['id' => 1]);

        // The module is rendered, it just contains no reminders
        self::assertStringContainsString('content-reminder-module-filter', $html);
        self::assertStringNotContainsString(self::UNASSIGNED, $html);
        self::assertStringNotContainsString(self::ASSIGNED_TO_EDITOR, $html);
    }

    #[Test]
    public function adminSeesAllPagesWithoutSelectedPage(): void
    {
        $html = $this->render(self::ADMIN, ['id' => 0]);

        self::assertStringContainsString(self::UNASSIGNED, $html);
        self::assertStringContainsString(self::RECURRING_OVERDUE, $html);
    }

    #[Test]
    public function archiveViewShowsCompletionsFilteredByPeriod(): void
    {
        $logRepository = new ReminderLogRepository($this->getConnectionPool());
        $logRepository->add(3, 'Completed last week', 2, 'Editable', self::EDITOR, 'Eddi Editor', new \DateTimeImmutable('2026-09-28 09:00'), null, null, 'checked');
        $logRepository->add(3, 'Completed last year', 2, 'Editable', self::EDITOR, 'Eddi Editor', new \DateTimeImmutable('2025-09-28 09:00'), null, null, '');

        $html = $this->render(self::EDITOR, ['id' => 1, 'view' => 'archive', 'period' => '90']);
        self::assertStringContainsString('Completed last week', $html);
        self::assertStringContainsString('checked', $html);
        self::assertStringNotContainsString('Completed last year', $html);

        $html = $this->render(self::EDITOR, ['id' => 1, 'view' => 'archive', 'period' => 'all']);
        self::assertStringContainsString('Completed last year', $html);
    }

    /**
     * @param array<string, int|string> $queryParams
     */
    private function render(int $userUid, array $queryParams): string
    {
        $backendUser = $this->setUpBackendUser($userUid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $module = $this->get(ModuleProvider::class)->getModule(ReminderModuleController::MODULE);
        self::assertNotNull($module);
        $route = GeneralUtility::makeInstance(Router::class)->getRoute(ReminderModuleController::MODULE);
        $uri = '/typo3/module/content/reminders?' . http_build_query($queryParams);

        $request = (new ServerRequest('https://example.test' . $uri))
            ->withQueryParams($queryParams)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams([
                'HTTP_HOST' => 'example.test',
                'HTTPS' => 'on',
                'REQUEST_URI' => $uri,
                'SCRIPT_NAME' => '/typo3/index.php',
            ]))
            ->withAttribute('route', $route)
            ->withAttribute('module', $module)
            ->withAttribute('moduleData', ModuleData::createFromModule($module, (array)$backendUser->getModuleData($module->getIdentifier())));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $response = $this->get(ReminderModuleController::class)->indexAction($request);
        self::assertSame(200, $response->getStatusCode());
        return (string)$response->getBody();
    }

    /**
     * Labels may be returned as keys if no translation is loaded; normalize for assertions.
     */
    private function stripTranslations(string $html): string
    {
        return str_replace(['No reminders match the filter.', 'Keine Erinnerungen passen zum Filter.'], 'module.empty', $html);
    }
}
