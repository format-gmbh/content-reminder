<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\EventListener;

use Formatsoft\ContentReminder\Domain\Model\Reminder;
use Formatsoft\ContentReminder\Service\RecordListFilter;
use TYPO3\CMS\Backend\View\Event\ModifyDatabaseQueryForRecordListingEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Applies the "only mine" / "only due" filter to the reminders in the list module.
 * The event is used for both the record query and the count query.
 */
#[AsEventListener(identifier: 'content-reminder/record-list-filter-query')]
final readonly class RecordListFilterQuery
{
    public function __construct(
        private RecordListFilter $filter,
    ) {}

    public function __invoke(ModifyDatabaseQueryForRecordListingEvent $event): void
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if ($event->getTable() !== Reminder::TABLE || !$user instanceof BackendUserAuthentication) {
            return;
        }
        $state = $this->filter->getState($GLOBALS['TYPO3_REQUEST'] ?? null, $user);
        $queryBuilder = $event->getQueryBuilder();
        $this->filter->applyToQuery($queryBuilder, $state, (int)$user->getUserId());
        $event->setQueryBuilder($queryBuilder);
    }
}
