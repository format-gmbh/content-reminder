<?php

// Still required for TER / Tailor and TYPO3 v13 classic mode.
// TYPO3 v14+ reads the metadata from composer.json.
$EM_CONF[$_EXTKEY] = [
    'title' => 'Content Reminder',
    'description' => 'Reminders for TYPO3 pages: due dates, recurrence, assignment and archive to keep content up to date',
    'category' => 'be',
    'state' => 'alpha',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'php' => '8.2.0-8.5.99',
            'typo3' => '13.4.0-14.99.99',
            'backend' => '13.4.0-14.99.99',
            'dashboard' => '13.4.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
