<?php

declare(strict_types=1);

use Formatsoft\ContentReminder\Mail\WeeklyMailBuilder;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

// User setting to opt out of the weekly mail - TYPO3 v14.2 and later.
// Earlier versions use $GLOBALS['TYPO3_USER_SETTINGS'] (EventListener\RegisterLegacyUserSetting).
if (method_exists(ExtensionManagementUtility::class, 'addUserSetting')) {
    ExtensionManagementUtility::addUserSetting(
        WeeklyMailBuilder::OPT_OUT_SETTING,
        [
            'label' => 'LLL:EXT:content_reminder/Resources/Private/Language/locallang_be.xlf:userSettings.mailOptOut',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
            ],
        ],
        'after:emailMeAtLogin'
    );
}
