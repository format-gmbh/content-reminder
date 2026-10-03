<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Information\Typo3Version;

$ll = 'LLL:EXT:content_reminder/Resources/Private/Language/locallang_db.xlf:tx_contentreminder_reminder';

$tca = [
    'ctrl' => [
        'title' => $ll,
        'label' => 'title',
        'label_alt' => 'due_date',
        'label_alt_force' => true,
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        // Set on copies; used to recognize copied reminders in the DataHandler hook
        'origUid' => 't3_origuid',
        'default_sortby' => 'status ASC, due_date ASC',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'rootLevel' => 0,
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
        'versioningWS' => false,
        'versioningWS_alwaysAllowLiveEdit' => true,
        'typeicon_classes' => [
            'default' => 'content-reminder-reminder',
        ],
    ],
    'columns' => [
        'title' => [
            'label' => $ll . '.title',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'eval' => 'trim',
                'required' => true,
            ],
        ],
        'notes' => [
            'label' => $ll . '.notes',
            'config' => [
                'type' => 'text',
                'cols' => 50,
                'rows' => 5,
            ],
        ],
        'due_date' => [
            'label' => $ll . '.due_date',
            'description' => $ll . '.due_date.description',
            'config' => [
                'type' => 'datetime',
                'format' => 'date',
                'dbType' => 'date',
                'nullable' => true,
            ],
        ],
        'recurrence_unit' => [
            'label' => $ll . '.recurrence_unit',
            'onChange' => 'reload',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => $ll . '.recurrence_unit.none', 'value' => 'none'],
                    ['label' => $ll . '.recurrence_unit.day', 'value' => 'day'],
                    ['label' => $ll . '.recurrence_unit.week', 'value' => 'week'],
                    ['label' => $ll . '.recurrence_unit.month', 'value' => 'month'],
                    ['label' => $ll . '.recurrence_unit.year', 'value' => 'year'],
                ],
                'default' => 'none',
                'dbFieldLength' => 10,
            ],
        ],
        'recurrence_value' => [
            'label' => $ll . '.recurrence_value',
            'displayCond' => 'FIELD:recurrence_unit:!=:none',
            'config' => [
                'type' => 'number',
                'size' => 5,
                'range' => [
                    'lower' => 1,
                    'upper' => 365,
                ],
                'default' => 1,
            ],
        ],
        'assignee' => [
            'label' => $ll . '.assignee',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => $ll . '.assignee.unassigned', 'value' => 0],
                ],
                'foreign_table' => 'be_users',
                'foreign_table_where' => 'AND {#be_users}.{#disable} = 0 AND {#be_users}.{#username} NOT LIKE \'\\_cli\\_%\' ORDER BY {#be_users}.{#realName}, {#be_users}.{#username}',
                'default' => 0,
            ],
        ],
        'creator' => [
            'label' => $ll . '.creator',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => '', 'value' => 0],
                ],
                'foreign_table' => 'be_users',
                'readOnly' => true,
                'default' => 0,
            ],
        ],
        'status' => [
            'label' => $ll . '.status',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => $ll . '.status.open', 'value' => 0],
                    ['label' => $ll . '.status.done', 'value' => 1],
                ],
                'readOnly' => true,
                'default' => 0,
            ],
        ],
        'hidden' => [
            'label' => $ll . '.hidden',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'items' => [
                    ['label' => '', 'invertStateDisplay' => false],
                ],
            ],
        ],
    ],
    'palettes' => [
        'task' => [
            'label' => $ll . '.palette.task',
            'showitem' => 'title, --linebreak--, notes',
        ],
        'schedule' => [
            'label' => $ll . '.palette.schedule',
            'showitem' => 'due_date, --linebreak--, recurrence_unit, recurrence_value',
        ],
        'responsibility' => [
            'label' => $ll . '.palette.responsibility',
            'showitem' => 'assignee, creator, --linebreak--, hidden',
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => '--palette--;;task, --palette--;;schedule, --palette--;;responsibility',
        ],
    ],
];

// TYPO3 v13 still needs ctrl.searchFields; v14 removed it and uses the field types instead.
if ((new Typo3Version())->getMajorVersion() < 14) {
    $tca['ctrl']['searchFields'] = 'title, notes';
}

return $tca;
