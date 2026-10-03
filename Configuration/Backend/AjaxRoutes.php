<?php

declare(strict_types=1);

use Formatsoft\ContentReminder\Controller\ReminderAjaxController;

return [
    'content_reminder_action' => [
        'path' => '/content-reminder/action',
        'methods' => ['POST'],
        'target' => ReminderAjaxController::class . '::actionAction',
    ],
    'content_reminder_history' => [
        'path' => '/content-reminder/history',
        'methods' => ['GET'],
        'target' => ReminderAjaxController::class . '::historyAction',
    ],
];
