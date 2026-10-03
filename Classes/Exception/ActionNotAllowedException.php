<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Exception;

/**
 * The current backend user may not perform the requested action on a reminder.
 */
final class ActionNotAllowedException extends \RuntimeException {}
