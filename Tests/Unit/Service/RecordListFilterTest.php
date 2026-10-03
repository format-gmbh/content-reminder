<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Tests\Unit\Service;

use Formatsoft\ContentReminder\Service\Clock;
use Formatsoft\ContentReminder\Service\RecordListFilter;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class RecordListFilterTest extends UnitTestCase
{
    private RecordListFilter $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new RecordListFilter($this->createStub(Clock::class));
    }

    #[Test]
    public function stateDefaultsToNoFilter(): void
    {
        $user = $this->createStub(BackendUserAuthentication::class);
        $user->method('getModuleData')->willReturn(null);

        self::assertSame(['mine' => false, 'due' => false], $this->subject->getState(new ServerRequest('https://example.test/'), $user));
    }

    #[Test]
    public function toggleParameterChangesStoredStateAndPersistsIt(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('getModuleData')->willReturn(['mine' => true, 'due' => false]);
        $user->expects($this->once())->method('pushModuleData')->with('content_reminder/records_filter', ['mine' => true, 'due' => true]);

        $request = (new ServerRequest('https://example.test/'))->withQueryParams([RecordListFilter::PARAMETER => ['due' => '1']]);

        self::assertSame(['mine' => true, 'due' => true], $this->subject->getState($request, $user));
    }

    #[Test]
    public function stateIsNotPersistedWithoutToggleParameter(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('getModuleData')->willReturn(['mine' => true]);
        $user->expects($this->never())->method('pushModuleData');

        self::assertSame(['mine' => true, 'due' => false], $this->subject->getState(new ServerRequest('https://example.test/'), $user));
    }

    #[Test]
    public function toggleUriReplacesPreviousToggleAndKeepsOtherParameters(): void
    {
        $uri = new Uri('https://example.test/typo3/module/content/records?id=6&table=tx_contentreminder_reminder&contentReminderFilter%5Bmine%5D=1');

        $result = $this->subject->buildToggleUri($uri, 'due', true);

        parse_str($result->getQuery(), $parameters);
        self::assertSame('6', $parameters['id']);
        self::assertSame('tx_contentreminder_reminder', $parameters['table']);
        self::assertSame(['due' => '1'], $parameters[RecordListFilter::PARAMETER]);
        self::assertSame('/typo3/module/content/records', $result->getPath());
    }
}
