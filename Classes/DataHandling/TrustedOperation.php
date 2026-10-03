<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\DataHandling;

use TYPO3\CMS\Core\SingletonInterface;

/**
 * Marks DataHandler calls made by the extension's own actions (complete, take over, …).
 *
 * Those actions check their permissions via ReminderPermissionService before writing
 * and may change fields that are protected in the regular editing form (e.g. status).
 * While a trusted operation runs, DataHandlerHook skips its field-level checks.
 * Table and page permissions are still enforced by the DataHandler itself.
 */
final class TrustedOperation implements SingletonInterface
{
    private int $depth = 0;

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function run(callable $operation): mixed
    {
        $this->depth++;
        try {
            return $operation();
        } finally {
            $this->depth--;
        }
    }

    public function isActive(): bool
    {
        return $this->depth > 0;
    }
}
