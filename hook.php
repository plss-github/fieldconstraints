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
    // filters, required fields, message).
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
                `filter_logic`                      varchar(3) NOT NULL DEFAULT 'AND',
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

    // Filters of item rules ("Filters" tab): criterion, operator, JSON list of ids.
    $table = 'glpi_plugin_fieldconstraints_rulefilters';
    if (!$DB->tableExists($table)) {
        $DB->doQuery("
            CREATE TABLE `$table` (
                `id`                                     int $sign NOT NULL AUTO_INCREMENT,
                `plugin_fieldconstraints_actionrules_id` int $sign NOT NULL DEFAULT '0',
                `criterion`                              varchar(50) NOT NULL DEFAULT '',
                `operator`                               varchar(20) NOT NULL DEFAULT 'equals',
                `filter_values`                          text,
                `date_creation`                          timestamp NULL DEFAULT NULL,
                `date_mod`                               timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `plugin_fieldconstraints_actionrules_id` (`plugin_fieldconstraints_actionrules_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET = $charset COLLATE = $collation ROW_FORMAT=DYNAMIC;
        ");
    }

    // 1.0 -> 1.1: categories and ticket type of the rule form become filters.
    plugin_fieldconstraints_migrate_rule_scope($migration);

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

/**
 * Turns the 1.0 scope columns of item rules (`itilcategories`, `ticket_type`)
 * into filters with the same meaning (AND), then drops them.
 */
function plugin_fieldconstraints_migrate_rule_scope(Migration $migration): void
{
    global $DB;

    $rules   = 'glpi_plugin_fieldconstraints_actionrules';
    $filters = 'glpi_plugin_fieldconstraints_rulefilters';

    if (!$DB->fieldExists($rules, 'filter_logic')) {
        $DB->doQuery("ALTER TABLE `$rules` ADD `filter_logic` varchar(3) NOT NULL DEFAULT 'AND' AFTER `is_active`");
    }

    if (!$DB->fieldExists($rules, 'itilcategories')) {
        return;
    }

    $now = date('Y-m-d H:i:s');
    foreach ($DB->request(['FROM' => $rules]) as $row) {
        $rule_id = (int) $row['id'];

        // Stored by 1.0 as a JSON list; a category covered its subcategories.
        $categories = json_decode((string) $row['itilcategories'], true);
        $categories = is_array($categories) ? array_values(array_unique(array_filter(array_map('intval', $categories)))) : [];
        if ($categories !== []) {
            $DB->insert($filters, [
                'plugin_fieldconstraints_actionrules_id' => $rule_id,
                'criterion'                              => 'itilcategories_id',
                'operator'                               => 'under',
                'filter_values'                          => json_encode($categories),
                'date_creation'                          => $now,
                'date_mod'                               => $now,
            ]);
        }

        // Ignored by 1.0 for changes and problems.
        $type = (int) ($row['ticket_type'] ?? 0);
        if ($type > 0 && $row['itemtype'] === 'Ticket') {
            $DB->insert($filters, [
                'plugin_fieldconstraints_actionrules_id' => $rule_id,
                'criterion'                              => 'type',
                'operator'                               => 'equals',
                'filter_values'                          => json_encode([$type]),
                'date_creation'                          => $now,
                'date_mod'                               => $now,
            ]);
        }
    }

    $migration->dropField($rules, 'itilcategories');
    $migration->dropField($rules, 'ticket_type');
    $migration->migrationOneTable($rules);
}

function plugin_fieldconstraints_uninstall(): bool
{
    global $DB;

    $tables = [
        'glpi_plugin_fieldconstraints_actionblocks',
        'glpi_plugin_fieldconstraints_actionrules',
        'glpi_plugin_fieldconstraints_rulefilters',
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
        'GlpiPlugin\\Fieldconstraints\\RuleFilter',
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
