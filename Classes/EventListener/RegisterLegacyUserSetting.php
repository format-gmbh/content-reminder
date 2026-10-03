<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\EventListener;

use Formatsoft\ContentReminder\Mail\WeeklyMailBuilder;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Setup\Event\AddJavaScriptModulesEvent;

/**
 * Adds the "no weekly mail" option to the user settings module in TYPO3 v13 (and v14.0/14.1).
 *
 * There, user settings live in $GLOBALS['TYPO3_USER_SETTINGS'], which EXT:setup defines in its
 * ext_tables.php - and ext_tables.php files are deprecated since TYPO3 v14.3. The setup module
 * dispatches this event before it reads or stores the settings, so the field is added just in time.
 * From TYPO3 v14.2 on, user settings are TCA (Configuration/TCA/Overrides/be_users.php).
 */
#[AsEventListener(identifier: 'content-reminder/register-legacy-user-setting')]
final class RegisterLegacyUserSetting
{
    public function __invoke(AddJavaScriptModulesEvent $event): void
    {
        if (method_exists(ExtensionManagementUtility::class, 'addUserSetting')
            || !is_array($GLOBALS['TYPO3_USER_SETTINGS']['columns'] ?? null)
            || isset($GLOBALS['TYPO3_USER_SETTINGS']['columns'][WeeklyMailBuilder::OPT_OUT_SETTING])
        ) {
            return;
        }
        $GLOBALS['TYPO3_USER_SETTINGS']['columns'][WeeklyMailBuilder::OPT_OUT_SETTING] = [
            'type' => 'check',
            'label' => 'LLL:EXT:content_reminder/Resources/Private/Language/locallang_be.xlf:userSettings.mailOptOut',
        ];
        ExtensionManagementUtility::addFieldsToUserSettings(WeeklyMailBuilder::OPT_OUT_SETTING, 'after:emailMeAtLogin');
    }
}
