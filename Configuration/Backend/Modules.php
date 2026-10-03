<?php

declare(strict_types=1);

use Formatsoft\ContentReminder\Controller\ReminderModuleController;

/**
 * Module "Reminders" below "Web" (TYPO3 v13) / "Content" (v14, "web" is an alias there),
 * with page tree.
 */
return [
    ReminderModuleController::MODULE => [
        'parent' => 'web',
        'access' => 'user',
        'path' => '/module/content/reminders',
        'iconIdentifier' => 'content-reminder-module',
        'labels' => 'LLL:EXT:content_reminder/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => ReminderModuleController::class . '::indexAction',
            ],
        ],
        'moduleData' => [
            'view' => 'reminders',
            'depth' => 'subpages',
            'state' => 'open',
            'period' => '90',
            'assignee' => 'all',
        ],
    ],
];
