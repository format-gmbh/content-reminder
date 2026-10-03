<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Tests\Functional\Mail;

use Formatsoft\ContentReminder\Domain\Repository\ReminderRepository;
use Formatsoft\ContentReminder\Mail\SitePageCollector;
use Formatsoft\ContentReminder\Mail\WeeklyMail;
use Formatsoft\ContentReminder\Mail\WeeklyMailBuilder;
use Formatsoft\ContentReminder\Mail\WeeklyMailSender;
use Formatsoft\ContentReminder\Mail\WeeklyMailSettings;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Weekly mail (concept 6.2): who gets which reminders, and how the mail looks.
 */
final class WeeklyMailTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['dashboard'];

    protected array $testExtensionsToLoad = ['formatsoft/content-reminder'];

    private WeeklyMailBuilder $builder;

    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/WeeklyMail.csv');
        $connectionPool = $this->getConnectionPool();
        $this->builder = new WeeklyMailBuilder(
            new SitePageCollector($connectionPool),
            new ReminderRepository($connectionPool),
            $connectionPool,
            new NullLogger(),
        );
        $this->today = new \DateTimeImmutable('2026-10-03 06:00:00');
    }

    #[Test]
    public function editorGetsOverdueAndUpcomingRemindersOfTheSiteOnly(): void
    {
        $mail = $this->mailFor('eddi@example.org', $this->build());

        self::assertSame('Eddi Editor', $mail->recipientName);
        self::assertSame('de', $mail->language);
        self::assertSame(['Overdue for editor'], array_column($mail->overdue, 'title'));
        // No due date first; within 7 days; hidden pages count, deleted pages and other sites do not
        self::assertSame(['No date on hidden page', 'Due in 5 days'], array_column($mail->upcoming, 'title'));
        self::assertSame('30.09.2026', $mail->overdue[0]['dueDate']);
        self::assertSame('Prices', $mail->overdue[0]['pageTitle']);
        self::assertSame('https://example.org/typo3/module/web/layout?id=2', $mail->overdue[0]['url']);
    }

    #[Test]
    public function lookaheadDaysAreConfigurable(): void
    {
        $mail = $this->mailFor('eddi@example.org', $this->build(new WeeklyMailSettings(lookaheadDays: 14, backendUrl: '')));

        self::assertContains('Due in 10 days', array_column($mail->upcoming, 'title'));
        self::assertSame('', $mail->upcoming[0]['url']);
    }

    #[Test]
    public function usersWithoutEmailOptedOutOrDisabledGetNoMail(): void
    {
        $recipients = array_map(static fn(WeeklyMail $mail): string => $mail->recipientEmail, $this->build());

        sort($recipients);
        self::assertSame(['eddi@example.org', 'emma@example.org'], $recipients);
    }

    #[Test]
    public function unassignedRemindersGoToTheCollectiveAddressInSiteLanguage(): void
    {
        $mails = $this->build(new WeeklyMailSettings(unassignedRecipient: 'team@example.org'), 'de');
        $mail = $this->mailFor('team@example.org', $mails);

        self::assertTrue($mail->unassignedDigest);
        self::assertSame('de', $mail->language);
        self::assertSame(['Unassigned'], array_column($mail->upcoming, 'title'));
    }

    #[Test]
    public function unknownLanguagesFallBackToEnglish(): void
    {
        self::assertSame('en', $this->mailFor('emma@example.org', $this->build())->language);
    }

    #[Test]
    public function mailIsRenderedInTheLanguageOfTheRecipient(): void
    {
        $sender = new WeeklyMailSender($this->createStub(MailerInterface::class), $this->get(LanguageServiceFactory::class));
        $settings = new WeeklyMailSettings(fromAddress: 'cms@example.org', fromName: 'CMS', backendUrl: 'https://example.org/typo3');

        $german = $sender->createEmail($this->mailFor('eddi@example.org', $this->build($settings)), $settings, 'https://example.org/');
        self::assertSame('Example: 3 fällige Erinnerung(en)', $german->getSubject());
        self::assertSame('cms@example.org', $german->getFrom()[0]->getAddress());
        $text = (string)$german->getTextBody(true);
        self::assertStringContainsString('Hallo Eddi Editor', $text);
        self::assertStringContainsString('Überfällig (1)', $text);
        self::assertStringContainsString('fällig am 30.09.2026', $text);
        self::assertStringContainsString('https://example.org/typo3/module/web/layout?id=2', $text);
        self::assertStringContainsString('href="https://example.org/typo3/module/web/layout?id=2"', (string)$german->getHtmlBody(true));

        $english = $sender->createEmail($this->mailFor('emma@example.org', $this->build($settings)), $settings, 'https://example.org/');
        self::assertSame('Example: 1 reminder(s) due', $english->getSubject());
        self::assertStringContainsString('Hello Emma English', (string)$english->getTextBody(true));
    }

    /**
     * @return list<WeeklyMail>
     */
    private function build(?WeeklyMailSettings $settings = null, string $siteLanguage = 'en'): array
    {
        return $this->builder->build(
            1,
            'Example',
            $siteLanguage,
            $settings ?? new WeeklyMailSettings(backendUrl: 'https://example.org/typo3'),
            $this->today
        );
    }

    /**
     * @param list<WeeklyMail> $mails
     */
    private function mailFor(string $email, array $mails): WeeklyMail
    {
        foreach ($mails as $mail) {
            if ($mail->recipientEmail === $email) {
                return $mail;
            }
        }
        self::fail('No mail for ' . $email);
    }
}
