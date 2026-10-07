<?php

namespace GlpiPlugin\Fieldconstraints;

use CommonDBTM;
use CommonITILObject;
use Entity;
use Session;
use Ticket;

/**
 * Hook callbacks: where a status change can come from, and how it is refused.
 *
 *  - ITIL object update with a new `status` (form, API, massive action, cron,
 *    approval/refusal of a solution): the status is dropped from the input, the
 *    other changes of the same save are kept;
 *  - solution added: the solution is refused before anything is written, since
 *    core would otherwise store it and only then try to change the status;
 *  - followup/task flagged "pending": refused likewise; a followup approving the
 *    solution (`add_close`) too.
 */
final class Enforcer
{
    /**
     * ITIL objects whose status change was already validated together with the
     * solution that triggers it (ITILSolution::post_addItem() updates the status
     * right after storing the solution).
     *
     * @var array<string, true>
     */
    private static array $validated = [];

    public static function preItilUpdate(CommonDBTM $item): void
    {
        if (!$item instanceof CommonITILObject || !is_array($item->input) || !isset($item->input['status'])) {
            return;
        }

        $status = (int) $item->input['status'];
        if ($status === (int) ($item->fields['status'] ?? 0)) {
            return;
        }

        if (isset(self::$validated[self::key($item)])) {
            return;
        }

        $violations = Checker::check($item, [$status], $item->input);
        if ($violations === []) {
            return;
        }

        unset($item->input['status']);
        self::report($item, $violations);
    }

    public static function preSolutionAdd(CommonDBTM $solution): void
    {
        if (!is_array($solution->input)) {
            return;
        }

        $parent = self::loadParent(
            (string) ($solution->input['itemtype'] ?? ''),
            (int) ($solution->input['items_id'] ?? 0)
        );
        if ($parent === null) {
            return;
        }

        $violations = Checker::check($parent, self::solutionStatuses($parent), [], $solution->input);
        if ($violations !== []) {
            $solution->input = false;
            self::report($parent, $violations);
            return;
        }

        self::$validated[self::key($parent)] = true;
    }

    /**
     * Statuses a new solution moves the object to.
     *
     * Same decision as ITILSolution::post_addItem(): an entity configured to
     * close immediately turns the solution into a closure.
     *
     * @return int[]
     */
    public static function solutionStatuses(CommonITILObject $parent): array
    {
        $statuses = [CommonITILObject::SOLVED];
        if ($parent instanceof Ticket) {
            $autoclose = Entity::getUsedConfig('autoclose_delay', $parent->getEntityID(), '', Entity::CONFIG_NEVER);
            if ((int) $autoclose === 0 || (int) $parent->fields['status'] === CommonITILObject::CLOSED) {
                $statuses[] = CommonITILObject::CLOSED;
            }
        }

        return $statuses;
    }

    public static function solutionAdded(CommonDBTM $solution): void
    {
        $key = ($solution->fields['itemtype'] ?? '') . '#' . ($solution->fields['items_id'] ?? 0);
        unset(self::$validated[$key]);
    }

    public static function preTimelineAdd(CommonDBTM $timeline_item): void
    {
        if (!is_array($timeline_item->input)) {
            return;
        }

        $input = $timeline_item->input;

        // Followups carry the parent as itemtype/items_id, tasks as a foreign key.
        if (isset($input['itemtype'], $input['items_id'])) {
            $parent = self::loadParent((string) $input['itemtype'], (int) $input['items_id']);
        } else {
            $parent_type = method_exists($timeline_item, 'getItilObjectItemType')
                ? (string) $timeline_item->getItilObjectItemType()
                : '';
            $fk     = $parent_type !== '' ? getForeignKeyFieldForItemType($parent_type) : '';
            $parent = self::loadParent($parent_type, (int) ($input[$fk] ?? 0));
        }
        if ($parent === null) {
            return;
        }

        // Same condition as PendingReason_Item::handlePendingReasonUpdateFromNewTimelineItem().
        $status = null;
        if (!empty($input['pending']) && (int) ($input['pendingreasons_id'] ?? 0) > 0) {
            $status = CommonITILObject::WAITING;
        } elseif (isset($input['add_close'])) {
            $status = CommonITILObject::CLOSED;
        }

        if ($status === null || $status === (int) $parent->fields['status']) {
            return;
        }

        $violations = Checker::check($parent, [$status]);
        if ($violations !== []) {
            $timeline_item->input = false;
            self::report($parent, $violations);
        }
    }

    private static function loadParent(string $itemtype, int $id): ?CommonITILObject
    {
        if ($id <= 0 || !in_array($itemtype, ActionBlock::ITEMTYPES, true)) {
            return null;
        }

        $parent = new $itemtype();

        return $parent->getFromDB($id) ? $parent : null;
    }

    private static function key(CommonITILObject $item): string
    {
        return $item->getType() . '#' . $item->getID();
    }

    /**
     * Text explaining why a rule refuses its action: its own message, or a default one.
     */
    public static function ruleMessage(CommonITILObject $item, array $rule): string
    {
        $message = trim(Compat::plain($rule['message'] ?? ''));
        if ($message !== '') {
            return $message;
        }

        return sprintf(
            __('Action "%1$s" is not allowed yet (rule "%2$s").', 'fieldconstraints'),
            ActionBlock::getActionLabel((int) $rule['status']),
            Compat::plain($rule['name'] ?? '')
        );
    }

    /**
     * @param array<int, array{rule: array, missing: array<string, string>}> $violations
     */
    private static function report(CommonITILObject $item, array $violations): void
    {
        foreach ($violations as $violation) {
            $message = self::ruleMessage($item, $violation['rule']);

            $html = Compat::escape($message)
                . '<br>' . Compat::escape(__('Fill in:', 'fieldconstraints'))
                . '<ul class="mb-0"><li>'
                . implode('</li><li>', array_map([Compat::class, 'escape'], $violation['missing']))
                . '</li></ul>';

            Session::addMessageAfterRedirect($html, false, ERROR);
        }
    }
}
