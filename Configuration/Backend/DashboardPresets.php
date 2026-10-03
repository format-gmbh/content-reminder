<?php

declare(strict_types=1);

return [
    'contentReminder' => [
        'title' => 'LLL:EXT:content_reminder/Resources/Private/Language/locallang_be.xlf:widget.preset.title',
        'description' => 'LLL:EXT:content_reminder/Resources/Private/Language/locallang_be.xlf:widget.preset.description',
        'iconIdentifier' => 'content-reminder-reminder',
        'defaultWidgets' => [
            'contentReminderDueCount',
            'contentReminderStatus',
            'contentReminderMine',
            'contentReminderUnassigned',
            'contentReminderOverdue',
            'contentReminderRecentlyCompleted',
        ],
        'showInWizard' => true,
    ],
];
