<?php

namespace GlpiPlugin\Fieldconstraints;

use CommonDropdown;
use CommonITILObject;
use Dropdown;

/**
 * A block of rules for one action ("Solve", "Close", "Put on hold"...).
 *
 * The block carries the action, the entity scope and the on/off switch; what is
 * required, and for which items and categories, lives in its item rules
 * (ActionRule): one block "Solve" with a rule for tickets, one for changes...
 *
 * Built on CommonDropdown to reuse core's form, list and history screens, which
 * render and escape correctly on both GLPI 10 and 11.
 */
class ActionBlock extends CommonDropdown
{
    use ConfigRights;

    /** ITIL objects rules may target. */
    public const ITEMTYPES = ['Ticket', 'Change', 'Problem'];

    public static $rightname = 'config';

    public $can_be_translated = false;

    public $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        return _n('Action rule', 'Action rules', $nb, 'fieldconstraints');
    }

    public static function getIcon()
    {
        return 'ti ti-lock-check';
    }

    public function post_getEmpty()
    {
        $this->fields['is_active']    = 1;
        $this->fields['is_recursive'] = 1;
        $this->fields['status']       = CommonITILObject::SOLVED;
    }

    public function defineTabs($options = [])
    {
        $tabs = parent::defineTabs($options);
        $this->addStandardTab(ActionRule::class, $tabs, $options);

        return $tabs;
    }

    public function getAdditionalFields()
    {
        return [
            [
                'name'  => 'status',
                'label' => __('Action', 'fieldconstraints'),
                'type'  => 'fieldconstraints_action',
            ],
            [
                'name'  => 'is_active',
                'label' => __('Active', 'fieldconstraints'),
                'type'  => 'bool',
            ],
        ];
    }

    public function displaySpecificTypeField($ID, $field = [], array $options = [])
    {
        if (($field['type'] ?? '') !== 'fieldconstraints_action') {
            return;
        }

        // The rules' fields depend on the action (solution fields...): once the
        // block has rules, its action is fixed.
        if (!$this->isNewItem() && countElementsInTable(ActionRule::getTable(), [ActionRule::$items_id => $this->getID()]) > 0) {
            echo '<span class="fw-bold">' . Compat::escape(self::getActionLabel((int) $this->fields['status'])) . '</span>'
                . '<div class="form-text">' . Compat::escape(__('Fixed while the block has item rules.', 'fieldconstraints')) . '</div>';
            return;
        }

        $choices = [];
        foreach (self::getActions() as $status => $label) {
            $choices[$status] = Compat::dropdownLabel($label);
        }
        Dropdown::showFromArray('status', $choices, ['value' => (int) $this->fields['status'], 'width' => '100%']);
    }

    public function prepareInputForAdd($input)
    {
        if (!isset(self::getActions()[(int) ($input['status'] ?? 0)])) {
            \Session::addMessageAfterRedirect(Compat::escape(__('Invalid item or action.', 'fieldconstraints')), false, ERROR);
            return false;
        }

        return parent::prepareInputForAdd($input);
    }

    public function prepareInputForUpdate($input)
    {
        if (isset($input['status'])) {
            $has_rules = countElementsInTable(ActionRule::getTable(), [ActionRule::$items_id => $this->getID()]) > 0;
            if ($has_rules || !isset(self::getActions()[(int) $input['status']])) {
                unset($input['status']);
            }
        }

        return parent::prepareInputForUpdate($input);
    }

    public function cleanDBonPurge()
    {
        $this->deleteChildrenAndRelationsFromDb([ActionRule::class]);
    }

    // ---------------------------------------------------------------- actions

    /**
     * Statuses an item may move to, labelled as actions, for one itemtype. The
     * initial status is left out: nothing ever moves back to "new".
     *
     * @return array<int, string>
     */
    public static function getStatusesFor(string $itemtype): array
    {
        $statuses = [];
        foreach ($itemtype::getAllStatusArray() as $status => $label) {
            $status = (int) $status;
            if ($status !== CommonITILObject::INCOMING) {
                $statuses[$status] = self::actionLabelFor($status, (string) $label);
            }
        }

        return $statuses;
    }

    /**
     * Every action of any item.
     *
     * @return array<int, string>
     */
    public static function getActions(): array
    {
        $actions = [];
        foreach (self::ITEMTYPES as $itemtype) {
            $actions += self::getStatusesFor($itemtype);
        }

        // Most used first.
        $first = array_intersect_key($actions, array_flip([CommonITILObject::SOLVED, CommonITILObject::CLOSED, CommonITILObject::WAITING]));

        return $first + $actions;
    }

    public static function getActionLabel(int $status): string
    {
        return self::getActions()[$status] ?? (string) $status;
    }

    /**
     * Items that have this action.
     *
     * @return string[]
     */
    public static function getItemtypesFor(int $status): array
    {
        return array_values(array_filter(
            self::ITEMTYPES,
            static fn (string $itemtype): bool => isset(self::getStatusesFor($itemtype)[$status])
        ));
    }

    private static function actionLabelFor(int $status, string $status_label): string
    {
        return match ($status) {
            CommonITILObject::SOLVED  => __('Solve', 'fieldconstraints'),
            CommonITILObject::CLOSED  => __('Close', 'fieldconstraints'),
            CommonITILObject::WAITING => __('Put on hold (pending)', 'fieldconstraints'),
            default                   => sprintf(__('Change status to "%s"', 'fieldconstraints'), $status_label),
        };
    }

    // ----------------------------------------------------------------- search

    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();

        $tab[] = [
            'id'            => '201',
            'table'         => static::getTable(),
            'field'         => 'status',
            'name'          => __('Action', 'fieldconstraints'),
            'datatype'      => 'specific',
            'searchtype'    => ['equals', 'notequals'],
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'       => '202',
            'table'    => static::getTable(),
            'field'    => 'is_active',
            'name'     => __('Active', 'fieldconstraints'),
            'datatype' => 'bool',
        ];

        $tab[] = [
            'id'            => '203',
            'table'         => ActionRule::getTable(),
            'field'         => 'id',
            'name'          => _n('Item rule', 'Item rules', 2, 'fieldconstraints'),
            'datatype'      => 'count',
            'forcegroupby'  => true,
            'usehaving'     => true,
            'massiveaction' => false,
            'joinparams'    => ['jointype' => 'child'],
        ];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        if ($field === 'status') {
            return Compat::escape(self::getActionLabel((int) $values['status']));
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        if ($field === 'status') {
            $options['display'] = false;
            $choices = array_map([Compat::class, 'dropdownLabel'], self::getActions());

            return Dropdown::showFromArray($name, $choices, $options + ['value' => $values[$field]]);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }
}
