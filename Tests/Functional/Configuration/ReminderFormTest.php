<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Tests\Functional\Configuration;

use Formatsoft\ContentReminder\Domain\Model\Reminder;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Form\FormDataCompiler;
use TYPO3\CMS\Backend\Form\FormDataGroup\TcaDatabaseRecord;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Builds the editing form data like FormEngine does, so that errors in the TCA
 * (e.g. invalid foreign_table_where) are found - saving via DataHandler alone does not.
 */
final class ReminderFormTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['dashboard'];

    protected array $testExtensionsToLoad = ['formatsoft/content-reminder'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Permissions.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    #[Test]
    public function newReminderFormListsActiveBackendUsersAsAssignees(): void
    {
        $result = $this->compile(['command' => 'new', 'vanillaUid' => 2]);

        $items = array_column($result['processedTca']['columns']['assignee']['config']['items'], 'label', 'value');
        self::assertArrayHasKey(0, $items);
        self::assertSame('editor', $items[2] ?? null);
        self::assertSame('colleague', $items[3] ?? null);
        self::assertSame(2, (int)$result['databaseRow']['pid']);
    }

    #[Test]
    public function editFormCanBeBuiltForExistingReminder(): void
    {
        $result = $this->compile(['command' => 'edit', 'vanillaUid' => 3]);

        self::assertSame('Created by colleague, assigned to editor', $result['databaseRow']['title']);
        self::assertSame(['2'], array_map(strval(...), (array)$result['databaseRow']['assignee']));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function compile(array $input): array
    {
        $request = (new ServerRequest('https://example.test/typo3/record/edit'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams(['HTTP_HOST' => 'example.test', 'HTTPS' => 'on']));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return GeneralUtility::makeInstance(FormDataCompiler::class)->compile(
            ['request' => $request, 'tableName' => Reminder::TABLE] + $input,
            GeneralUtility::makeInstance(TcaDatabaseRecord::class)
        );
    }
}
