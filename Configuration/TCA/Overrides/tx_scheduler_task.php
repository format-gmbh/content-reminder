<?php

declare(strict_types=1);

use Formatsoft\ContentReminder\Domain\Repository\ReminderLogRepository;

defined('TYPO3') or die();

// Scheduler task "Table garbage collection" (TYPO3 v14): allow cleaning up old archive entries.
// TYPO3 v13 registers the table in ext_localconf.php.
$garbageCollectionTask = 'TYPO3\\CMS\\Scheduler\\Task\\TableGarbageCollectionTask';
if (isset($GLOBALS['TCA']['tx_scheduler_task']['types'][$garbageCollectionTask])) {
    $GLOBALS['TCA']['tx_scheduler_task']['types'][$garbageCollectionTask]['taskOptions']['tables'][ReminderLogRepository::TABLE] ??= [
        'dateField' => 'completed_at',
        'expirePeriod' => ReminderLogRepository::GARBAGE_COLLECTION_DAYS,
    ];
}
unset($garbageCollectionTask);
