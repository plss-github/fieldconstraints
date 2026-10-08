<?php

namespace GlpiPlugin\Fieldconstraints;

use Ajax;
use CommonDBChild;
use CommonGLPI;
use CommonITILActor;
use CommonITILObject;
use Dropdown;
use Entity;
use Group;
use Html;
use ITILCategory;
use Location;
use Profile;
use RequestType;
use Session;
use Ticket;
use User;

/**
 * One condition of an item rule ("Filters" tab): "category is under X",
 * "requester group is not in [A, B]", "group of the user is G"...
 *
 * A rule applies when its filters match, combined with the rule's
 * `filter_logic` (AND / OR); a rule without filters applies to every item.
 *
 * Every criterion is read as a set of ids on the item side (a ticket has one
 * category but may have several requester groups), so the operators read the
 * same everywhere:
 *
 *  - equals / notequals : the value is / is not one of the item's;
 *  - in / notin         : at least one / none of the item's is in the list;
 *  - under / notunder   : same as in / notin, a tree value covering its children
 *                         (category, entity, location, group).
 */
class RuleFilter extends CommonDBChild
{
    use ConfigRights;

    public static $itemtype = ActionRule::class;
    public static $items_id = 'plugin_fieldconstraints_actionrules_id';

    public static $rightname = 'config';

    public $dohistory = true;

    public const LOGIC_AND = 'AND';
    public const LOGIC_OR  = 'OR';

    public const EQUALS    = 'equals';
    public const NOTEQUALS = 'notequals';
    public const IN        = 'in';
    public const NOTIN     = 'notin';
    public const UNDER     = 'under';
    public const NOTUNDER  = 'notunder';

    public static function getTypeName($nb = 0)
    {
        return _n('Filter', 'Filters', $nb, 'fieldconstraints');
    }

    public static function getIcon()
    {
        return 'ti ti-filter';
    }

    // ------------------------------------------------------------- catalogue

    /**
     * Criteria a filter may test, for one itemtype.
     *
     * - `source`:    a dropdown itemtype, or null for a fixed list (`choices`);
     * - `tree`:      the values form a tree (enables under / notunder);
     * - `itemtypes`: items having the criterion (null = every ITIL object).
     *
     * @return array<string, array{label: string, group: string, source: ?string, tree: bool,
     *                             itemtypes: ?string[], choices?: array<int, string>}>
     */
    public static function getCriteria(?string $itemtype = null): array
    {
        $item_group    = __('Item', 'fieldconstraints');
        $actors_group  = __('Actors', 'fieldconstraints');
        $session_group = __('User performing the action', 'fieldconstraints');

        $criteria = [
            'entities_id'       => ['label' => __('Entity', 'fieldconstraints'), 'group' => $item_group, 'source' => Entity::class, 'tree' => true, 'itemtypes' => null],
            'itilcategories_id' => ['label' => __('Category', 'fieldconstraints'), 'group' => $item_group, 'source' => ITILCategory::class, 'tree' => true, 'itemtypes' => null],
            'type'              => ['label' => __('Ticket type', 'fieldconstraints'), 'group' => $item_group, 'source' => null, 'tree' => false, 'itemtypes' => ['Ticket']],
            'requesttypes_id'   => ['label' => __('Request source', 'fieldconstraints'), 'group' => $item_group, 'source' => RequestType::class, 'tree' => false, 'itemtypes' => ['Ticket']],
            'locations_id'      => ['label' => __('Location', 'fieldconstraints'), 'group' => $item_group, 'source' => Location::class, 'tree' => true, 'itemtypes' => null],
            'urgency'           => ['label' => __('Urgency', 'fieldconstraints'), 'group' => $item_group, 'source' => null, 'tree' => false, 'itemtypes' => null],
            'impact'            => ['label' => __('Impact', 'fieldconstraints'), 'group' => $item_group, 'source' => null, 'tree' => false, 'itemtypes' => null],
            'priority'          => ['label' => __('Priority', 'fieldconstraints'), 'group' => $item_group, 'source' => null, 'tree' => false, 'itemtypes' => null],

            'requester_user'    => ['label' => __('Requester', 'fieldconstraints'), 'group' => $actors_group, 'source' => User::class, 'tree' => false, 'itemtypes' => null],
            'requester_group'   => ['label' => __('Requester group', 'fieldconstraints'), 'group' => $actors_group, 'source' => Group::class, 'tree' => true, 'itemtypes' => null],
            'assign_user'       => ['label' => __('Assigned technician', 'fieldconstraints'), 'group' => $actors_group, 'source' => User::class, 'tree' => false, 'itemtypes' => null],
            'assign_group'      => ['label' => __('Assigned group', 'fieldconstraints'), 'group' => $actors_group, 'source' => Group::class, 'tree' => true, 'itemtypes' => null],

            'session_user'      => ['label' => __('User', 'fieldconstraints'), 'group' => $session_group, 'source' => User::class, 'tree' => false, 'itemtypes' => null],
            'session_group'     => ['label' => __('Group of the user', 'fieldconstraints'), 'group' => $session_group, 'source' => Group::class, 'tree' => true, 'itemtypes' => null],
            'session_profile'   => ['label' => __('Profile of the user', 'fieldconstraints'), 'group' => $session_group, 'source' => Profile::class, 'tree' => false, 'itemtypes' => null],
        ];

        $criteria['type']['choices']     = Ticket::getTypes();
        $criteria['urgency']['choices']  = self::scale([CommonITILObject::class, 'getUrgencyName'], 5);
        $criteria['impact']['choices']   = self::scale([CommonITILObject::class, 'getImpactName'], 5);
        $criteria['priority']['choices'] = self::scale([CommonITILObject::class, 'getPriorityName'], 6);

        if ($itemtype === null) {
            return $criteria;
        }

        return array_filter(
            $criteria,
            static fn (array $criterion): bool => $criterion['itemtypes'] === null || in_array($itemtype, $criterion['itemtypes'], true)
        );
    }

    /**
     * @return array<int, string>
     */
    private static function scale(callable $namer, int $max): array
    {
        $choices = [];
        for ($value = $max; $value >= 1; $value--) {
            $choices[$value] = (string) $namer($value);
        }

        return $choices;
    }

    /**
     * @return array<string, string>
     */
    public static function getOperators(?string $criterion = null): array
    {
        $operators = [
            self::EQUALS    => __('is', 'fieldconstraints'),
            self::NOTEQUALS => __('is not', 'fieldconstraints'),
            self::IN        => __('is in', 'fieldconstraints'),
            self::NOTIN     => __('is not in', 'fieldconstraints'),
            self::UNDER     => __('is under', 'fieldconstraints'),
            self::NOTUNDER  => __('is not under', 'fieldconstraints'),
        ];

        $definition = $criterion === null ? null : (self::getCriteria()[$criterion] ?? null);
        if ($definition !== null && !$definition['tree']) {
            unset($operators[self::UNDER], $operators[self::NOTUNDER]);
        }

        return $operators;
    }

    public static function isSingleValue(string $operator): bool
    {
        return in_array($operator, [self::EQUALS, self::NOTEQUALS], true);
    }

    /**
     * @return array<string, string>
     */
    public static function getLogics(): array
    {
        return [
            self::LOGIC_AND => __('All filters must match (AND)', 'fieldconstraints'),
            self::LOGIC_OR  => __('At least one filter must match (OR)', 'fieldconstraints'),
        ];
    }

    // -------------------------------------------------------------- decoding

    /**
     * @return int[]
     */
    public static function decodeValues(mixed $stored): array
    {
        if (!is_string($stored) || $stored === '') {
            return [];
        }

        $ids = json_decode(Compat::plain($stored), true);

        return is_array($ids) ? array_values(array_map('intval', array_filter($ids, 'is_numeric'))) : [];
    }

    public static function describeValue(string $criterion, int $value): string
    {
        $definition = self::getCriteria()[$criterion] ?? null;
        if ($definition === null) {
            return (string) $value;
        }

        if ($definition['source'] === null) {
            return (string) ($definition['choices'][$value] ?? $value);
        }

        if ($definition['source'] === User::class) {
            return Compat::plain((string) getUserName($value));
        }

        return Compat::plain((string) Dropdown::getDropdownName(getTableForItemType($definition['source']), $value));
    }

    /**
     * "Category is under [A, B]".
     */
    public static function describe(array $filter): string
    {
        $criterion = (string) $filter['criterion'];
        $label     = self::getCriteria()[$criterion]['label'] ?? $criterion;
        $operator  = self::getOperators()[(string) $filter['operator']] ?? (string) $filter['operator'];
        $values    = array_map(
            static fn (int $value): string => self::describeValue($criterion, $value),
            self::decodeValues($filter['filter_values'] ?? null)
        );

        $text = self::isSingleValue((string) $filter['operator'])
            ? implode(', ', $values)
            : '[' . implode(', ', $values) . ']';

        return sprintf('%s %s %s', $label, $operator, $text);
    }

    /**
     * Summary of a rule's filters for lists: "All items" or the filters joined
     * by the rule's logic.
     *
     * @param array<int, array> $filters
     */
    public static function summarize(array $filters, string $logic): string
    {
        if ($filters === []) {
            return __('All items', 'fieldconstraints');
        }

        $glue = $logic === self::LOGIC_OR ? __('OR', 'fieldconstraints') : __('AND', 'fieldconstraints');

        return implode(' ' . $glue . ' ', array_map([self::class, 'describe'], $filters));
    }

    /**
     * Filters of rules, by rule id.
     *
     * @param int[] $rule_ids
     *
     * @return array<int, array<int, array>>
     */
    public static function getForRules(array $rule_ids): array
    {
        global $DB;

        $rule_ids = array_values(array_unique(array_filter(array_map('intval', $rule_ids))));
        if ($rule_ids === [] || !$DB->tableExists(self::getTable())) {
            return [];
        }

        $filters = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => [self::$items_id => $rule_ids], 'ORDER' => 'id']) as $row) {
            $filters[(int) $row[self::$items_id]][] = $row;
        }

        return $filters;
    }

    public static function normalizeLogic(mixed $logic): string
    {
        return strtoupper((string) $logic) === self::LOGIC_OR ? self::LOGIC_OR : self::LOGIC_AND;
    }

    // ------------------------------------------------------------ evaluation

    /**
     * Whether a rule's filters match the item.
     *
     * @param array<int, array> $filters
     * @param array             $state   the item as it will be after the write
     * @param array             $input   the update's input (actors set by the same request)
     */
    public static function matchAll(array $filters, string $logic, CommonITILObject $item, array $state, array $input): bool
    {
        if ($filters === []) {
            return true;
        }

        $is_or = self::normalizeLogic($logic) === self::LOGIC_OR;
        $cache = [];
        foreach ($filters as $filter) {
            $criterion = (string) $filter['criterion'];
            $cache[$criterion] ??= self::itemValues($criterion, $item, $state, $input);

            $match = self::matchOne($filter, $cache[$criterion]);
            if ($is_or && $match) {
                return true;
            }
            if (!$is_or && !$match) {
                return false;
            }
        }

        return !$is_or;
    }

    /**
     * @param int[]|null $item_values null = criterion unknown (stored by a newer version?)
     */
    private static function matchOne(array $filter, ?array $item_values): bool
    {
        $criterion = (string) $filter['criterion'];
        $operator  = (string) $filter['operator'];
        $values    = self::decodeValues($filter['filter_values'] ?? null);

        // Never let an unreadable filter decide: it neither restricts nor excludes.
        if ($item_values === null || !isset(self::getOperators()[$operator])) {
            return true;
        }

        if (in_array($operator, [self::UNDER, self::NOTUNDER], true)) {
            $source = self::getCriteria()[$criterion]['source'] ?? null;
            if ($source !== null) {
                $table = getTableForItemType($source);
                foreach ($item_values as $value) {
                    $item_values = array_merge($item_values, array_map('intval', array_values(getAncestorsOf($table, $value))));
                }
            }
        }

        $found = array_intersect($values, $item_values) !== [];

        return match ($operator) {
            self::EQUALS, self::IN, self::UNDER          => $found,
            self::NOTEQUALS, self::NOTIN, self::NOTUNDER => !$found,
        };
    }

    /**
     * The item's values for a criterion, as a set of ids.
     *
     * @return int[]|null
     */
    private static function itemValues(string $criterion, CommonITILObject $item, array $state, array $input): ?array
    {
        $ids = static fn (array $values): array => array_values(array_unique(array_filter(array_map('intval', $values), static fn (int $id): bool => $id > 0)));

        switch ($criterion) {
            case 'entities_id':
                // The root entity is 0.
                return [(int) ($state['entities_id'] ?? 0)];

            case 'itilcategories_id':
            case 'type':
            case 'requesttypes_id':
            case 'locations_id':
            case 'urgency':
            case 'impact':
            case 'priority':
                return $ids([$state[$criterion] ?? 0]);

            case 'requester_user':
                return $ids(self::actorIds($item, $input, 'requester', CommonITILActor::REQUESTER, 'User'));
            case 'requester_group':
                return $ids(self::actorIds($item, $input, 'requester', CommonITILActor::REQUESTER, 'Group'));
            case 'assign_user':
                return $ids(self::actorIds($item, $input, 'assign', CommonITILActor::ASSIGN, 'User'));
            case 'assign_group':
                return $ids(self::actorIds($item, $input, 'assign', CommonITILActor::ASSIGN, 'Group'));

            // No session (cron, CLI): no user, so "is" filters fail and "is not" pass.
            case 'session_user':
                return $ids([Session::getLoginUserID() ?: 0]);
            case 'session_group':
                return $ids((array) ($_SESSION['glpigroups'] ?? []));
            case 'session_profile':
                return $ids([$_SESSION['glpiactiveprofile']['id'] ?? 0]);
        }

        return null;
    }

    /**
     * Actors of a role: as set by the request when it touches the role (the
     * ITIL form posts the full set in `_actors`), stored ones otherwise, plus
     * those the API style `_users_id_<role>` adds.
     *
     * @return int[]
     */
    private static function actorIds(CommonITILObject $item, array $input, string $role, int $type, string $actor_itemtype): array
    {
        $actors = $input['_actors'] ?? null;
        if (is_string($actors)) {
            $actors = json_decode(Compat::plain($actors), true);
        }
        if (is_array($actors) && array_key_exists($role, $actors)) {
            $ids = [];
            foreach ((array) $actors[$role] as $actor) {
                if (is_array($actor) && ($actor['itemtype'] ?? '') === $actor_itemtype) {
                    $ids[] = (int) ($actor['items_id'] ?? 0);
                }
            }

            return $ids;
        }

        $ids = [];
        if (!$item->isNewItem()) {
            [$link_class, $fk] = match ($actor_itemtype) {
                'User'  => [$item->userlinkclass, 'users_id'],
                'Group' => [$item->grouplinkclass, 'groups_id'],
            };

            global $DB;
            foreach ($DB->request([
                'SELECT' => [$fk],
                'FROM'   => getTableForItemType($link_class),
                'WHERE'  => [$item->getForeignKeyField() => $item->getID(), 'type' => $type],
            ]) as $row) {
                $ids[] = (int) $row[$fk];
            }
        }

        $legacy_key = ($actor_itemtype === 'User' ? '_users_id_' : '_groups_id_') . $role;
        foreach ((array) ($input[$legacy_key] ?? []) as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    // ----------------------------------------------------------------- input

    public function prepareInputForAdd($input)
    {
        $rule = new ActionRule();
        if (!$rule->getFromDB((int) ($input[self::$items_id] ?? 0))) {
            return false;
        }

        $input = $this->prepareFilterInput($input, (string) $rule->fields['itemtype']);

        return $input === false ? false : parent::prepareInputForAdd($input);
    }

    public function prepareInputForUpdate($input)
    {
        unset($input[self::$items_id]);

        $rule = new ActionRule();
        if (!$rule->getFromDB((int) $this->fields[self::$items_id])) {
            return false;
        }

        $input = $this->prepareFilterInput($input + $this->fields, (string) $rule->fields['itemtype']);

        return $input === false ? false : parent::prepareInputForUpdate($input);
    }

    /**
     * @return array|false
     */
    private function prepareFilterInput(array $input, string $itemtype)
    {
        $criterion = (string) ($input['criterion'] ?? '');
        if (!isset(self::getCriteria($itemtype)[$criterion])) {
            return self::refuse(__('Choose a criterion that applies to the item of the rule.', 'fieldconstraints'));
        }

        $operator = (string) ($input['operator'] ?? '');
        if (!isset(self::getOperators($criterion)[$operator])) {
            return self::refuse(__('Choose an operator.', 'fieldconstraints'));
        }

        $values = $input['filter_values'] ?? [];
        if (is_string($values)) {
            // Stored JSON (API, update without a new value) or a single select.
            $decoded = json_decode(Compat::plain($values), true);
            $values  = is_array($decoded) ? $decoded : ($values === '' ? [] : [$values]);
        }
        $values = array_values(array_unique(array_map('intval', array_filter((array) $values, 'is_numeric'))));
        // 0 is a real entity (the root one), and nothing else.
        if ($criterion !== 'entities_id') {
            $values = array_values(array_filter($values, static fn (int $id): bool => $id > 0));
        }

        if ($values === []) {
            return self::refuse(__('Choose at least one value.', 'fieldconstraints'));
        }
        if (self::isSingleValue($operator) && count($values) > 1) {
            return self::refuse(__('"is" and "is not" take a single value: use "is in" / "is not in" for a list.', 'fieldconstraints'));
        }

        $input['criterion']     = $criterion;
        $input['operator']      = $operator;
        $input['filter_values'] = json_encode($values);

        return $input;
    }

    private static function refuse(string $message): bool
    {
        Session::addMessageAfterRedirect(Compat::escape($message), false, ERROR);

        return false;
    }

    // -------------------------------------------------------- "Filters" tab

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof ActionRule || $item->isNewItem()) {
            return '';
        }

        $count = ($_SESSION['glpishow_count_on_tabs'] ?? false)
            ? countElementsInTable(self::getTable(), [self::$items_id => $item->getID()])
            : 0;

        return self::createTabEntry(self::getTypeName(2), $count);
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof ActionRule) {
            self::showForRule($item);
        }

        return true;
    }

    private static function showForRule(ActionRule $rule): void
    {
        $canedit = self::canCreate();
        $filters = self::getForRules([$rule->getID()])[$rule->getID()] ?? [];
        $logic   = self::normalizeLogic($rule->fields['filter_logic'] ?? self::LOGIC_AND);
        $rand    = mt_rand();

        echo '<div class="p-3">';

        // Logic, above the list: one choice for the whole rule.
        echo '<form method="post" action="' . Compat::escape(ActionRule::getFormURL()) . '" class="d-flex flex-wrap align-items-center gap-2 mb-3">';
        echo Html::hidden('id', ['value' => $rule->getID()]);
        echo '<label class="fw-bold mb-0" for="filter_logic' . $rand . '">' . Compat::escape(__('The rule applies when', 'fieldconstraints')) . '</label>';
        $choices = array_map([Compat::class, 'dropdownLabel'], self::getLogics());
        if ($canedit) {
            Dropdown::showFromArray('filter_logic', $choices, ['value' => $logic, 'rand' => $rand, 'width' => '22rem']);
            echo '<button type="submit" name="update" value="1" class="btn btn-outline-primary btn-sm">'
                . '<i class="ti ti-device-floppy me-1"></i>' . Compat::escape(_sx('button', 'Save')) . '</button>';
        } else {
            echo '<span>' . Compat::escape(self::getLogics()[$logic]) . '</span>';
        }
        Html::closeForm();

        echo '<table class="table table-hover card-table"><thead><tr>'
            . '<th style="width: 4rem;"></th>'
            . '<th>' . Compat::escape(__('Criterion', 'fieldconstraints')) . '</th>'
            . '<th>' . Compat::escape(__('Operator', 'fieldconstraints')) . '</th>'
            . '<th>' . Compat::escape(__('Values', 'fieldconstraints')) . '</th>'
            . ($canedit ? '<th style="width: 1%;"></th>' : '')
            . '</tr></thead><tbody>';

        if ($filters === []) {
            echo '<tr><td colspan="5" class="text-muted">'
                . Compat::escape(__('No filter: the rule applies to every item (within the block\'s entity).', 'fieldconstraints'))
                . '</td></tr>';
        }

        $glue     = $logic === self::LOGIC_OR ? __('OR', 'fieldconstraints') : __('AND', 'fieldconstraints');
        $criteria = self::getCriteria();
        foreach (array_values($filters) as $index => $filter) {
            $criterion = (string) $filter['criterion'];
            $values    = array_map(
                static fn (int $value): string => self::describeValue($criterion, $value),
                self::decodeValues($filter['filter_values'])
            );
            $label = $criteria[$criterion]['label'] ?? $criterion;
            $link  = self::getFormURLWithID((int) $filter['id']);

            echo '<tr>'
                . '<td class="text-muted small">' . ($index === 0 ? Compat::escape(__('If', 'fieldconstraints')) : Compat::escape($glue)) . '</td>'
                . '<td>' . ($canedit ? '<a href="' . Compat::escape($link) . '">' . Compat::escape($label) . '</a>' : Compat::escape($label)) . '</td>'
                . '<td>' . Compat::escape(self::getOperators()[(string) $filter['operator']] ?? (string) $filter['operator']) . '</td>'
                . '<td>' . implode('', array_map(static fn (string $v): string => '<span class="badge bg-secondary-lt me-1">' . Compat::escape($v) . '</span>', $values)) . '</td>';
            if ($canedit) {
                echo '<td><form method="post" action="' . Compat::escape(self::getFormURL()) . '" class="m-0">'
                    . Html::hidden('id', ['value' => (int) $filter['id']])
                    . '<button type="submit" name="purge" value="1" class="btn btn-sm btn-ghost-danger" title="' . Compat::escape(_x('button', 'Delete permanently')) . '">'
                    . '<i class="ti ti-trash"></i></button>';
                Html::closeForm();
                echo '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';

        if ($canedit) {
            echo '<div class="card mt-3"><div class="card-header"><h4 class="card-title">'
                . Compat::escape(__('Add a filter', 'fieldconstraints')) . '</h4></div><div class="card-body">';
            echo '<form method="post" action="' . Compat::escape(self::getFormURL()) . '">';
            echo Html::hidden(self::$items_id, ['value' => $rule->getID()]);
            self::showEditor((string) $rule->fields['itemtype'], null);
            echo '<button type="submit" name="add" value="1" class="btn btn-primary mt-2">'
                . '<i class="ti ti-plus me-1"></i>' . Compat::escape(_x('button', 'Add')) . '</button>';
            Html::closeForm();
            echo '</div></div>';
        }

        echo '</div>';
    }

    /**
     * Criterion, operator and value widgets; the operator and the value follow
     * the criterion (and the value the operator: one value for "is").
     */
    private static function showEditor(string $itemtype, ?array $filter): void
    {
        $rand      = mt_rand();
        $criteria  = self::getCriteria($itemtype);
        $criterion = (string) ($filter['criterion'] ?? array_key_first($criteria));
        $operator  = (string) ($filter['operator'] ?? self::EQUALS);

        $choices = [];
        foreach ($criteria as $key => $definition) {
            $choices[Compat::dropdownLabel($definition['group'])][$key] = Compat::dropdownLabel($definition['label']);
        }

        echo '<div class="row g-2 align-items-start">';
        echo '<div class="col-12 col-md-4"><label class="form-label">' . Compat::escape(__('Criterion', 'fieldconstraints')) . '</label>';
        Dropdown::showFromArray('criterion', $choices, ['value' => $criterion, 'rand' => $rand, 'width' => '100%']);
        echo '</div>';

        echo '<div class="col-12 col-md-3"><label class="form-label">' . Compat::escape(__('Operator', 'fieldconstraints')) . '</label>'
            . '<div id="fieldconstraints_filter_operator' . $rand . '">';
        self::showOperatorDropdown($criterion, $operator, $rand);
        echo '</div></div>';

        echo '<div class="col-12 col-md-5"><label class="form-label">' . Compat::escape(__('Values', 'fieldconstraints')) . '</label>'
            . '<div id="fieldconstraints_filter_values' . $rand . '">';
        self::showValuesDropdown($criterion, $operator, self::decodeValues($filter['filter_values'] ?? null));
        echo '</div></div>';
        echo '</div>';

        $url = Compat::webDir() . '/ajax/rulefilter.php';
        // Criterion changed: new operator list, then (from that dropdown's own
        // script) new values.
        Ajax::updateItemOnSelectEvent(
            'dropdown_criterion' . $rand,
            'fieldconstraints_filter_operator' . $rand,
            $url,
            ['part' => 'operator', 'criterion' => '__VALUE__', 'rand' => $rand, 'itemtype' => $itemtype]
        );
    }

    /**
     * Operator dropdown; changing it (or rendering it for a new criterion)
     * refreshes the value widget.
     */
    public static function showOperatorDropdown(string $criterion, string $operator, int $rand, bool $refresh_values = false): void
    {
        $operators = self::getOperators($criterion);
        if (!isset($operators[$operator])) {
            $operator = self::EQUALS;
        }

        $operator_rand = mt_rand();
        Dropdown::showFromArray('operator', array_map([Compat::class, 'dropdownLabel'], $operators), [
            'value' => $operator,
            'rand'  => $operator_rand,
            'width' => '100%',
        ]);

        $params = [
            'part'      => 'values',
            'criterion' => $criterion,
            'operator'  => '__VALUE__',
        ];
        $url = Compat::webDir() . '/ajax/rulefilter.php';
        Ajax::updateItemOnSelectEvent('dropdown_operator' . $operator_rand, 'fieldconstraints_filter_values' . $rand, $url, $params);

        if ($refresh_values) {
            Ajax::updateItem('fieldconstraints_filter_values' . $rand, $url, ['operator' => $operator] + $params);
        }
    }

    /**
     * @param int[] $selected
     */
    public static function showValuesDropdown(string $criterion, string $operator, array $selected): void
    {
        $definition = self::getCriteria()[$criterion] ?? null;
        if ($definition === null) {
            return;
        }

        $multiple = !self::isSingleValue($operator);
        $name     = $multiple ? 'filter_values[]' : 'filter_values';
        $value    = $multiple ? $selected : ($selected[0] ?? 0);

        if ($definition['source'] === null) {
            $choices = array_map([Compat::class, 'dropdownLabel'], $definition['choices']);
            Dropdown::showFromArray('filter_values', $choices, $multiple
                ? ['multiple' => true, 'values' => $selected, 'width' => '100%']
                : ['value' => $value, 'width' => '100%']);
            return;
        }

        if ($definition['source'] === User::class) {
            User::dropdown([
                'name'     => $name,
                'value'    => $value,
                'multiple' => $multiple,
                'right'    => 'all',
                'width'    => '100%',
            ]);
            return;
        }

        $options = [
            'name'     => $name,
            'value'    => $value,
            'multiple' => $multiple,
            'width'    => '100%',
        ];
        // Anything the administrator can see, whatever the block's entity.
        $options['entity'] = $_SESSION['glpiactiveentities'] ?? 0;
        if ($definition['source'] === Entity::class) {
            // The root entity is a value (id 0), not "nothing".
            $options['display_emptychoice'] = false;
        }
        Dropdown::show($definition['source'], $options);
    }

    // ------------------------------------------------------------------ form

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab(\Log::class, $tabs, $options);

        return $tabs;
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);

        $rule = new ActionRule();
        if (!$rule->getFromDB((int) ($this->fields[self::$items_id] ?? 0))) {
            echo '<div class="alert alert-warning">' . Compat::escape(__('Choose the rule of the filter.', 'fieldconstraints')) . '</div>';
            return false;
        }

        $this->showFormHeader($options);
        echo '<tr class="tab_bg_1"><td>' . Compat::escape(ActionRule::getTypeName(1)) . '</td><td colspan="3">'
            . '<a href="' . Compat::escape($rule->getLinkURL()) . '">' . Compat::escape($rule->getName()) . '</a>'
            . '</td></tr>';
        echo '<tr class="tab_bg_1"><td colspan="4">';
        self::showEditor((string) $rule->fields['itemtype'], $this->fields);
        echo '</td></tr>';
        $this->showFormButtons($options);

        return true;
    }

    /**
     * "<rule> - <criterion>".
     */
    public function getName($options = [])
    {
        if ($this->isNewItem()) {
            return parent::getName($options);
        }

        return self::describe($this->fields);
    }
}
