<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Service;

use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Labels for reminder-actions.js as JSON, for places where TYPO3.lang is not filled
 * (dashboard widgets are loaded via AJAX). Rendered into a data-content-reminder-labels attribute.
 */
final class JavaScriptLabels
{
    private const LL = 'LLL:EXT:content_reminder/Resources/Private/Language/locallang_be.xlf:js.';

    private const KEYS = [
        'complete.title',
        'complete.hint',
        'complete.hintRecurring',
        'complete.comment',
        'complete.confirm',
        'cancel',
        'close',
        'history.title',
        'notification.success',
        'notification.error',
        'notification.failed',
    ];

    public function toJson(LanguageService $languageService): string
    {
        $labels = [];
        foreach (self::KEYS as $key) {
            $labels['js.' . $key] = $languageService->sL(self::LL . $key);
        }
        return (string)json_encode($labels, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
