<?php

declare(strict_types=1);

namespace FacturaScripts\Plugins\FiltroEjercicioActual\Lib;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Dinamic\Model\PageFilter;

/**
 * Stores the preferred filter separately from FacturaScripts saved filters.
 * Filter names and descriptions remain fully editable by the user.
 */
final class SavedFilterDefault
{
    public const TABLE = 'filtroejerciciodefaults';

    public static function clear(string $nick, string $viewName): void
    {
        $db = self::db();
        $db->exec('DELETE FROM ' . self::TABLE
            . ' WHERE nick = ' . $db->var2str($nick)
            . ' AND viewname = ' . $db->var2str($viewName));
    }

    public static function createTable(): void
    {
        self::db();
    }

    public static function getId(string $nick, string $viewName): int
    {
        $db = self::db();
        $rows = $db->select('SELECT idfilter FROM ' . self::TABLE
            . ' WHERE nick = ' . $db->var2str($nick)
            . ' AND viewname = ' . $db->var2str($viewName));
        $id = isset($rows[0]['idfilter']) ? (int)$rows[0]['idfilter'] : 0;
        if ($id > 0 && null !== self::filter($id, $nick, $viewName)) {
            return $id;
        }

        if ($id > 0) {
            self::clear($nick, $viewName);
        }
        return 0;
    }

    /** Lets FacturaScripts load the saved filter natively, including dates. */
    public static function applyToRequest(string $nick, string $viewName, object $request, ?object $unusedView = null): bool
    {
        $id = self::getId($nick, $viewName);
        if ($id < 1) {
            return false;
        }

        $request->request->set('loadfilter', $id);
        return true;
    }

    /** Adds a temporary visual marker for the current response only. */
    public static function markViewFilters(object $view, string $nick, string $viewName): void
    {
        $id = self::getId($nick, $viewName);
        foreach ($view->pageFilters as $filter) {
            $filter->description = self::withoutLegacyMarker((string)$filter->description);
            if ($id > 0 && (int)$filter->id === $id) {
                $filter->description = '★ ' . $filter->description;
            }
        }
    }

    public static function set(string $id, string $nick, string $viewName): bool
    {
        $selected = self::filter((int)$id, $nick, $viewName);
        if (null === $selected) {
            return false;
        }

        $db = self::db();
        return $db->exec('INSERT INTO ' . self::TABLE . ' (nick, viewname, idfilter) VALUES ('
            . $db->var2str($nick) . ', ' . $db->var2str($viewName) . ', ' . (int)$selected->id . ') '
            . 'ON DUPLICATE KEY UPDATE idfilter = VALUES(idfilter)');
    }

    /** Converts defaults created by earlier plugin versions once. */
    public static function migrateLegacyMarkers(): void
    {
        $filter = new PageFilter();
        foreach ($filter->all() as $item) {
            if (!self::hasLegacyMarker((string)$item->description)) {
                continue;
            }

            if (self::getId($item->nick, $item->name) < 1) {
                self::set((string)$item->id, $item->nick, $item->name);
            }
            $item->description = self::withoutLegacyMarker((string)$item->description);
            $item->save();
        }
    }

    private static function filter(int $id, string $nick, string $viewName): ?PageFilter
    {
        if ($id < 1) {
            return null;
        }

        $filter = new PageFilter();
        if (false === $filter->loadFromCode($id)
            || $filter->nick !== $nick
            || $filter->name !== $viewName) {
            return null;
        }

        return $filter;
    }

    private static function db(): DataBase
    {
        $db = new DataBase();
        $db->connect();
        if (!$db->tableExists(self::TABLE)) {
            $db->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
                . 'id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . 'nick VARCHAR(50) NOT NULL,'
                . 'viewname VARCHAR(64) NOT NULL,'
                . 'idfilter INT UNSIGNED NOT NULL,'
                . 'PRIMARY KEY (id),'
                . 'UNIQUE KEY filtroejerciciodefaults_nick_view (nick, viewname)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        }
        return $db;
    }

    private static function hasLegacyMarker(string $description): bool
    {
        return str_starts_with($description, '★ ') || str_ends_with($description, ' ★');
    }

    private static function withoutLegacyMarker(string $description): string
    {
        if (str_starts_with($description, '★ ')) {
            $description = substr($description, strlen('★ '));
        }
        if (str_ends_with($description, ' ★')) {
            $description = substr($description, 0, -strlen(' ★'));
        }
        return trim($description);
    }
}
