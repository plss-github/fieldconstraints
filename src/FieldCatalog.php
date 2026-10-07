<?php

namespace GlpiPlugin\Fieldconstraints;

use CommonITILObject;
use Plugin;

/**
 * The fields a rule can require, identified by a stable key:
 *
 *  - `native:<name>`   a property of the ITIL object itself (category, actors...);
 *  - `solution:<name>` a property of the solution (type, description, attachment);
 *  - `fields:<id>`     an additional field of the Fields plugin, by field id.
 */
final class FieldCatalog
{
    public const NATIVE   = 'native';
    public const SOLUTION = 'solution';
    public const FIELDS   = 'fields';

    private const FIELDS_CONTAINERS = 'glpi_plugin_fields_containers';
    private const FIELDS_FIELDS     = 'glpi_plugin_fields_fields';

    /**
     * Native checks: key => itemtypes they apply to (null = every ITIL object).
     *
     * @return array<string, string[]|null>
     */
    private static function nativeScopes(): array
    {
        return [
            'itilcategories_id'   => null,
            'locations_id'        => null,
            'requesttypes_id'     => ['Ticket'],
            'content'             => null,
            'time_to_resolve'     => ['Ticket'],
            'actiontime'          => null,
            'requester'           => null,
            'requester_group'     => null,
            'observer'            => null,
            'assign_user'         => null,
            'assign_group'        => null,
            'assign_supplier'     => null,
            'linked_item'         => null,
            'task'                => null,
            'tasks_done'          => null,
            'followup'            => null,
            'document'            => null,
            'validation_accepted' => ['Ticket', 'Change'],
        ];
    }

    private static function nativeLabel(string $name): string
    {
        return match ($name) {
            'itilcategories_id'   => __('Category', 'fieldconstraints'),
            'locations_id'        => __('Location', 'fieldconstraints'),
            'requesttypes_id'     => __('Request source', 'fieldconstraints'),
            'content'             => __('Description', 'fieldconstraints'),
            'time_to_resolve'     => __('Time to resolve', 'fieldconstraints'),
            'actiontime'          => __('Total duration (at least one timed task)', 'fieldconstraints'),
            'requester'           => __('Requester', 'fieldconstraints'),
            'requester_group'     => __('Requester group', 'fieldconstraints'),
            'observer'            => __('Observer', 'fieldconstraints'),
            'assign_user'         => __('Assigned technician', 'fieldconstraints'),
            'assign_group'        => __('Assigned group', 'fieldconstraints'),
            'assign_supplier'     => __('Assigned supplier', 'fieldconstraints'),
            'linked_item'         => __('Associated item', 'fieldconstraints'),
            'task'                => __('At least one task', 'fieldconstraints'),
            'tasks_done'          => __('All tasks done', 'fieldconstraints'),
            'followup'            => __('At least one followup', 'fieldconstraints'),
            'document'            => __('At least one document', 'fieldconstraints'),
            'validation_accepted' => __('Approval accepted', 'fieldconstraints'),
            default               => $name,
        };
    }

    /**
     * @return string[]
     */
    public static function solutionNames(): array
    {
        return ['solutiontypes_id', 'content', 'document'];
    }

    private static function solutionLabel(string $name): string
    {
        return match ($name) {
            'solutiontypes_id' => __('Solution type', 'fieldconstraints'),
            'content'          => __('Solution description', 'fieldconstraints'),
            'document'         => __('Solution attachment', 'fieldconstraints'),
            default            => $name,
        };
    }

    /**
     * Solution checks only make sense when the target status implies a solution.
     */
    public static function statusHasSolution(int $status): bool
    {
        return in_array($status, [CommonITILObject::SOLVED, CommonITILObject::CLOSED], true);
    }

    /**
     * Options for the rule form, grouped, with plain-text labels.
     *
     * @return array<string, array<string, string>> group label => [key => label]
     */
    public static function getOptions(string $itemtype, int $status): array
    {
        $groups = [];

        $native = [];
        foreach (self::nativeScopes() as $name => $scope) {
            if ($scope === null || in_array($itemtype, $scope, true)) {
                $native[self::NATIVE . ':' . $name] = self::nativeLabel($name);
            }
        }
        $groups[__('Item fields', 'fieldconstraints')] = $native;

        if (self::statusHasSolution($status)) {
            $solution = [];
            foreach (self::solutionNames() as $name) {
                $solution[self::SOLUTION . ':' . $name] = self::solutionLabel($name);
            }
            $groups[__('Solution', 'fieldconstraints')] = $solution;
        }

        foreach (self::getAdditionalFields($itemtype) as $field) {
            $group = sprintf(__('Additional fields - %s', 'fieldconstraints'), $field['container_label']);
            $groups[$group][self::FIELDS . ':' . $field['id']] = $field['label'];
        }

        return $groups;
    }

    /**
     * Whether a key may be stored for this target.
     */
    public static function isValidKey(string $key, string $itemtype, int $status): bool
    {
        foreach (self::getOptions($itemtype, $status) as $options) {
            if (isset($options[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Human label of a key, for messages and the rule list.
     */
    public static function describe(string $key): string
    {
        [$kind, $name] = array_pad(explode(':', $key, 2), 2, '');

        if ($kind === self::NATIVE) {
            return self::nativeLabel($name);
        }

        if ($kind === self::SOLUTION) {
            return self::solutionLabel($name);
        }

        if ($kind === self::FIELDS) {
            $field = self::getAdditionalField((int) $name);
            if ($field !== null) {
                return sprintf('%s - %s', $field['container_label'], $field['label']);
            }

            return sprintf(__('Additional field #%d (removed)', 'fieldconstraints'), (int) $name);
        }

        return $key;
    }

    /**
     * Column (and form input name) holding the value of a Fields field.
     */
    public static function fieldsColumn(array $field): string
    {
        return match ($field['type']) {
            'dropdown'  => 'plugin_fields_' . $field['name'] . 'dropdowns_id',
            'glpi_item' => 'items_id_' . $field['name'],
            default     => $field['name'],
        };
    }

    /**
     * Class Fields generates for a container on an itemtype.
     *
     * Same naming as PluginFieldsContainer::getClassname(), recomputed rather
     * than called: at plugin init, the Fields autoloader may not be registered yet.
     */
    public static function fieldsClassname(string $itemtype, string $container_name): string
    {
        return 'PluginFields' . ucfirst(strtolower(
            str_replace('\\', '', $itemtype) . preg_replace('/s$/', '', $container_name)
        ));
    }

    public static function isFieldsPluginAvailable(): bool
    {
        global $DB;

        return Plugin::isPluginActive('fields')
            && $DB->tableExists(self::FIELDS_CONTAINERS)
            && $DB->tableExists(self::FIELDS_FIELDS);
    }

    /**
     * Active fields of active Fields containers attached to an itemtype.
     *
     * @return array<int, array{id: int, name: string, label: string, type: string, multiple: bool,
     *                          container_id: int, container_name: string, container_label: string}>
     */
    public static function getAdditionalFields(string $itemtype): array
    {
        $fields = [];
        foreach (self::queryAdditionalFields([]) as $field) {
            if (in_array($itemtype, $field['itemtypes'], true)) {
                unset($field['itemtypes']);
                $fields[$field['id']] = $field;
            }
        }

        return $fields;
    }

    /**
     * @return array{id: int, name: string, label: string, type: string, multiple: bool, container_id: int,
     *               container_name: string, container_label: string, itemtypes: string[]}|null
     */
    public static function getAdditionalField(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return self::queryAdditionalFields(['f.id' => $id])[$id] ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function queryAdditionalFields(array $where): array
    {
        global $DB;

        if (!self::isFieldsPluginAvailable()) {
            return [];
        }

        $select = [
            'f.id',
            'f.name',
            'f.label',
            'f.type',
            'c.id AS container_id',
            'c.name AS container_name',
            'c.label AS container_label',
            'c.itemtypes',
        ];
        // Multiple dropdowns arrived in Fields 1.15; older releases lack the column.
        if ($DB->fieldExists(self::FIELDS_FIELDS, 'multiple')) {
            $select[] = 'f.multiple';
        }

        $iterator = $DB->request([
            'SELECT'     => $select,
            'FROM'       => self::FIELDS_FIELDS . ' AS f',
            'INNER JOIN' => [
                self::FIELDS_CONTAINERS . ' AS c' => [
                    'ON' => ['f' => 'plugin_fields_containers_id', 'c' => 'id'],
                ],
            ],
            'WHERE'      => array_merge([
                'f.is_active' => 1,
                'c.is_active' => 1,
                'NOT'         => ['f.type' => 'header'],
            ], $where),
            'ORDER'      => ['c.label', 'f.ranking'],
        ]);

        $fields = [];
        foreach ($iterator as $row) {
            $itemtypes = json_decode(Compat::plain($row['itemtypes']), true);

            $fields[(int) $row['id']] = [
                'id'              => (int) $row['id'],
                'name'            => (string) $row['name'],
                'label'           => Compat::plain($row['label']),
                'type'            => (string) $row['type'],
                'multiple'        => (bool) ($row['multiple'] ?? false),
                'container_id'    => (int) $row['container_id'],
                'container_name'  => (string) $row['container_name'],
                'container_label' => Compat::plain($row['container_label']),
                'itemtypes'       => is_array($itemtypes) ? $itemtypes : [],
            ];
        }

        return $fields;
    }
}
