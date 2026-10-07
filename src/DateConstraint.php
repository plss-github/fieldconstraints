<?php

namespace GlpiPlugin\Fieldconstraints;

use CommonDBTM;
use CommonGLPI;
use Dropdown;
use Html;

/**
 * A date constraint: "<date field> cannot be before|after <today | a date |
 * another date field>".
 *
 * It belongs to where the date field is defined, not to where it is shown:
 *  - a Fields container (source `fields`): it applies to that field on every
 *    item and tab the container appears in;
 *  - a Formcreator form (`formcreator`, GLPI 10) or a native form (`native`,
 *    GLPI 11): it applies to that question.
 *
 * Managed from a "Date constraints" tab of the container or form; the other
 * date field of a constraint is always from the same container or form.
 */
class DateConstraint extends CommonDBTM
{
    public const SOURCE_FIELDS = 'fields';

    public const MIN = 'min';
    public const MAX = 'max';

    public const REF_TODAY = 'today';
    public const REF_DATE  = 'date';
    public const REF_FIELD = 'field';

    public static $rightname = 'config';

    public $dohistory = false;

    public static function getTypeName($nb = 0)
    {
        return _n('Date constraint', 'Date constraints', $nb, 'fieldconstraints');
    }

    public static function getIcon()
    {
        return 'ti ti-calendar-cog';
    }

    // ---------------------------------------------------------------- parents

    /**
     * @return array<string, string> source => parent class, for what is installed
     */
    public static function getParentClasses(): array
    {
        $classes = [];
        if (FieldCatalog::isFieldsPluginAvailable()) {
            $classes[self::SOURCE_FIELDS] = 'PluginFieldsContainer';
        }

        $engine = FormEngine::current();
        if ($engine === FormEngine::FORMCREATOR) {
            $classes[FormEngine::FORMCREATOR] = 'PluginFormcreatorForm';
        } elseif ($engine === FormEngine::NATIVE) {
            $classes[FormEngine::NATIVE] = 'Glpi\\Form\\Form';
        }

        return $classes;
    }

    public static function sourceFor(CommonGLPI $item): ?string
    {
        $source = array_search($item::class, self::getParentClasses(), true);

        return $source === false ? null : (string) $source;
    }

    /**
     * Date fields of a container, or date questions of a form.
     *
     * @return array<string, string> key => label
     */
    public static function getDateFields(string $source, int $parent_id): array
    {
        global $DB;

        if ($source !== self::SOURCE_FIELDS) {
            return FormEngine::getDateQuestions($source, $parent_id);
        }

        if (!FieldCatalog::isFieldsPluginAvailable()) {
            return [];
        }

        $fields = [];
        $iterator = $DB->request([
            'FROM'  => 'glpi_plugin_fields_fields',
            'WHERE' => [
                'plugin_fields_containers_id' => $parent_id,
                'type'                        => ['date', 'datetime'],
                'is_active'                   => 1,
            ],
            'ORDER' => 'ranking',
        ]);
        foreach ($iterator as $row) {
            $fields[FieldCatalog::FIELDS . ':' . $row['id']] = Compat::plain($row['label']);
        }

        return $fields;
    }

    /**
     * Name of the input holding a field's value in the forms.
     */
    public static function inputName(string $source, string $key): ?string
    {
        [$kind, $id] = array_pad(explode(':', $key, 2), 2, '');

        if ($source === self::SOURCE_FIELDS) {
            $field = FieldCatalog::getAdditionalField((int) $id);

            return $field !== null ? FieldCatalog::fieldsColumn($field) : null;
        }

        return $kind === 'question' ? FormEngine::inputName($source, (int) $id) : null;
    }

    /**
     * @return array<int, array>
     */
    public static function getForParent(string $source, int $parent_id, bool $only_active = true): array
    {
        global $DB;

        if ($parent_id <= 0 || !$DB->tableExists(self::getTable())) {
            return [];
        }

        $where = ['source' => $source, 'items_id' => $parent_id];
        if ($only_active) {
            $where['is_active'] = 1;
        }

        $rows = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => $where, 'ORDER' => 'id']) as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    // -------------------------------------------------------------- semantics

    public static function getOperators(): array
    {
        return [
            self::MIN => __('cannot be before', 'fieldconstraints'),
            self::MAX => __('cannot be after', 'fieldconstraints'),
        ];
    }

    public static function getReferences(): array
    {
        return [
            self::REF_TODAY => __('today', 'fieldconstraints'),
            self::REF_DATE  => __('a given date', 'fieldconstraints'),
            self::REF_FIELD => __('another date field', 'fieldconstraints'),
        ];
    }

    /**
     * "Data de término não pode ser anterior a Data de início".
     */
    public static function describe(array $row, ?array $fields = null): string
    {
        $fields ??= self::getDateFields((string) $row['source'], (int) $row['items_id']);

        return sprintf(
            '%s %s %s',
            $fields[$row['field']] ?? (string) $row['field'],
            self::getOperators()[$row['operator']] ?? (string) $row['operator'],
            self::describeReference($row, $fields)
        );
    }

    private static function describeReference(array $row, array $fields): string
    {
        return match ($row['reference']) {
            self::REF_TODAY => __('today', 'fieldconstraints'),
            self::REF_DATE  => (string) Html::convDate((string) $row['ref_date']),
            self::REF_FIELD => $fields[$row['ref_field']] ?? (string) $row['ref_field'],
            default         => '',
        };
    }

    /**
     * Constraints that apply to a write.
     *
     * A comparison with another field takes precedence, when that field is
     * filled, over the fixed bounds (today, a given date) in the same direction:
     * with "end >= start" and "end >= today", an end between a past start and
     * today is accepted; with the start empty, "end >= today" applies again.
     *
     * @param array<int, array>                                $constraints
     * @param callable(string): array{0: ?string, 1: ?string} $read key => [new, old]
     *
     * @return array<int, array>
     */
    public static function applicable(array $constraints, callable $read): array
    {
        $by_field = [];
        foreach ($constraints as $row) {
            if ($row['reference'] === self::REF_FIELD && self::normalize($read((string) $row['ref_field'])[0]) !== null) {
                $by_field[$row['field'] . '|' . $row['operator']] = true;
            }
        }

        return array_values(array_filter($constraints, static function (array $row) use ($by_field): bool {
            return $row['reference'] === self::REF_FIELD
                || !isset($by_field[$row['field'] . '|' . $row['operator']]);
        }));
    }

    /**
     * What a write breaks in a constraint.
     *
     * Values already stored are not re-judged: against today or a given date,
     * a value that is kept stays accepted (a past date is not a reason to block
     * an unrelated edit); against another field, only a write touching either
     * field is checked.
     *
     * @param callable(string): array{0: ?string, 1: ?string} $read key => [new, old]
     *
     * @return string|null plain-text problem
     */
    public static function evaluate(array $row, callable $read, ?array $fields = null): ?string
    {
        [$new, $old] = $read((string) $row['field']);
        $new = self::normalize($new);
        $old = self::normalize($old);
        if ($new === null) {
            // Empty is a matter for "mandatory", not for this constraint.
            return null;
        }

        $changed = $new !== $old;
        switch ($row['reference']) {
            case self::REF_TODAY:
                $reference = date('Y-m-d');
                $value     = substr($new, 0, 10);
                break;
            case self::REF_DATE:
                $reference = self::normalize((string) $row['ref_date']);
                $value     = substr($new, 0, 10);
                break;
            case self::REF_FIELD:
                [$ref_new, $ref_old] = $read((string) $row['ref_field']);
                $reference = self::normalize($ref_new);
                $changed   = $changed || $reference !== self::normalize($ref_old);
                $value     = $new;
                break;
            default:
                return null;
        }

        if (!$changed || $reference === null) {
            return null;
        }

        $broken = $row['operator'] === self::MAX ? $value > $reference : $value < $reference;

        return $broken ? self::describe($row, $fields) . '.' : null;
    }

    /**
     * Comparable form of a date: `Y-m-d` or `Y-m-d H:i:s`.
     */
    public static function normalize(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || $value === 'NULL' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        $timestamp = strtotime(str_replace('T', ' ', $value));
        if ($timestamp === false) {
            return null;
        }

        return strlen($value) <= 10 ? date('Y-m-d', $timestamp) : date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Constraints for the date pickers of a page: every Fields constraint (the
     * page decides which inputs it has), or those of one form.
     *
     * @return array<int, array{input: string, operator: string, reference: string, ref_date: ?string, ref_input: ?string}>
     */
    public static function clientConfig(string $source, int $parent_id = 0): array
    {
        global $DB;

        if (!$DB->tableExists(self::getTable())) {
            return [];
        }

        $where = ['source' => $source, 'is_active' => 1];
        if ($source !== self::SOURCE_FIELDS) {
            $where['items_id'] = $parent_id;
        }

        $config = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => $where]) as $row) {
            $input = self::inputName($source, (string) $row['field']);
            if ($input === null) {
                continue;
            }
            $config[] = [
                'input'     => $input,
                'operator'  => (string) $row['operator'],
                'reference' => (string) $row['reference'],
                'ref_date'  => $row['reference'] === self::REF_DATE ? (string) $row['ref_date'] : null,
                'ref_input' => $row['reference'] === self::REF_FIELD
                    ? self::inputName($source, (string) $row['ref_field'])
                    : null,
            ];
        }

        return $config;
    }

    // ------------------------------------------------------------------ input

    public function prepareInputForAdd($input)
    {
        $source    = (string) ($input['source'] ?? '');
        $parent_id = (int) ($input['items_id'] ?? 0);
        $fields    = self::getDateFields($source, $parent_id);

        $field     = (string) ($input['field'] ?? '');
        $operator  = (string) ($input['operator'] ?? '');
        $reference = (string) ($input['reference'] ?? '');

        if (!isset($fields[$field])) {
            return self::refuse(__('Choose a date field.', 'fieldconstraints'));
        }
        if (!isset(self::getOperators()[$operator]) || !isset(self::getReferences()[$reference])) {
            return self::refuse(__('Choose the comparison.', 'fieldconstraints'));
        }

        $clean = [
            'source'    => $source,
            'items_id'  => $parent_id,
            'field'     => $field,
            'operator'  => $operator,
            'reference' => $reference,
            'ref_date'  => null,
            'ref_field' => '',
            'is_active' => 1,
        ];

        if ($reference === self::REF_DATE) {
            $date = self::normalize((string) ($input['ref_date'] ?? ''));
            if ($date === null) {
                return self::refuse(__('Choose the date.', 'fieldconstraints'));
            }
            $clean['ref_date'] = substr($date, 0, 10);
        } elseif ($reference === self::REF_FIELD) {
            $ref_field = (string) ($input['ref_field'] ?? '');
            if (!isset($fields[$ref_field]) || $ref_field === $field) {
                return self::refuse(__('Choose another date field of the same block or form.', 'fieldconstraints'));
            }
            $clean['ref_field'] = $ref_field;
        }

        return $clean;
    }

    public function prepareInputForUpdate($input)
    {
        // Only switching on and off; a changed constraint is a new one.
        return array_intersect_key($input, ['id' => true, 'is_active' => true]);
    }

    private static function refuse(string $message): bool
    {
        \Session::addMessageAfterRedirect(Compat::escape($message), false, ERROR);

        return false;
    }

    // -------------------------------------------------------------------- tab

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        $source = self::sourceFor($item);
        if ($source === null || !$item instanceof CommonDBTM || $item->isNewItem()) {
            return '';
        }

        $count = $_SESSION['glpishow_count_on_tabs'] ?? false
            ? count(self::getForParent($source, $item->getID(), false))
            : 0;

        return self::createTabEntry(self::getTypeName(2), $count);
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        $source = self::sourceFor($item);
        if ($source !== null && $item instanceof CommonDBTM) {
            self::showForParent($source, $item);
        }

        return true;
    }

    private static function showForParent(string $source, CommonDBTM $parent): void
    {
        $fields  = self::getDateFields($source, $parent->getID());
        $rows    = self::getForParent($source, $parent->getID(), false);
        $canedit = $parent->can($parent->getID(), UPDATE);
        $action  = Compat::webDir() . '/front/dateconstraint.form.php';

        echo '<div class="p-3">';
        echo '<p class="text-muted">' . Compat::escape(
            $source === self::SOURCE_FIELDS
                ? __('Applies to these fields wherever the block is shown (any item, tab or main form).', 'fieldconstraints')
                : __('Applies to these questions when the form is answered.', 'fieldconstraints')
        ) . '</p>';

        if ($fields === []) {
            echo '<div class="alert alert-info">' . Compat::escape(
                $source === self::SOURCE_FIELDS
                    ? __('This block has no date field.', 'fieldconstraints')
                    : __('This form has no date question.', 'fieldconstraints')
            ) . '</div></div>';
            return;
        }

        if ($canedit) {
            self::showAddForm($source, $parent->getID(), $fields, $action);
        }

        echo '<table class="table table-hover card-table mt-3"><thead><tr>'
            . '<th>' . Compat::escape(__('Constraint', 'fieldconstraints')) . '</th>'
            . '<th class="text-center" style="width: 8rem;">' . Compat::escape(__('Active', 'fieldconstraints')) . '</th>'
            . ($canedit ? '<th style="width: 1%;"></th>' : '')
            . '</tr></thead><tbody>';

        if ($rows === []) {
            echo '<tr><td colspan="3" class="text-muted">'
                . Compat::escape(__('No constraint yet.', 'fieldconstraints')) . '</td></tr>';
        }

        foreach ($rows as $row) {
            echo '<tr><td>' . Compat::escape(self::describe($row, $fields)) . '</td>';
            echo '<td class="text-center">' . Compat::escape(Dropdown::getYesNo($row['is_active'])) . '</td>';
            if ($canedit) {
                echo '<td class="text-nowrap">';
                echo '<form method="post" action="' . Compat::escape($action) . '" class="d-inline">'
                    . Html::hidden('id', ['value' => $row['id']])
                    . Html::hidden('is_active', ['value' => $row['is_active'] ? 0 : 1])
                    . '<button type="submit" name="update" class="btn btn-sm btn-ghost-secondary">'
                    . Compat::escape($row['is_active'] ? __('Disable', 'fieldconstraints') : __('Enable', 'fieldconstraints'))
                    . '</button>';
                Html::closeForm();
                echo '<form method="post" action="' . Compat::escape($action) . '" class="d-inline">'
                    . Html::hidden('id', ['value' => $row['id']])
                    . '<button type="submit" name="purge" class="btn btn-sm btn-ghost-danger">'
                    . '<i class="ti ti-trash"></i><span class="visually-hidden">'
                    . Compat::escape(__('Delete', 'fieldconstraints')) . '</span></button>';
                Html::closeForm();
                echo '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function showAddForm(string $source, int $parent_id, array $fields, string $action): void
    {
        $rand    = mt_rand();
        $choices = [];
        foreach ($fields as $key => $label) {
            $choices[$key] = Compat::dropdownLabel($label);
        }
        $labels = static fn (array $options): array => array_map([Compat::class, 'dropdownLabel'], $options);

        echo '<form method="post" action="' . Compat::escape($action) . '" id="fieldconstraints_constraint_' . $rand . '">';
        echo Html::hidden('source', ['value' => $source]);
        echo Html::hidden('items_id', ['value' => $parent_id]);

        echo '<div class="d-flex flex-wrap align-items-end gap-2">';

        echo '<div><div class="form-text mb-1">' . Compat::escape(__('Date field', 'fieldconstraints')) . '</div>';
        Dropdown::showFromArray('field', $choices, ['rand' => $rand, 'width' => '16rem']);
        echo '</div>';

        echo '<div><div class="form-text mb-1">&nbsp;</div>';
        Dropdown::showFromArray('operator', $labels(self::getOperators()), ['rand' => $rand, 'width' => '12rem']);
        echo '</div>';

        echo '<div><div class="form-text mb-1">&nbsp;</div>';
        Dropdown::showFromArray('reference', $labels(self::getReferences()), ['rand' => $rand, 'width' => '12rem']);
        echo '</div>';

        echo '<div data-fieldconstraints-ref="' . self::REF_DATE . '" class="d-none"><div class="form-text mb-1">'
            . Compat::escape(__('Date', 'fieldconstraints')) . '</div>';
        Html::showDateField('ref_date', ['rand' => $rand]);
        echo '</div>';

        echo '<div data-fieldconstraints-ref="' . self::REF_FIELD . '" class="d-none"><div class="form-text mb-1">'
            . Compat::escape(__('Other field', 'fieldconstraints')) . '</div>';
        Dropdown::showFromArray('ref_field', $choices, ['rand' => $rand, 'width' => '16rem']);
        echo '</div>';

        echo '<div><button type="submit" name="add" class="btn btn-primary">'
            . '<i class="ti ti-plus me-1"></i>' . Compat::escape(__('Add', 'fieldconstraints')) . '</button></div>';
        echo '</div>';

        // Show the input matching the chosen reference.
        echo Html::scriptBlock("
            (function () {
                var form = document.getElementById('fieldconstraints_constraint_{$rand}');
                var select = $(form).find('select[name=\"reference\"]');
                var toggle = function () {
                    form.querySelectorAll('[data-fieldconstraints-ref]').forEach(function (block) {
                        block.classList.toggle('d-none', block.getAttribute('data-fieldconstraints-ref') !== select.val());
                    });
                };
                select.on('change', toggle);
                toggle();
            })();
        ");

        Html::closeForm();
    }
}
