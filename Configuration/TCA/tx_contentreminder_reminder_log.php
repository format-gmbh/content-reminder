<?php

declare(strict_types=1);

$ll = 'LLL:EXT:content_reminder/Resources/Private/Language/locallang_db.xlf:tx_contentreminder_reminder_log';

// Archive of completed reminders. Records live on pid 0 so that they survive
// the deletion of the page or the reminder; they are written by the extension only.
return [
    'ctrl' => [
        'title' => $ll,
        'label' => 'reminder_title',
        'label_alt' => 'completed_by_name',
        'label_alt_force' => true,
        'crdate' => 'crdate',
        'default_sortby' => 'completed_at DESC',
        'rootLevel' => 1,
        'readOnly' => true,
        'hideTable' => true,
        'versioningWS' => false,
        'typeicon_classes' => [
            'default' => 'content-reminder-reminder-log',
        ],
    ],
    'columns' => [
        'reminder' => [
            'label' => $ll . '.reminder',
            'config' => [
                'type' => 'number',
                'default' => 0,
            ],
        ],
        'reminder_title' => [
            'label' => $ll . '.reminder_title',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
            ],
        ],
        'page' => [
            'label' => $ll . '.page',
            'config' => [
                'type' => 'number',
                'default' => 0,
            ],
        ],
        'page_title' => [
            'label' => $ll . '.page_title',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
            ],
        ],
        'completed_by' => [
            'label' => $ll . '.completed_by',
            'config' => [
                'type' => 'number',
                'default' => 0,
            ],
        ],
        'completed_by_name' => [
            'label' => $ll . '.completed_by_name',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
            ],
        ],
        'completed_at' => [
            'label' => $ll . '.completed_at',
            'config' => [
                'type' => 'datetime',
                'format' => 'datetime',
                'default' => 0,
            ],
        ],
        'due_date' => [
            'label' => $ll . '.due_date',
            'config' => [
                'type' => 'datetime',
                'format' => 'date',
                'dbType' => 'date',
                'nullable' => true,
            ],
        ],
        'next_due_date' => [
            'label' => $ll . '.next_due_date',
            'config' => [
                'type' => 'datetime',
                'format' => 'date',
                'dbType' => 'date',
                'nullable' => true,
            ],
        ],
        'comment' => [
            'label' => $ll . '.comment',
            'config' => [
                'type' => 'text',
                'cols' => 50,
                'rows' => 3,
            ],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'reminder_title, reminder, page_title, page, completed_by_name, completed_by, completed_at, due_date, next_due_date, comment',
        ],
    ],
];
