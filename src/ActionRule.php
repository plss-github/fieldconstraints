<?php

namespace GlpiPlugin\Fieldconstraints;

use Ajax;
use Glpi\Application\View\TemplateRenderer;
use CommonDBChild;
use CommonGLPI;
use Dropdown;
use Html;
use Session;

/**
 * The rule of an action block for one item: "a <ticket> matching <these
 * filters> cannot <solve> while <fields> are empty".
 *
 * Which items it covers is in its "Filters" tab (RuleFilter, combined with
 * `filter_logic`; no filter = every item); the message is per rule since it
 * usually names item-specific fields.
 */
class ActionRule extends CommonDBChild
{
    use ConfigRights;

    public static $itemtype = ActionBlock::class;
    public static $items_id = 'plugin_fieldconstraints_actionblocks_id';

    public static $rightname = 'config';

    public $dohistory = true;

    /** Shared by the widgets of one form, so the item can refresh the field list. */
    private static ?int $form_rand = null;

    public static function getTypeName($nb = 0)
    {
        return _n('Item rule', 'Item rules', $nb, 'fieldconstraints');
    }

    public static function getIcon()
    {
        return 'ti ti-list-check';
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab(RuleFilter::class, $tabs, $options);
        $this->addStandardTab(\Log::class, $tabs, $options);

        return $tabs;
    }

    /**
     * Item rules have no name of their own: "<block> - <item>".
     */
    public function getName($options = [])
    {
        if ($this->isNewItem()) {
            return parent::getName($options);
        }

        $block    = new ActionBlock();
        $itemtype = (string) ($this->fields['itemtype'] ?? '');
        $parts    = [];
        if ($block->getFromDB((int) ($this->fields[self::$items_id] ?? 0))) {
            $parts[] = Compat::plain($block->fields['name']);
        }
        $parts[] = in_array($itemtype, ActionBlock::ITEMTYPES, true) ? $itemtype::getTypeName(1) : $itemtype;

        return implode(' - ', $parts);
    }

    public function post_getEmpty()
    {
        $this->fields['is_active']    = 1;
        $this->fields['itemtype']     = 'Ticket';
        $this->fields['filter_logic'] = RuleFilter::LOGIC_AND;
    }

    public function cleanDBonPurge()
    {
        $this->deleteChildrenAndRelationsFromDb([RuleFilter::class]);
    }

    // -------------------------------------------------------------- decoding

    /**
     * @return string[]
     */
    public static function decodeRequiredFields(mixed $stored): array
    {
        if (!is_string($stored) || $stored === '') {
            return [];
        }

        $keys = json_decode(Compat::plain($stored), true);

        return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
    }

    public function getBlockStatus(): int
    {
        $block = new ActionBlock();
        $id    = (int) ($this->fields[self::$items_id] ?? 0);

        return $id > 0 && $block->getFromDB($id) ? (int) $block->fields['status'] : 0;
    }

    // ----------------------------------------------------------------- input

    public function prepareInputForAdd($input)
    {
        $block = new ActionBlock();
        if (!$block->getFromDB((int) ($input[self::$items_id] ?? 0))) {
            return self::refuse(__('Choose the block of the rule.', 'fieldconstraints'));
        }

        $input = $this->prepareRuleInput($input, (int) $block->fields['status'], true);

        return $input === false ? false : parent::prepareInputForAdd($input);
    }

    public function prepareInputForUpdate($input)
    {
        unset($input[self::$items_id]);
        $input = $this->prepareRuleInput($input, $this->getBlockStatus(), false);

        return $input === false ? false : parent::prepareInputForUpdate($input);
    }

    /**
     * @return array|false
     */
    private function prepareRuleInput(array $input, int $status, bool $is_add)
    {
        $itemtype = (string) ($input['itemtype'] ?? $this->fields['itemtype'] ?? '');
        if (!in_array($itemtype, ActionBlock::getItemtypesFor($status), true)) {
            return self::refuse(__('This item has no such action.', 'fieldconstraints'));
        }
        $input['itemtype'] = $itemtype;

        if (array_key_exists('filter_logic', $input)) {
            $input['filter_logic'] = RuleFilter::normalizeLogic($input['filter_logic']);
        }

        if (array_key_exists('required_fields', $input) || isset($input['_required_fields_posted'])) {
            $posted = $input['required_fields'] ?? [];
            if (is_string($posted)) {
                // GLPI 11 posts an empty string for an empty multiple select; the
                // API may send the stored JSON form.
                $posted = $posted === '' ? [] : (json_decode(Compat::plain($posted), true) ?? [$posted]);
            }

            $keys = [];
            foreach ((array) $posted as $key) {
                $key = (string) $key;
                if (FieldCatalog::isValidKey($key, $itemtype, $status)) {
                    $keys[] = $key;
                } elseif ($key !== '') {
                    Session::addMessageAfterRedirect(
                        Compat::escape(sprintf(
                            __('"%s" does not apply to this item or action and was removed from the rule.', 'fieldconstraints'),
                            FieldCatalog::describe($key)
                        )),
                        false,
                        WARNING
                    );
                }
            }
            $input['required_fields'] = json_encode(array_values(array_unique($keys)));
        }
        unset($input['_required_fields_posted']);

        $required = array_key_exists('required_fields', $input)
            ? self::decodeRequiredFields($input['required_fields'])
            : self::decodeRequiredFields($this->fields['required_fields'] ?? null);
        if ($required === [] && (int) ($input['is_active'] ?? $this->fields['is_active'] ?? 1) === 1) {
            Session::addMessageAfterRedirect(
                Compat::escape(__('The rule has no required field: it will not block anything.', 'fieldconstraints')),
                false,
                WARNING
            );
        }

        // A filter on a criterion the new item lacks (ticket type on a change)
        // would never match.
        if (!$is_add && $itemtype !== ($this->fields['itemtype'] ?? $itemtype)) {
            $criteria = RuleFilter::getCriteria($itemtype);
            foreach (RuleFilter::getForRules([$this->getID()])[$this->getID()] ?? [] as $filter) {
                if (!isset($criteria[$filter['criterion']])) {
                    return self::refuse(sprintf(
                        __('The filter "%s" does not apply to this item: remove it first.', 'fieldconstraints'),
                        RuleFilter::describe($filter)
                    ));
                }
            }
        }

        return $input;
    }

    private static function refuse(string $message): bool
    {
        Session::addMessageAfterRedirect(Compat::escape($message), false, ERROR);

        return false;
    }

    // ------------------------------------------------------------ block tab

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof ActionBlock || $item->isNewItem()) {
            return '';
        }

        $count = ($_SESSION['glpishow_count_on_tabs'] ?? false)
            ? countElementsInTable(self::getTable(), [self::$items_id => $item->getID()])
            : 0;

        return self::createTabEntry(self::getTypeName(2), $count);
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof ActionBlock) {
            self::showForBlock($item);
        }

        return true;
    }

    private static function showForBlock(ActionBlock $block): void
    {
        global $DB;

        $canedit = self::canCreate();
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [self::$items_id => $block->getID()],
            'ORDER' => ['itemtype', 'id'],
        ]);

        echo '<div class="p-3">';
        if ($canedit) {
            $url = self::getFormURL() . '?' . self::$items_id . '=' . $block->getID();
            echo '<a class="btn btn-primary mb-3" href="' . Compat::escape($url) . '">'
                . '<i class="ti ti-plus me-1"></i>' . Compat::escape(__('Add an item rule', 'fieldconstraints')) . '</a>';
        }

        echo '<table class="table table-hover card-table"><thead><tr>'
            . '<th>' . Compat::escape(__('Item', 'fieldconstraints')) . '</th>'
            . '<th>' . Compat::escape(RuleFilter::getTypeName(2)) . '</th>'
            . '<th>' . Compat::escape(__('Required fields', 'fieldconstraints')) . '</th>'
            . '<th class="text-center">' . Compat::escape(__('Active', 'fieldconstraints')) . '</th>'
            . '</tr></thead><tbody>';

        if (count($iterator) === 0) {
            echo '<tr><td colspan="4" class="text-muted">'
                . Compat::escape(__('No item rule yet: this block blocks nothing.', 'fieldconstraints')) . '</td></tr>';
        }

        $rows    = iterator_to_array($iterator, false);
        $filters = RuleFilter::getForRules(array_column($rows, 'id'));
        foreach ($rows as $row) {
            $itemtype = (string) $row['itemtype'];
            $label    = in_array($itemtype, ActionBlock::ITEMTYPES, true) ? $itemtype::getTypeName(1) : $itemtype;
            $fields = array_map([FieldCatalog::class, 'describe'], self::decodeRequiredFields($row['required_fields']));
            $link   = self::getFormURLWithID((int) $row['id']);

            echo '<tr>'
                . '<td><a href="' . Compat::escape($link) . '">' . Compat::escape($label) . '</a></td>'
                . '<td>' . Compat::escape(RuleFilter::summarize($filters[(int) $row['id']] ?? [], (string) $row['filter_logic'])) . '</td>'
                . '<td>' . implode('<br>', array_map([Compat::class, 'escape'], $fields)) . '</td>'
                . '<td class="text-center">' . Compat::escape(Dropdown::getYesNo($row['is_active'])) . '</td>'
                . '</tr>';
        }
        echo '</tbody></table></div>';
    }

    // ------------------------------------------------------------------ form

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        if ($this->isNewItem()) {
            $this->fields[self::$items_id] = (int) ($options[self::$items_id] ?? 0);
        }

        $block = new ActionBlock();
        if (!$block->getFromDB((int) $this->fields[self::$items_id])) {
            echo '<div class="alert alert-warning">' . Compat::escape(__('Choose the block of the rule.', 'fieldconstraints')) . '</div>';
            return false;
        }
        $status = (int) $block->fields['status'];
        self::$form_rand ??= mt_rand();
        $rand = self::$form_rand;

        $items = [];
        foreach (ActionBlock::getItemtypesFor($status) as $itemtype) {
            $items[$itemtype] = $itemtype::getTypeName(1);
        }

        // Widgets without a core macro, rendered here.
        $capture = static function (callable $render): string {
            ob_start();
            $render();
            return (string) ob_get_clean();
        };
        // Filters are edited in their own tab; the form only recalls them.
        if ($this->isNewItem()) {
            $filters_html = '<span class="text-muted">'
                . Compat::escape(__('Save the rule, then add filters in the "Filters" tab. No filter = every item.', 'fieldconstraints'))
                . '</span>';
        } else {
            $summary = RuleFilter::summarize(
                RuleFilter::getForRules([$this->getID()])[$this->getID()] ?? [],
                (string) ($this->fields['filter_logic'] ?? RuleFilter::LOGIC_AND)
            );
            $tab_url = self::getFormURLWithID($this->getID()) . '&forcetab=' . urlencode(RuleFilter::class . '$1');
            $filters_html = '<span>' . Compat::escape($summary) . '</span>'
                . ' <a class="ms-2" href="' . Compat::escape($tab_url) . '"><i class="ti ti-filter me-1"></i>'
                . Compat::escape(__('Edit filters', 'fieldconstraints')) . '</a>';
        }
        $required_html = $capture(function () use ($rand, $status) {
            echo '<div id="fieldconstraints_fields_' . $rand . '" data-fieldconstraints-fields>';
            self::showFieldsDropdown(
                (string) $this->fields['itemtype'],
                $status,
                self::decodeRequiredFields($this->fields['required_fields'] ?? null)
            );
            echo '</div>';
        });
        $refresh_script = Ajax::updateItemOnSelectEvent(
            'dropdown_itemtype' . $rand,
            'fieldconstraints_fields_' . $rand,
            Compat::webDir() . '/ajax/fields.php',
            ['itemtype' => '__VALUE__', 'block' => $block->getID(), 'id' => (int) ($this->fields['id'] ?? 0)],
            false
        );

        $block_link = '<a href="' . Compat::escape($block->getLinkURL()) . '">'
            . Compat::escape(Compat::plain($block->fields['name']) . ' - ' . ActionBlock::getActionLabel($status))
            . '</a>';

        TemplateRenderer::getInstance()->display('@fieldconstraints/actionrule_form.html.twig', [
            'item'             => $this,
            'params'           => $options + ['candel' => true, 'formfooter' => true],
            'rand'             => $rand,
            'parent_field'     => self::$items_id,
            'block_id'         => $block->getID(),
            'block_label'      => ActionBlock::getTypeName(1),
            'block_link'       => $block_link,
            'itemtypes'        => $items,
            'filters_html'     => $filters_html,
            'required_html'    => $required_html,
            'refresh_script'   => $refresh_script,
            // Plain text: Twig escapes it (GLPI 10 stores it HTML-encoded).
            'message'          => Compat::plain($this->fields['message'] ?? ''),
            'comment'          => Compat::plain($this->fields['comment'] ?? ''),
            'labels'           => [
                'item'              => __('Item', 'fieldconstraints'),
                'filters'           => RuleFilter::getTypeName(2),
                'active'            => __('Active', 'fieldconstraints'),
                'message'           => __('Custom message', 'fieldconstraints'),
                'required'          => __('Fields required to allow the action', 'fieldconstraints'),
                'comment'           => _n('Comment', 'Comments', 2),
            ],
        ]);

        self::showPickerPanel();

        return true;
    }

    /**
     * @param string[] $selected
     */
    public static function showFieldsDropdown(string $itemtype, int $status, array $selected): void
    {
        $options = [];
        foreach (FieldCatalog::getOptions($itemtype, $status) as $group => $fields) {
            $group = Compat::dropdownLabel($group);
            foreach ($fields as $key => $label) {
                $options[$group][$key] = Compat::dropdownLabel($label);
            }
        }

        // Keys stored earlier but no longer offered (field removed, item changed)
        // stay visible so that saving the form is a deliberate choice.
        $offered = array_merge([], ...array_map('array_keys', array_values($options)));
        foreach (array_diff($selected, $offered) as $key) {
            $options[Compat::dropdownLabel(__('Unavailable', 'fieldconstraints'))][$key]
                = Compat::dropdownLabel(FieldCatalog::describe($key));
        }

        // Lets prepareInput*() tell "nothing selected" from "field not posted":
        // GLPI 10 posts nothing at all for an empty multiple select.
        echo Html::hidden('_required_fields_posted', ['value' => 1]);

        Dropdown::showFromArray('required_fields', $options, [
            'multiple' => true,
            'values'   => $selected,
            'width'    => '100%',
        ]);
        echo '<div class="form-text">'
            . Compat::escape(__('Requirements without a field of their own (tasks, followups...) are only in the list.', 'fieldconstraints'))
            . '</div>';

        self::showPickerConfig($itemtype, $status);
    }

    /**
     * What the picker panel needs for the current item: the form to show, a
     * sample item and the clickable fields. Re-rendered with the field list.
     */
    private static function showPickerConfig(string $itemtype, int $status): void
    {
        global $DB;

        if (!in_array($itemtype, ActionBlock::ITEMTYPES, true)) {
            return;
        }

        $item    = new $itemtype();
        $options = [];
        foreach (FieldCatalog::getOptions($itemtype, $status) as $fields) {
            foreach ($fields as $key => $label) {
                $target = FormHints::target($item, $key);
                if ($target !== null) {
                    $options[] = ['key' => $key, 'label' => $label, 'target' => $target];
                }
            }
        }

        // A saved, still open item shows what a creation form lacks: status,
        // the solution form, tabs.
        $sample = (int) ($DB->request([
            'SELECT' => ['MAX' => 'id AS id'],
            'FROM'   => $item::getTable(),
            'WHERE'  => [
                'is_deleted' => 0,
                'NOT'        => ['status' => array_merge($item->getSolvedStatusArray(), $item->getClosedStatusArray())],
            ],
        ])->current()['id'] ?? 0);

        echo '<span class="d-none" data-fieldconstraints-picker-config'
            . ' data-itemtype="' . Compat::escape($itemtype) . '"'
            . ' data-title="' . Compat::escape(sprintf(__('Select the fields on the form - %s', 'fieldconstraints'), $itemtype::getTypeName(1))) . '"'
            . ' data-url="' . Compat::escape($item::getFormURL()) . '"'
            . ' data-sample="' . $sample . '"'
            . ' data-options="' . Compat::escape(FormHints::json($options)) . '"></span>';
    }

    /**
     * The real item form, always shown below the rule: clicking a field there
     * toggles it in "Fields required" (public/fieldconstraints.js).
     */
    private static function showPickerPanel(): void
    {
        echo '<div class="card mt-3" data-fieldconstraints-picker-panel>'
            . '<div class="card-header d-flex flex-wrap align-items-center gap-2">'
            . '<h3 class="card-title me-auto" data-fieldconstraints-picker-title></h3>'
            . '<label class="d-flex align-items-center gap-2 mb-0"><span class="text-nowrap">'
            . Compat::escape(__('Sample item ID', 'fieldconstraints')) . '</span>'
            . '<input type="number" min="1" class="form-control form-control-sm" style="width: 8rem;" data-fieldconstraints-picker-sample></label>'
            . '<button type="button" class="btn btn-sm btn-outline-secondary" data-fieldconstraints-picker-load>'
            . Compat::escape(__('Load', 'fieldconstraints')) . '</button>'
            . '</div>'
            . '<div class="px-3 py-2 border-bottom text-muted">'
            . Compat::escape(__('Click a field to require it, click again to remove it. Fields of other tabs: open the tab in the form. Nothing is saved on the item; save the rule above.', 'fieldconstraints'))
            . '</div>'
            . '<iframe data-fieldconstraints-picker-frame style="width: 100%; height: 75vh; border: 0;"></iframe>'
            . '</div>';
    }
}
