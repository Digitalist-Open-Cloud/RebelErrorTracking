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
            KEY `time` (`server_time`)
        ");
    }

    public function uninstall(): void
    {
        Db::dropTables(Common::prefixTable(self::TABLE));
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
            "SELECT error.server_time, error.page_url, error.source, error.line_number, error.column_number, error.stack,
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
    }

    private function table(): string
    {
        return Common::prefixTable(self::TABLE);
    }
}
