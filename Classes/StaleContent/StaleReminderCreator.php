<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\StaleContent;

use Formatsoft\ContentReminder\DataHandling\TrustedOperation;
use Formatsoft\ContentReminder\Domain\Model\Reminder;
use Formatsoft\ContentReminder\Domain\Model\ReminderOrigin;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Creates the reminder "Outdated content – please review" for a page (concept 6.3):
 * nobody responsible, no due date (due now), created by the system (E38).
 */
class StaleReminderCreator
{
    private const LL = 'LLL:EXT:content_reminder/Resources/Private/Language/locallang_be.xlf:';

    public function __construct(
        private readonly TrustedOperation $trustedOperation,
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {}

    /**
     * @param string $languageCode language of the texts, e.g. the default language of the site
     * @return int uid of the new reminder
     */
    public function create(StalePage $page, string $languageCode, BackendUserAuthentication $user): int
    {
        $languageService = $this->languageServiceFactory->create($languageCode);
        $dateFormat = $languageCode === 'de' ? 'd.m.Y' : 'Y-m-d';
        $data = [
            Reminder::TABLE => [
                'NEW1' => [
                    'pid' => $page->uid,
                    'title' => $languageService->sL(self::LL . 'staleContent.title'),
                    'notes' => sprintf($languageService->sL(self::LL . 'staleContent.notes'), $page->lastChange->format($dateFormat)),
                    'origin' => ReminderOrigin::StaleContent->value,
                    'creator' => 0,
                    'assignee' => 0,
                ],
            ],
        ];

        [$uid, $errors] = $this->trustedOperation->run(static function () use ($data, $user): array {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start($data, [], $user);
            $dataHandler->process_datamap();
            return [(int)($dataHandler->substNEWwithIDs['NEW1'] ?? 0), $dataHandler->errorLog];
        });
        if ($uid === 0 || $errors !== []) {
            throw new \RuntimeException(
                'Creating the reminder for page ' . $page->uid . ' failed: ' . implode('; ', $errors),
                1791200001
            );
        }
        return $uid;
    }
}
