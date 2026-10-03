<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Command;

use Formatsoft\ContentReminder\Mail\WeeklyMailBuilder;
use Formatsoft\ContentReminder\Mail\WeeklyMailSender;
use Formatsoft\ContentReminder\Mail\WeeklyMailSettings;
use Formatsoft\ContentReminder\Service\Clock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Sends the weekly reminder mails (concept 6.2). Meant to run daily via the scheduler:
 * each site is processed on its configured weekday, at most once per day.
 */
#[AsCommand(
    name: 'content-reminder:send-weekly-mail',
    description: 'Send the weekly mail with due reminders to the responsible persons of each site.',
)]
final class SendWeeklyMailCommand extends Command
{
    private const REGISTRY_NAMESPACE = 'tx_contentreminder';

    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly WeeklyMailBuilder $builder,
        private readonly WeeklyMailSender $sender,
        private readonly Registry $registry,
        private readonly Clock $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('site', null, InputOption::VALUE_REQUIRED, 'Only process the site with this identifier')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Send regardless of the configured weekday and of mails already sent today')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list the mails that would be sent');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $today = $this->clock->today();
        $force = (bool)$input->getOption('force');
        $dryRun = (bool)$input->getOption('dry-run');
        $onlySite = (string)($input->getOption('site') ?? '');
        $failures = 0;

        foreach ($this->siteFinder->getAllSites() as $site) {
            if ($onlySite !== '' && $site->getIdentifier() !== $onlySite) {
                continue;
            }
            $settings = WeeklyMailSettings::fromSite($site);
            $registryKey = 'weeklyMail.lastSent.' . $site->getIdentifier();

            if (!$force) {
                if (!$settings->enabled) {
                    $io->writeln(sprintf('<comment>%s</comment>: weekly mail disabled', $site->getIdentifier()), OutputInterface::VERBOSITY_VERBOSE);
                    continue;
                }
                if (!$settings->isSendDay($today)) {
                    $io->writeln(sprintf('<comment>%s</comment>: not the send day (%s)', $site->getIdentifier(), $settings->weekday), OutputInterface::VERBOSITY_VERBOSE);
                    continue;
                }
                if ($this->registry->get(self::REGISTRY_NAMESPACE, $registryKey) === $today->format('Y-m-d')) {
                    $io->writeln(sprintf('<comment>%s</comment>: already sent today', $site->getIdentifier()), OutputInterface::VERBOSITY_VERBOSE);
                    continue;
                }
            }

            $mails = $this->builder->build(
                $site->getRootPageId(),
                $this->getSiteTitle($site),
                $site->getDefaultLanguage()->getLocale()->getLanguageCode(),
                $settings,
                $today
            );

            $io->section(sprintf('%s: %d mail(s)', $site->getIdentifier(), count($mails)));
            if ($mails !== [] && $settings->backendUrl === '') {
                $io->warning(sprintf(
                    'The base of site "%s" has no domain, so the mails contain no links to the backend. Set "contentReminder.backendUrl" in the site settings.',
                    $site->getIdentifier()
                ));
            }
            foreach ($mails as $mail) {
                $line = sprintf('%s <%s> – %d overdue, %d due', $mail->recipientName ?: '-', $mail->recipientEmail, count($mail->overdue), count($mail->upcoming));
                if ($dryRun) {
                    $io->writeln('[dry-run] ' . $line);
                    continue;
                }
                try {
                    $this->sender->send($mail, $settings, $this->getBaseUrl($site, $settings));
                    $io->writeln('sent: ' . $line);
                } catch (\Throwable $e) {
                    $failures++;
                    $io->error('failed: ' . $line . ' – ' . $e->getMessage());
                }
            }

            if (!$dryRun) {
                $this->registry->set(self::REGISTRY_NAMESPACE, $registryKey, $today->format('Y-m-d'));
            }
        }

        return $failures === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Absolute URL of the site for the mail layout (logo, "sent from URL"). Sites with a
     * relative base (e.g. "/") fall back to the domain of the configured backend URL.
     */
    private function getBaseUrl(Site $site, WeeklyMailSettings $settings): string
    {
        $base = $site->getBase();
        if ($base->getHost() !== '') {
            return (string)$base;
        }
        $backendUrl = parse_url($settings->backendUrl);
        if (!is_array($backendUrl) || empty($backendUrl['host'])) {
            return '';
        }
        return ($backendUrl['scheme'] ?? 'https') . '://' . $backendUrl['host'] . (isset($backendUrl['port']) ? ':' . $backendUrl['port'] : '') . '/';
    }

    private function getSiteTitle(Site $site): string
    {
        $title = trim((string)($site->getConfiguration()['websiteTitle'] ?? ''));
        if ($title === '') {
            $title = trim($site->getDefaultLanguage()->getWebsiteTitle());
        }
        return $title !== '' ? $title : $site->getIdentifier();
    }
}
