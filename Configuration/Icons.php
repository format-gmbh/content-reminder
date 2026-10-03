<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    'content-reminder-extension' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:content_reminder/Resources/Public/Icons/Extension.svg',
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
