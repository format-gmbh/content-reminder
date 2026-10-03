<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS extension "content_reminder".
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Formatsoft\ContentReminder\Mail;

use Doctrine\DBAL\ArrayParameterType;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Pages of a page tree (default language, live workspace, not deleted; hidden
 * pages included, as their content is maintained as well).
 */
class SitePageCollector
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @return array<int, string> page uid => title, including the root page
     */
    public function collect(int $rootPageUid): array
    {
        return $this->collectSubtrees([$rootPageUid]);
    }

    /**
     * Pages of several subtrees. Root 0 stands for the whole page tree.
     *
     * @param list<int> $rootPageUids
     * @return array<int, string> page uid => title, including the root pages
     */
    public function collectSubtrees(array $rootPageUids): array
    {
        $rootPageUids = array_values(array_unique(array_map(intval(...), $rootPageUids)));
        $pages = in_array(0, $rootPageUids, true)
            ? $this->fetchPages('pid', [0])
            : $this->fetchPages('uid', $rootPageUids);
        $parents = array_keys($pages);
        while ($parents !== []) {
            $children = [];
            foreach (array_chunk($parents, 1000) as $chunk) {
                $children += $this->fetchPages('pid', $chunk);
            }
            $children = array_diff_key($children, $pages);
            $pages += $children;
            $parents = array_keys($children);
        }
        return $pages;
    }

    /**
     * @param list<int> $values
     * @return array<int, string>
     */
    private function fetchPages(string $field, array $values): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('uid', 'title')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->in($field, $queryBuilder->createNamedParameter($values, ArrayParameterType::INTEGER)),
                $queryBuilder->expr()->eq('deleted', 0),
                $queryBuilder->expr()->eq('sys_language_uid', 0),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $pages = [];
        foreach ($rows as $row) {
            $pages[(int)$row['uid']] = (string)$row['title'];
        }
        return $pages;
    }
}
