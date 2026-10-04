<?php

declare(strict_types=1);

defined('TYPO3') or die();

// Templates of the weekly mail (FluidEmail)
$GLOBALS['TYPO3_CONF_VARS']['MAIL']['templateRootPaths'][1791100100]
    = 'EXT:content_reminder/Resources/Private/Templates/Email/';

// Enforce the permission matrix for all write access through the DataHandler
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['content_reminder']
    = \Formatsoft\ContentReminder\Hooks\DataHandlerHook::class;
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass']['content_reminder']
    = \Formatsoft\ContentReminder\Hooks\DataHandlerHook::class;

// Scheduler task "Table garbage collection" (TYPO3 v13): allow cleaning up old archive entries.
// TYPO3 v14 uses TCA instead (Configuration/TCA/Overrides/tx_scheduler_task.php), the
// configuration below is deprecated there.
if ((new \TYPO3\CMS\Core\Information\Typo3Version())->getMajorVersion() < 14) {
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks']['TYPO3\\CMS\\Scheduler\\Task\\TableGarbageCollectionTask']['options']['tables'][\Formatsoft\ContentReminder\Domain\Repository\ReminderLogRepository::TABLE] ??= [
        'dateField' => 'completed_at',
        'expirePeriod' => \Formatsoft\ContentReminder\Domain\Repository\ReminderLogRepository::GARBAGE_COLLECTION_DAYS,
    ];
}

// Additional permissions, configurable per backend user group ("Access Lists" tab)
$GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['content_reminder'] = [
    'header' => 'LLL:EXT:content_reminder/Resources/Private/Language/locallang_be.xlf:customPermOptions.header',
    'items' => [
        'assignOthers' => [
            'LLL:EXT:content_reminder/Resources/Private/Language/locallang_be.xlf:customPermOptions.assignOthers',
            'content-reminder-reminder',
            'LLL:EXT:content_reminder/Resources/Private/Language/locallang_be.xlf:customPermOptions.assignOthers.description',
        ],
        'manageAll' => [
            'LLL:EXT:content_reminder/Resources/Private/Language/locallang_be.xlf:customPermOptions.manageAll',
            'content-reminder-reminder',
            'LLL:EXT:content_reminder/Resources/Private/Language/locallang_be.xlf:customPermOptions.manageAll.description',
        ],
    ],
];
