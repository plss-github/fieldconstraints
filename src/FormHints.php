<?php

namespace GlpiPlugin\Fieldconstraints;

use CommonDBTM;
use CommonITILObject;
use ITILSolution;

/**
 * Tells the user beforehand what the rules require, on top of the server-side
 * refusal:
 *
 *  - in the solution form, a red notice right above the submit button listing
 *    what is still missing (the solution's own fields are then tracked live by
 *    public/fieldconstraints.js);
 *  - in the item and solution forms, the labels of required fields in red with
 *    a `*`, their tooltip naming the actions that require them;
 *
 * The notice is rendered from the `post_item_form` hook, which GLPI 10 and 11
 * call inside the solution form just above its buttons; the rest is fetched by
 * public/fieldconstraints.js.
 */
final class FormHints
{
    public static function postItemForm(array $params): void
    {
        $item = $params['item'] ?? null;
        if (!$item instanceof CommonDBTM) {
            return;
        }

        if ($item instanceof ITILSolution) {
            $parent = $params['options']['item'] ?? null;
            if ($parent instanceof CommonITILObject && (int) ($item->fields['id'] ?? 0) <= 0) {
                self::showSolutionNotice($parent);
            }
            return;
        }

    }

    private static function showSolutionNotice(CommonITILObject $parent): void
    {
        if ($parent->isNewItem()) {
            return;
        }

        // An empty solution input: every solution field counts as missing here
        // and the script clears them as the user fills the form.
        $violations = Checker::check($parent, Enforcer::solutionStatuses($parent), [], []);
        if ($violations === []) {
            return;
        }

        $marks = [];
        $html  = '';
        foreach ($violations as $violation) {
            $action = self::actionLabel($parent, $violation['rule']);

            $items = '';
            foreach ($violation['missing'] as $key => $label) {
                if (str_starts_with($key, FieldCatalog::SOLUTION . ':')) {
                    $items .= '<li data-fieldconstraints-key="' . Compat::escape($key) . '">' . Compat::escape($label) . '</li>';
                    $marks[$key]['actions'][$action] = true;
                } else {
                    // Checked against the saved item: filling it in this form is not possible.
                    $items .= '<li>' . Compat::escape($label)
                        . ' <small class="opacity-75">'
                        . Compat::escape(__('(fill in on the item and save it first)', 'fieldconstraints'))
                        . '</small></li>';
                }
            }

            $html .= '<div data-fieldconstraints-rule class="mb-1">'
                . '<div class="fw-bold">' . Compat::escape(Enforcer::ruleMessage($parent, $violation['rule'])) . '</div>'
                . '<div>' . Compat::escape(__('Fill in:', 'fieldconstraints')) . '</div>'
                . '<ul class="mb-0">' . $items . '</ul>'
                . '</div>';
        }

        $targets = [];
        foreach ($marks as $key => $mark) {
            $target = self::target($parent, $key);
            if ($target !== null) {
                $targets[] = $target + ['title' => self::requiredTitle(array_keys($mark['actions']))];
            }
        }

        echo '<div class="alert alert-important alert-danger fieldconstraints-notice mt-2 mb-2" role="alert" data-fieldconstraints-notice'
            . ' data-fieldconstraints-marks="' . Compat::escape(self::json($targets)) . '">'
            . '<div class="d-flex"><i class="ti ti-alert-triangle fs-2 me-2"></i><div class="flex-fill">'
            . $html
            . '</div></div></div>';
    }

    /**
     * Required labels of an item page.
     *
     * Fetched by the page (ajax/itemconfig.php) rather than printed with the item
     * form: a page opened on another tab (Fields tab...) never renders that form.
     */
    public static function itemConfig(CommonITILObject $item): array
    {
        $marks = [];
        foreach (Checker::applicableRules($item) as $rule) {
            $action = self::actionLabel($item, $rule);
            foreach (ActionRule::decodeRequiredFields($rule['required_fields'] ?? null) as $key) {
                $target = self::target($item, $key);
                if ($target === null || str_starts_with($key, FieldCatalog::SOLUTION . ':')) {
                    continue;
                }
                $marks[$key]['target']           = $target;
                $marks[$key]['actions'][$action] = true;
            }
        }

        $targets = [];
        foreach ($marks as $mark) {
            $targets[] = $mark['target'] + ['title' => self::requiredTitle(array_keys($mark['actions']))];
        }

        return ['marks' => $targets];
    }

    /**
     * Where a required field is in the forms: input names, the actor role, or the
     * file upload area.
     *
     * @return array{names?: string[], actor?: string, upload?: bool}|null null when the
     *         requirement has no field of its own (at least one task...)
     */
    public static function target(CommonITILObject $item, string $key): ?array
    {
        [$kind, $name] = array_pad(explode(':', $key, 2), 2, '');

        if ($kind === FieldCatalog::NATIVE) {
            return match ($name) {
                'itilcategories_id', 'locations_id', 'requesttypes_id', 'time_to_resolve', 'actiontime'
                    => ['names' => [$name]],
                'requester', 'requester_group' => ['actor' => 'requester'],
                'observer'                     => ['actor' => 'observer'],
                'assign_user', 'assign_group', 'assign_supplier' => ['actor' => 'assign'],
                default                        => null,
            };
        }

        if ($kind === FieldCatalog::SOLUTION) {
            // The description has no label: the notice covers it.
            return match ($name) {
                'solutiontypes_id' => ['names' => ['solutiontypes_id']],
                'document'         => ['upload' => true],
                default            => null,
            };
        }

        if ($kind === FieldCatalog::FIELDS) {
            $field = FieldCatalog::getAdditionalField((int) $name);
            if ($field === null || !in_array($item->getType(), $field['itemtypes'], true)) {
                return null;
            }
            $column = FieldCatalog::fieldsColumn($field);

            return ['names' => [$column, $column . '[]']];
        }

        return null;
    }

    private static function actionLabel(CommonITILObject $item, array $rule): string
    {
        return ActionBlock::getActionLabel((int) $rule['status']);
    }

    /**
     * @param string[] $actions
     */
    private static function requiredTitle(array $actions): string
    {
        return sprintf(__('Required to: %s', 'fieldconstraints'), implode(', ', $actions));
    }

    public static function json(array $data): string
    {
        return (string) json_encode(
            $data,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );
    }
}
