<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Service;

use Formatsoft\ContentReminder\Domain\Model\Reminder;
use Formatsoft\ContentReminder\Domain\Model\ReminderStatus;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Filter "only mine" / "only due" for reminders in the list module (concept 2.4.3).
 *
 * The state is toggled via the query parameter contentReminderFilter[mine|due]=0|1
 * and remembered per backend user in the module data.
 */
final class RecordListFilter implements SingletonInterface
{
    public const PARAMETER = 'contentReminderFilter';
    public const FILTERS = ['mine', 'due'];
    private const MODULE_DATA_KEY = 'content_reminder/records_filter';

    /** @var array{mine: bool, due: bool}|null */
    private ?array $state = null;

    public function __construct(
        private readonly Clock $clock,
    ) {}

    /**
     * @return array{mine: bool, due: bool}
     */
    public function getState(?ServerRequestInterface $request, BackendUserAuthentication $user): array
    {
        if ($this->state !== null) {
            return $this->state;
        }
        $stored = $user->getModuleData(self::MODULE_DATA_KEY);
        $state = [
            'mine' => (bool)($stored['mine'] ?? false),
            'due' => (bool)($stored['due'] ?? false),
        ];
        $toggle = $request?->getQueryParams()[self::PARAMETER] ?? null;
        if (is_array($toggle)) {
            foreach (self::FILTERS as $filter) {
                if (isset($toggle[$filter])) {
                    $state[$filter] = (bool)$toggle[$filter];
                }
            }
            $user->pushModuleData(self::MODULE_DATA_KEY, $state);
        }
        return $this->state = $state;
    }

    /**
     * @param array{mine: bool, due: bool} $state
     */
    public function applyToQuery(QueryBuilder $queryBuilder, array $state, int $userUid): void
    {
        if ($state['mine']) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(Reminder::TABLE . '.assignee', $queryBuilder->createNamedParameter($userUid, Connection::PARAM_INT))
            );
        }
        if ($state['due']) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(Reminder::TABLE . '.status', $queryBuilder->createNamedParameter(ReminderStatus::Open->value, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq(Reminder::TABLE . '.hidden', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->isNull(Reminder::TABLE . '.due_date'),
                    $queryBuilder->expr()->lte(Reminder::TABLE . '.due_date', $queryBuilder->createNamedParameter($this->clock->today()->format('Y-m-d'))),
                ),
            );
        }
    }

    public function buildToggleUri(UriInterface $listUri, string $filter, bool $enable): UriInterface
    {
        parse_str($listUri->getQuery(), $parameters);
        // Only the new toggle; the remaining state is stored in the module data
        $parameters[self::PARAMETER] = [$filter => $enable ? 1 : 0];
        return $listUri->withQuery(http_build_query($parameters));
    }
}
