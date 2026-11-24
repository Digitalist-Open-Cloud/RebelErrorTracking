<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\RebelErrorTracking\Dao;

use Piwik\Common;
use Piwik\Db;
use Piwik\DbHelper;

class LogError
{
    public const TABLE = 'rebel_error_log';

    // When each error of a website was first seen. It outlives the errors themselves for as long as
    // the error keeps happening.
    public const FIRST_SEEN_TABLE = 'rebel_error_first_seen';

    public function install(): void
    {
        DbHelper::createTable(self::TABLE, "
            `idlog` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `idsite` INT UNSIGNED NOT NULL,
            `idvisit` BIGINT UNSIGNED NOT NULL,
            `idvisitor` BINARY(8) NOT NULL,
            `server_time` DATETIME NOT NULL,
            `type` VARCHAR(10) NOT NULL,
            `fingerprint` CHAR(32) NOT NULL,
            `message` TEXT NOT NULL,
            `source` TEXT NOT NULL,
            `line_number` INT UNSIGNED NOT NULL DEFAULT 0,
            `column_number` INT UNSIGNED NOT NULL DEFAULT 0,
            `stack` TEXT NOT NULL,
            `page_url` TEXT NOT NULL,
            PRIMARY KEY (`idlog`),
            KEY `site_time` (`idsite`, `server_time`),
            KEY `visit` (`idvisit`),
            KEY `time` (`server_time`),
            KEY `site_fingerprint` (`idsite`, `fingerprint`)
        ");
        $this->installFirstSeen();
    }

    public function installFirstSeen(): void
    {
        DbHelper::createTable(self::FIRST_SEEN_TABLE, "
            `idsite` INT UNSIGNED NOT NULL,
            `fingerprint` CHAR(32) NOT NULL,
            `first_seen` DATETIME NOT NULL,
            PRIMARY KEY (`idsite`, `fingerprint`)
        ");
    }

    public function uninstall(): void
    {
        Db::dropTables([Common::prefixTable(self::TABLE), Common::prefixTable(self::FIRST_SEEN_TABLE)]);
    }

    /**
     * @param array<string, mixed> $row
     */
    public function record(array $row): void
    {
        $columns = array_keys($row);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table(),
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?'))
        );
        Db::query($sql, array_values($row));

        Db::query(
            'INSERT IGNORE INTO ' . Common::prefixTable(self::FIRST_SEEN_TABLE) . ' (idsite, fingerprint, first_seen) VALUES (?, ?, ?)',
            [$row['idsite'], $row['fingerprint'], $row['server_time']]
        );
    }

    /**
     * When the given errors of the websites were first seen, in UTC, by fingerprint.
     *
     * @param int[] $idSites
     * @param string[] $fingerprints
     * @return array<string, string>
     */
    public function getFirstSeen(array $idSites, array $fingerprints): array
    {
        $firstSeen = [];
        foreach (array_chunk($fingerprints, 500) as $chunk) {
            $rows = Db::fetchAll(
                'SELECT fingerprint, MIN(first_seen) AS first_seen
                   FROM ' . Common::prefixTable(self::FIRST_SEEN_TABLE) . '
                  WHERE idsite IN (' . Common::getSqlStringFieldsArray($idSites) . ')
                    AND fingerprint IN (' . Common::getSqlStringFieldsArray($chunk) . ')
                  GROUP BY fingerprint',
                array_merge(array_map('intval', $idSites), $chunk)
            );
            foreach ($rows as $row) {
                $firstSeen[$row['fingerprint']] = $row['first_seen'];
            }
        }

        return $firstSeen;
    }

    /**
     * @param int|string $idVisit
     */
    public function countForVisit($idVisit): int
    {
        return (int) Db::fetchOne('SELECT COUNT(*) FROM ' . $this->table() . ' WHERE idvisit = ?', [$idVisit]);
    }

    /**
     * The latest errors with the fingerprint of the given one, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getOccurrences(int $idSite, int $idLog, string $from, string $to, int $limit): array
    {
        $table = $this->table();

        return Db::fetchAll(
            "SELECT error.idvisit, error.server_time, error.page_url, error.source, error.line_number, error.column_number, error.stack,
                    visit.config_browser_name, visit.config_browser_version, visit.config_os, visit.config_os_version
               FROM $table error
               LEFT JOIN " . Common::prefixTable('log_visit') . " visit ON visit.idvisit = error.idvisit
              WHERE error.idsite = ? AND error.server_time BETWEEN ? AND ?
                AND error.fingerprint = (SELECT fingerprint FROM $table WHERE idlog = ? AND idsite = ?)
              ORDER BY error.idlog DESC
              LIMIT " . $limit,
            [$idSite, $from, $to, $idLog, $idSite]
        );
    }

    /**
     * @param array<int|string> $idVisits
     * @return array<int, array<string, mixed>>
     */
    public function getErrorsOfVisits(array $idVisits): array
    {
        $idVisits = array_map('intval', $idVisits);
        if (!$idVisits) {
            return [];
        }

        return Db::fetchAll(
            'SELECT idvisit, server_time, type, message, source, line_number, column_number
               FROM ' . $this->table() . '
              WHERE idvisit IN (' . Common::getSqlStringFieldsArray($idVisits) . ')
              ORDER BY idlog',
            $idVisits
        );
    }

    public function deleteOlderThan(string $time): void
    {
        Db::query('DELETE FROM ' . $this->table() . ' WHERE server_time < ?', [$time]);

        // An error that no longer happens is forgotten, and is new when it comes back.
        Db::query(
            'DELETE first_seen FROM ' . Common::prefixTable(self::FIRST_SEEN_TABLE) . ' first_seen
               LEFT JOIN ' . $this->table() . ' error
                 ON error.idsite = first_seen.idsite AND error.fingerprint = first_seen.fingerprint
              WHERE error.idlog IS NULL'
        );
    }

    private function table(): string
    {
        return Common::prefixTable(self::TABLE);
    }
}
