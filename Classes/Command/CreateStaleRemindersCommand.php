<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Command;

use Formatsoft\ContentReminder\Service\Clock;
use Formatsoft\ContentReminder\StaleContent\StaleContentSettings;
use Formatsoft\ContentReminder\StaleContent\StalePageFinder;
use Formatsoft\ContentReminder\StaleContent\StaleReminderCreator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Creates reminders for pages whose content has not been changed for the configured
 * number of months (concept 6.3). Disabled by default (site setting
 * contentReminder.stale.enabled), meant to run e.g. weekly via the scheduler.
 */
#[AsCommand(
    name: 'content-reminder:create-stale-reminders',
    description: 'Create reminders "Outdated content – please review" for pages without changes for a long time.',
)]
final class CreateStaleRemindersCommand extends Command
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly StalePageFinder $finder,
        private readonly StaleReminderCreator $creator,
        private readonly Clock $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('site', null, InputOption::VALUE_REQUIRED, 'Only process the site with this identifier')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of reminders per site (default: site setting contentReminder.stale.limit)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list the pages, also for sites where the function is disabled');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $today = $this->clock->today();
        $dryRun = (bool)$input->getOption('dry-run');
        $onlySite = (string)($input->getOption('site') ?? '');
        $limitOption = $input->getOption('limit');
        $user = null;
        $failures = 0;

        foreach ($this->siteFinder->getAllSites() as $site) {
            if ($onlySite !== '' && $site->getIdentifier() !== $onlySite) {
                continue;
            }
            $settings = StaleContentSettings::fromSite($site);
            if (!$settings->enabled && !$dryRun) {
                $io->writeln(sprintf('<comment>%s</comment>: outdated content check disabled', $site->getIdentifier()), OutputInterface::VERBOSITY_VERBOSE);
                continue;
            }
            $limit = $limitOption !== null ? max(0, (int)$limitOption) : $settings->limit;
            $pages = $this->finder->find($site->getRootPageId(), $settings, $today, $limit);

            $io->section(sprintf(
                '%s: %d page(s) without changes since %s%s',
                $site->getIdentifier(),
                count($pages),
                $settings->getThreshold($today)->format('Y-m-d'),
                $settings->enabled ? '' : ' (disabled)'
            ));
            if ($pages === []) {
                continue;
            }
            if ($dryRun) {
                $io->table(
                    ['Page', 'Title', 'Last change'],
                    array_map(static fn($page): array => [$page->uid, $page->title, $page->lastChange->format('Y-m-d')], $pages)
                );
                continue;
            }

            $user ??= $this->getBackendUser();
            $languageCode = $site->getDefaultLanguage()->getLocale()->getLanguageCode();
            foreach ($pages as $page) {
                $line = sprintf('page %d "%s", last change %s', $page->uid, $page->title, $page->lastChange->format('Y-m-d'));
                try {
                    $uid = $this->creator->create($page, $languageCode, $user);
                    $io->writeln(sprintf('created reminder %d: %s', $uid, $line));
                } catch (\Throwable $e) {
                    $failures++;
                    $io->error('failed: ' . $line . ' – ' . $e->getMessage());
                }
            }
        }

        return $failures === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * The CLI backend user (_cli_, admin) writes the reminders through the DataHandler.
     * $GLOBALS['BE_USER'] exists on the command line, but is only logged in by this call.
     */
    private function getBackendUser(): BackendUserAuthentication
    {
        Bootstrap::initializeBackendAuthentication();
        return $GLOBALS['BE_USER'];
    }
}
