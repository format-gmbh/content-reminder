<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;
use TYPO3\CMS\Core\Information\Typo3Version;

return [
    'content-reminder-extension' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:content_reminder/Resources/Public/Icons/Extension.svg',
    ],
    // TYPO3 v14 module icons are line icons colored by the backend theme
    // (currentColor, --icon-color-accent), v13 module icons are colored squares
    'content-reminder-module' => [
        'provider' => SvgIconProvider::class,
        'source' => (new Typo3Version())->getMajorVersion() >= 14
            ? 'EXT:content_reminder/Resources/Public/Icons/Module.svg'
            : 'EXT:content_reminder/Resources/Public/Icons/Extension.svg',
    ],
    'content-reminder-reminder' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:content_reminder/Resources/Public/Icons/Reminder.svg',
    ],
    'content-reminder-reminder-log' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:content_reminder/Resources/Public/Icons/ReminderLog.svg',
    ],
];
