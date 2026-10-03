<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Tests\Unit\Mail;

use Formatsoft\ContentReminder\Mail\WeeklyMailSettings;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class WeeklyMailSettingsTest extends UnitTestCase
{
    #[Test]
    public function sendDayMatchesConfiguredWeekday(): void
    {
        $settings = new WeeklyMailSettings(weekday: 'saturday');

        // 2026-10-03 is a Saturday
        self::assertTrue($settings->isSendDay(new \DateTimeImmutable('2026-10-03 23:59')));
        self::assertFalse($settings->isSendDay(new \DateTimeImmutable('2026-10-05')));
    }

    #[Test]
    public function pageModuleUrlIsBuiltFromBackendUrl(): void
    {
        self::assertSame(
            'https://example.org/typo3/module/web/layout?id=42',
            (new WeeklyMailSettings(backendUrl: 'https://example.org/typo3'))->getPageModuleUrl(42)
        );
        self::assertSame('', (new WeeklyMailSettings())->getPageModuleUrl(42));
    }
}
