<?php

/**
 * Install / uninstall entry points.
 *
 * Table creation is raw SQL through $DB->doQuery(): it exists on GLPI 10 and 11,
 * whereas $DB->query() throws on 11. install() also runs on upgrade, so every
 * statement is guarded.
 */

function plugin_fieldconstraints_install(): bool
{
    global $DB;

    $migration = new Migration(PLUGIN_FIELDCONSTRAINTS_VERSION);

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();

    // Action rules: blocks (one action, entity scope) holding item rules (item,
    // categories, ticket type, required fields, message).
    $table = 'glpi_plugin_fieldconstraints_actionblocks';
    if (!$DB->tableExists($table)) {
        $DB->doQuery("
            CREATE TABLE `$table` (
                `id`            int $sign NOT NULL AUTO_INCREMENT,
                `name`          varchar(255) DEFAULT NULL,
                `entities_id`   int $sign NOT NULL DEFAULT '0',
                `is_recursive`  tinyint NOT NULL DEFAULT '1',
                `is_active`     tinyint NOT NULL DEFAULT '1',
                `status`        int NOT NULL DEFAULT '0',
                `comment`       text,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod`      timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `name` (`name`),
                KEY `entities_id` (`entities_id`),
                KEY `is_active` (`is_active`),
                KEY `status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET = $charset COLLATE = $collation ROW_FORMAT=DYNAMIC;
        ");
    }

    $table = 'glpi_plugin_fieldconstraints_actionrules';
    if (!$DB->tableExists($table)) {
        $DB->doQuery("
            CREATE TABLE `$table` (
                `id`                                int $sign NOT NULL AUTO_INCREMENT,
                `plugin_fieldconstraints_actionblocks_id` int $sign NOT NULL DEFAULT '0',
                `itemtype`                          varchar(100) NOT NULL DEFAULT '',
                `is_active`                         tinyint NOT NULL DEFAULT '1',
                `itilcategories`                    text,
                `ticket_type`                       int NOT NULL DEFAULT '0',
                `required_fields`                   text,
                `message`                           text,
                `comment`                           text,
                `date_creation`                     timestamp NULL DEFAULT NULL,
                `date_mod`                          timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `plugin_fieldconstraints_actionblocks_id` (`plugin_fieldconstraints_actionblocks_id`),
                KEY `itemtype` (`itemtype`),
                KEY `is_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET = $charset COLLATE = $collation ROW_FORMAT=DYNAMIC;
        ");
    }

    // Date constraints, attached to Fields containers and to forms.
    $table = 'glpi_plugin_fieldconstraints_dateconstraints';
    if (!$DB->tableExists($table)) {
        $DB->doQuery("
            CREATE TABLE `$table` (
                `id`            int $sign NOT NULL AUTO_INCREMENT,
                `source`        varchar(20) NOT NULL DEFAULT '',
                `items_id`      int $sign NOT NULL DEFAULT '0',
                `field`         varchar(100) NOT NULL DEFAULT '',
                `operator`      varchar(10) NOT NULL DEFAULT 'min',
                `reference`     varchar(10) NOT NULL DEFAULT 'today',
                `ref_date`      date DEFAULT NULL,
                `ref_field`     varchar(100) NOT NULL DEFAULT '',
                `is_active`     tinyint NOT NULL DEFAULT '1',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod`      timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `parent` (`source`, `items_id`),
                KEY `is_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET = $charset COLLATE = $collation ROW_FORMAT=DYNAMIC;
        ");
    }

    $migration->executeMigration();

    return true;
}

function plugin_fieldconstraints_uninstall(): bool
{
    global $DB;

    $tables = [
        'glpi_plugin_fieldconstraints_actionblocks',
        'glpi_plugin_fieldconstraints_actionrules',
        'glpi_plugin_fieldconstraints_dateconstraints',
    ];
    foreach ($tables as $table) {
        if ($DB->tableExists($table)) {
            $DB->dropTable($table);
        }
    }

    // Search preferences and history entries left behind by the rule itemtypes.
    $itemtypes = [
        'GlpiPlugin\\Fieldconstraints\\ActionBlock',
        'GlpiPlugin\\Fieldconstraints\\ActionRule',
    ];
    foreach ($itemtypes as $itemtype) {
        if (version_compare(GLPI_VERSION, '11.0', '<')) {
            // GLPI 10's query builder does not escape values (they are expected to
            // arrive pre-escaped), and the backslashes would be eaten by MySQL.
            $itemtype = $DB->escape($itemtype);
        }
        foreach (['glpi_displaypreferences', 'glpi_savedsearches', 'glpi_logs'] as $core_table) {
            $DB->delete($core_table, ['itemtype' => $itemtype]);
        }
    }

    return true;
}
