<?php

namespace GlpiPlugin\Fieldconstraints;

use CommonITILActor;
use CommonITILObject;
use CommonITILValidation;
use Planning;

/**
 * Evaluates the rules that apply to a status change of an ITIL object.
 *
 * The state checked is the object as it will be after the write: stored values
 * overridden by what the same request is changing ($input), so that a
 * technician filling the category and solving in one save is not refused.
 */
final class Checker
{
    /**
     * @param int[]      $statuses       target statuses (a solution may solve AND close)
     * @param array      $input          input of the ITIL object update, if any
     * @param array|null $solution_input input of the solution being added, if any
     *
     * @return array<int, array{rule: array, missing: array<string, string>}> violated rules,
     *         with the missing fields as key => label
     */
    public static function check(
        CommonITILObject $item,
        array $statuses,
        array $input = [],
        ?array $solution_input = null
    ): array {
        $state = array_merge($item->fields, self::scalarInput($input));

        $violations = [];
        foreach (self::findRules($item, $state, $statuses, $input) as $rule) {
            $missing = [];
            foreach (ActionRule::decodeRequiredFields($rule['required_fields'] ?? null) as $key) {
                if (!self::isFilled($item, $state, $input, $solution_input, $key)) {
                    $missing[$key] = FieldCatalog::describe($key);
                }
            }

            if ($missing !== []) {
                $violations[] = ['rule' => $rule, 'missing' => $missing];
            }
        }

        return $violations;
    }

    /**
     * Active rules, for every action, whose scope covers the object as stored.
     *
     * @return array<int, array>
     */
    public static function applicableRules(CommonITILObject $item): array
    {
        return self::findRules($item, $item->fields, null);
    }

    /**
     * Active item rules of this itemtype, in active blocks of these actions
     * (null = any), whose scope covers the object: the block's entity, then the
     * rule's filters (RuleFilter).
     *
     * Each rule comes merged with its block: `status`, `name` (the block's),
     * `entities_id`, `is_recursive` from the block; `message`, `required_fields`,
     * `filter_logic` from the rule.
     *
     * @return array<int, array>
     */
    private static function findRules(CommonITILObject $item, array $state, ?array $statuses, array $input = []): array
    {
        global $DB;

        if (!$DB->tableExists(ActionRule::getTable())) {
            return [];
        }

        $where = [
            'r.is_active' => 1,
            'b.is_active' => 1,
            'r.itemtype'  => Compat::forDb($item->getType()),
        ];
        if ($statuses !== null) {
            $where['b.status'] = array_values(array_unique(array_map('intval', $statuses)));
            if ($where['b.status'] === []) {
                return [];
            }
        }

        $iterator = $DB->request([
            'SELECT'     => [
                'r.id', 'r.itemtype', 'r.filter_logic', 'r.required_fields', 'r.message',
                'b.id AS block_id', 'b.name', 'b.status', 'b.entities_id', 'b.is_recursive',
            ],
            'FROM'       => ActionRule::getTable() . ' AS r',
            'INNER JOIN' => [
                ActionBlock::getTable() . ' AS b' => ['ON' => ['r' => ActionRule::$items_id, 'b' => 'id']],
            ],
            'WHERE'      => $where,
            'ORDER'      => ['b.id', 'r.id'],
        ]);

        $entity    = (int) ($state['entities_id'] ?? 0);
        $ancestors = array_map('intval', array_values(getAncestorsOf('glpi_entities', $entity)));

        $candidates = [];
        foreach ($iterator as $rule) {
            $rule_entity = (int) $rule['entities_id'];
            $in_entity   = $rule_entity === $entity
                || ($rule['is_recursive'] && in_array($rule_entity, $ancestors, true));
            if ($in_entity) {
                $candidates[] = $rule;
            }
        }

        $filters = RuleFilter::getForRules(array_column($candidates, 'id'));
        $rules   = [];
        foreach ($candidates as $rule) {
            if (RuleFilter::matchAll($filters[(int) $rule['id']] ?? [], (string) $rule['filter_logic'], $item, $state, $input)) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    private static function isFilled(
        CommonITILObject $item,
        array $state,
        array $input,
        ?array $solution_input,
        string $key
    ): bool {
        [$kind, $name] = array_pad(explode(':', $key, 2), 2, '');

        return match ($kind) {
            FieldCatalog::NATIVE   => self::nativeFilled($item, $state, $input, $name),
            FieldCatalog::SOLUTION => self::solutionFilled($item, $solution_input, $name),
            FieldCatalog::FIELDS   => self::additionalFilled($item, $input, (int) $name),
            // Unknown key (stored by a newer version?): never block on it.
            default                => true,
        };
    }

    private static function nativeFilled(CommonITILObject $item, array $state, array $input, string $name): bool
    {
        switch ($name) {
            case 'itilcategories_id':
            case 'locations_id':
            case 'requesttypes_id':
                return (int) ($state[$name] ?? 0) > 0;

            case 'content':
                return self::hasText($state['content'] ?? '');

            case 'time_to_resolve':
                return self::hasDate($state['time_to_resolve'] ?? null);

            case 'actiontime':
                return (int) ($state['actiontime'] ?? 0) > 0;

            case 'requester':
                return self::hasActor($item, $input, 'requester', CommonITILActor::REQUESTER, 'User');
            case 'requester_group':
                return self::hasActor($item, $input, 'requester', CommonITILActor::REQUESTER, 'Group');
            case 'observer':
                return self::hasActor($item, $input, 'observer', CommonITILActor::OBSERVER, 'User')
                    || self::hasActor($item, $input, 'observer', CommonITILActor::OBSERVER, 'Group');
            case 'assign_user':
                return self::hasActor($item, $input, 'assign', CommonITILActor::ASSIGN, 'User');
            case 'assign_group':
                return self::hasActor($item, $input, 'assign', CommonITILActor::ASSIGN, 'Group');
            case 'assign_supplier':
                return self::hasActor($item, $input, 'assign', CommonITILActor::ASSIGN, 'Supplier');

            case 'linked_item':
                return self::hasLinkedItem($item, $input);

            case 'task':
                return self::countTasks($item, false) > 0;
            case 'tasks_done':
                return self::countTasks($item, true) === 0;

            case 'followup':
                return countElementsInTable('glpi_itilfollowups', [
                    'itemtype' => $item->getType(),
                    'items_id' => $item->getID(),
                ]) > 0;

            case 'document':
                return self::hasUploadedFiles($input)
                    || countElementsInTable('glpi_documents_items', [
                        'itemtype' => $item->getType(),
                        'items_id' => $item->getID(),
                    ]) > 0;

            case 'validation_accepted':
                return (int) ($state['global_validation'] ?? 0) === CommonITILValidation::ACCEPTED;
        }

        return true;
    }

    private static function solutionFilled(CommonITILObject $item, ?array $solution_input, string $name): bool
    {
        // A solution is being added: its own input is what counts.
        if ($solution_input !== null) {
            return match ($name) {
                'solutiontypes_id' => (int) ($solution_input['solutiontypes_id'] ?? 0) > 0,
                'content'          => self::hasText($solution_input['content'] ?? ''),
                'document'         => self::hasUploadedFiles($solution_input),
                default            => true,
            };
        }

        // Direct status change (API, massive action, approval of the solution):
        // the latest solution that was not refused.
        $solution = self::latestSolution($item);
        if ($solution === null) {
            return false;
        }

        return match ($name) {
            'solutiontypes_id' => (int) $solution['solutiontypes_id'] > 0,
            'content'          => self::hasText($solution['content'] ?? ''),
            'document'         => countElementsInTable('glpi_documents_items', [
                'itemtype' => 'ITILSolution',
                'items_id' => (int) $solution['id'],
            ]) > 0,
            default            => true,
        };
    }

    private static function additionalFilled(CommonITILObject $item, array $input, int $field_id): bool
    {
        global $DB;

        $field = FieldCatalog::getAdditionalField($field_id);
        if ($field === null || !in_array($item->getType(), $field['itemtypes'], true)) {
            // Field removed, deactivated or Fields plugin disabled: nothing to enforce.
            return true;
        }

        $column = FieldCatalog::fieldsColumn($field);

        // Container inserted in the main form: the value travels with this very
        // update and Fields only stores it afterwards, in its own hook.
        if (isset($input['_plugin_fields_data']) && is_array($input['_plugin_fields_data'])
            && array_key_exists($column, $input['_plugin_fields_data'])) {
            return !self::isEmptyFieldValue($input['_plugin_fields_data'][$column], $field);
        }
        if (isset($input['c_id']) || isset($input['_plugin_fields_type'])) {
            if (array_key_exists($column, $input)) {
                return !self::isEmptyFieldValue($input[$column], $field);
            }
            // An emptied multiple dropdown posts no value, only this marker.
            if (isset($input['_' . $column . '_defined'])) {
                return false;
            }
        }

        // No value table means Fields itself is broken for this container: nobody
        // could fill the field, so it must not lock the ticket.
        $table = self::fieldsValueTable($item->getType(), $field['container_name']);
        if ($table === null || !$DB->tableExists($table) || !$DB->fieldExists($table, $column)) {
            return true;
        }

        $row = $DB->request([
            'SELECT' => [$column],
            'FROM'   => $table,
            'WHERE'  => [
                'itemtype'                    => Compat::forDb($item->getType()),
                'items_id'                    => $item->getID(),
                'plugin_fields_containers_id' => $field['container_id'],
            ],
            'LIMIT'  => 1,
        ])->current();

        return $row !== null && !self::isEmptyFieldValue($row[$column], $field);
    }

    /**
     * Table holding the values of a Fields container for an itemtype.
     */
    public static function fieldsValueTable(string $itemtype, string $container_name): ?string
    {
        $classname = is_callable(['PluginFieldsContainer', 'getClassname'])
            ? \PluginFieldsContainer::getClassname($itemtype, $container_name)
            : FieldCatalog::fieldsClassname($itemtype, $container_name);

        $table = getTableForItemType($classname);

        return is_string($table) && $table !== '' ? $table : null;
    }

    private static function isEmptyFieldValue(mixed $value, array $field): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_array($value)) {
            return array_filter($value, static fn ($v) => $v !== '' && $v !== '0' && $v !== 0) === [];
        }

        $value = trim(Compat::plain((string) $value));
        if ($value === '') {
            return true;
        }

        $type = $field['type'];

        if ($field['multiple'] && str_starts_with($type, 'dropdown')) {
            $decoded = json_decode($value, true);

            return !is_array($decoded) || self::isEmptyFieldValue($decoded, $field);
        }

        // "Yes/no" stores 0/1 and both are answers: only a missing value is empty.
        if ($type === 'yesno') {
            return false;
        }

        // Dropdowns store an id: 0 means nothing selected.
        if ($type === 'glpi_item' || str_starts_with($type, 'dropdown')) {
            return (int) $value <= 0;
        }

        if ($type === 'date' || $type === 'datetime') {
            return !self::hasDate($value);
        }

        if ($type === 'richtext' || $type === 'textarea') {
            return !self::hasText($value);
        }

        return false;
    }

    private static function hasActor(
        CommonITILObject $item,
        array $input,
        string $role,
        int $type,
        string $actor_itemtype
    ): bool {
        $from_input = self::actorFromInput($input, $role, $actor_itemtype);
        if ($from_input !== null) {
            return $from_input;
        }

        if ($item->isNewItem()) {
            return false;
        }

        $link_class = match ($actor_itemtype) {
            'User'     => $item->userlinkclass,
            'Group'    => $item->grouplinkclass,
            'Supplier' => $item->supplierlinkclass,
        };

        return countElementsInTable(getTableForItemType($link_class), [
            $item->getForeignKeyField() => $item->getID(),
            'type'                      => $type,
        ]) > 0;
    }

    /**
     * Actors as set by the request itself, or null when the request does not
     * touch this role.
     *
     * The ITIL form posts `_actors` with the complete set of every role it shows
     * (an emptied role is an empty list); the API and the mail collector use the
     * older `_users_id_<role>` style, which only ever adds.
     */
    private static function actorFromInput(array $input, string $role, string $actor_itemtype): ?bool
    {
        $actors = $input['_actors'] ?? null;
        if (is_string($actors)) {
            $actors = json_decode(Compat::plain($actors), true);
        }

        if (is_array($actors) && array_key_exists($role, $actors)) {
            foreach ((array) $actors[$role] as $actor) {
                if (!is_array($actor) || ($actor['itemtype'] ?? '') !== $actor_itemtype) {
                    continue;
                }
                if ((int) ($actor['items_id'] ?? 0) > 0) {
                    return true;
                }
                // Requester or observer known by e-mail only.
                if ($actor_itemtype === 'User' && !empty($actor['alternative_email'])) {
                    return true;
                }
            }

            return false;
        }

        $legacy_key = match ($actor_itemtype) {
            'User'     => '_users_id_' . $role,
            'Group'    => '_groups_id_' . $role,
            'Supplier' => '_suppliers_id_' . $role,
        };

        $legacy = $input[$legacy_key] ?? null;
        if (is_array($legacy) ? array_filter(array_map('intval', $legacy)) !== [] : (int) $legacy > 0) {
            return true;
        }

        return null;
    }

    private static function hasLinkedItem(CommonITILObject $item, array $input): bool
    {
        // The ITIL form posts the associated items as [itemtype => [ids]].
        if (isset($input['items_id']) && is_array($input['items_id'])) {
            foreach ($input['items_id'] as $ids) {
                if (array_filter(array_map('intval', (array) $ids)) !== []) {
                    return true;
                }
            }
        }

        $link = match ($item->getType()) {
            'Ticket'  => ['glpi_items_tickets', 'tickets_id'],
            'Change'  => ['glpi_changes_items', 'changes_id'],
            'Problem' => ['glpi_items_problems', 'problems_id'],
            default   => null,
        };

        return $link !== null && countElementsInTable($link[0], [$link[1] => $item->getID()]) > 0;
    }

    private static function countTasks(CommonITILObject $item, bool $only_todo): int
    {
        $task_class = $item->getType() . 'Task';
        if (!class_exists($task_class)) {
            return 0;
        }

        $where = [$item->getForeignKeyField() => $item->getID()];
        if ($only_todo) {
            $where['state'] = Planning::TODO;
        }

        return countElementsInTable(getTableForItemType($task_class), $where);
    }

    private static function latestSolution(CommonITILObject $item): ?array
    {
        global $DB;

        $row = $DB->request([
            'FROM'  => 'glpi_itilsolutions',
            'WHERE' => [
                'itemtype' => $item->getType(),
                'items_id' => $item->getID(),
                'NOT'      => ['status' => CommonITILValidation::REFUSED],
            ],
            'ORDER' => 'id DESC',
            'LIMIT' => 1,
        ])->current();

        return $row ?: null;
    }

    /**
     * Files uploaded with the request: attachments and pasted images.
     */
    private static function hasUploadedFiles(array $input): bool
    {
        foreach (['_filename', '_content', '_documents_id'] as $key) {
            $value = $input[$key] ?? null;
            if (is_array($value) && array_filter($value, static fn ($v) => $v !== '' && $v !== null) !== []) {
                return true;
            }
        }

        return false;
    }

    private static function hasText(mixed $html): bool
    {
        $html = Compat::plain((string) $html);
        if (stripos($html, '<img') !== false) {
            return true;
        }

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\s\x{00A0}]+/u', '', $text);

        return $text !== null && $text !== '';
    }

    private static function hasDate(mixed $value): bool
    {
        $value = trim((string) $value);

        return $value !== '' && $value !== 'NULL' && !str_starts_with($value, '0000-00-00');
    }

    /**
     * Input values that can override a stored column: arrays (actors, uploads...)
     * are handled separately by each check.
     */
    private static function scalarInput(array $input): array
    {
        return array_filter($input, static fn ($value, $key) => is_scalar($value) && is_string($key) && $key[0] !== '_', ARRAY_FILTER_USE_BOTH);
    }
}
