<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

// Exclude a single page from the automatic reminders for outdated content (concept 6.3)
ExtensionManagementUtility::addTCAcolumns('pages', [
    'tx_contentreminder_stale_exclude' => [
        'exclude' => true,
        'l10n_mode' => 'exclude',
        'label' => 'LLL:EXT:content_reminder/Resources/Private/Language/locallang_db.xlf:pages.tx_contentreminder_stale_exclude',
        'description' => 'LLL:EXT:content_reminder/Resources/Private/Language/locallang_db.xlf:pages.tx_contentreminder_stale_exclude.description',
        'config' => [
            'type' => 'check',
            'renderType' => 'checkboxToggle',
            'default' => 0,
        ],
    ],
]);
ExtensionManagementUtility::addFieldsToPalette('pages', 'miscellaneous', 'tx_contentreminder_stale_exclude', 'after:no_search');
