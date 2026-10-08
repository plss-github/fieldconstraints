<?php
// Lab scenario (GLPI 10 and 11): run phase1.php on plugin 1.0.0, update to 1.1.0, run phase2.php.
// docker cp tests/lab <container>:/tmp/t && docker exec <container> php /tmp/t/phase2.php
require __DIR__ . '/boot.php';
use GlpiPlugin\Fieldconstraints\ActionRule;
use GlpiPlugin\Fieldconstraints\RuleFilter;
use GlpiPlugin\Fieldconstraints\Checker;

$cat = fn($n) => (new ITILCategory())->getFromDBByCrit(['name' => $n]) ? null : null;
$c = new ITILCategory(); $c->getFromDBByCrit(['name' => 'Wi-Fi']); $wifi = $c->getID();
$c->getFromDBByCrit(['name' => 'Impressoras']); $imp = $c->getID();
$c->getFromDBByCrit(['name' => 'Rede']); $rede = $c->getID();
$gA = ensure('Group', ['name' => 'Grupo A'], ['entities_id' => 0, 'is_recursive' => 1]);
$gB = ensure('Group', ['name' => 'Grupo B'], ['entities_id' => 0, 'is_recursive' => 1]);
$gSub = ensure('Group', ['name' => 'Sub A', 'groups_id' => $gA], ['entities_id' => 0, 'is_recursive' => 1]);

// Keep only the ticket rule active.
global $DB;
$DB->update(ActionRule::getTable(), ['is_active' => 0], ['NOT' => ['id' => 1]]);
$rule = new ActionRule(); $rule->getFromDB(1);

function ticket(int $type, int $cat, array $extra = []): Ticket {
    $t = new Ticket();
    $id = $t->add(['name' => 'T', 'content' => 'x', 'type' => $type, 'itilcategories_id' => $cat, 'entities_id' => 0] + $extra);
    $t->getFromDB($id);
    return $t;
}
function trySolve(Ticket $t): bool {
    $t->update(['id' => $t->getID(), 'status' => CommonITILObject::SOLVED]);
    $t->getFromDB($t->getID());
    messages();
    return (int) $t->fields['status'] === CommonITILObject::SOLVED;
}

// --- AND (migrated): category under Rede AND type = incident
check('AND: incident Wi-Fi blocked', !trySolve(ticket(Ticket::INCIDENT_TYPE, $wifi)));
check('AND: request Wi-Fi allowed', trySolve(ticket(Ticket::DEMAND_TYPE, $wifi)));
check('AND: incident Impressoras allowed', trySolve(ticket(Ticket::INCIDENT_TYPE, $imp)));
// Same request sets the category: new category counts.
$t = ticket(Ticket::INCIDENT_TYPE, $imp);
$t->update(['id' => $t->getID(), 'itilcategories_id' => $wifi, 'status' => CommonITILObject::SOLVED]); $t->getFromDB($t->getID()); messages();
check('AND: category changed in same save is evaluated', (int) $t->fields['status'] !== CommonITILObject::SOLVED && (int) $t->fields['itilcategories_id'] === $wifi);

// --- OR
check('logic update to OR', $rule->update(['id' => 1, 'filter_logic' => 'or']) && ($rule->getFromDB(1) && $rule->fields['filter_logic'] === 'OR'));
messages();
check('OR: request Wi-Fi blocked', !trySolve(ticket(Ticket::DEMAND_TYPE, $wifi)));
check('OR: request Impressoras allowed', trySolve(ticket(Ticket::DEMAND_TYPE, $imp)));
check('OR: incident Impressoras blocked', !trySolve(ticket(Ticket::INCIDENT_TYPE, $imp)));
$rule->update(['id' => 1, 'filter_logic' => 'AND']); messages();

// --- Validation
$f = new RuleFilter();
check('equals with two values refused', !$f->add([RuleFilter::$items_id => 1, 'criterion' => 'itilcategories_id', 'operator' => 'equals', 'filter_values' => [$rede, $imp]]), messages());
messages();
check('under on non-tree refused', !$f->add([RuleFilter::$items_id => 1, 'criterion' => 'type', 'operator' => 'under', 'filter_values' => [1]]));
check('no value refused', !$f->add([RuleFilter::$items_id => 1, 'criterion' => 'type', 'operator' => 'in', 'filter_values' => '']));
check('type on change rule refused', !$f->add([RuleFilter::$items_id => 2, 'criterion' => 'type', 'operator' => 'equals', 'filter_values' => 1]));
check('rule itemtype change refused while type filter', !$rule->update(['id' => 1, 'itemtype' => 'Change']) || ($rule->getFromDB(1) && $rule->fields['itemtype'] === 'Ticket'));
echo '  msg: ', messages(), "\n";
check('entity 0 accepted', $fid = $f->add([RuleFilter::$items_id => 1, 'criterion' => 'entities_id', 'operator' => 'in', 'filter_values' => ['0']]));
check('entity filter stored [0]', $f->getFromDB($fid) && $f->fields['filter_values'] === '[0]');
check('entity 0: incident Wi-Fi still blocked', !trySolve(ticket(Ticket::INCIDENT_TYPE, $wifi)));
$f->delete(['id' => $fid], true);

// --- session group: glpi user in Sub A
ensure('Group_User', ['groups_id' => $gSub, 'users_id' => Session::getLoginUserID()], []);
$_SESSION['glpigroups'] = [$gSub];
$fid = $f->add([RuleFilter::$items_id => 1, 'criterion' => 'session_group', 'operator' => 'under', 'filter_values' => [$gA]]);
check('session group under A (member of Sub A): blocked', !trySolve(ticket(Ticket::INCIDENT_TYPE, $wifi)));
$f->update(['id' => $fid, 'operator' => 'in']);
check('session group in A (exact): allowed', trySolve(ticket(Ticket::INCIDENT_TYPE, $wifi)));
$f->update(['id' => $fid, 'operator' => 'notequals', 'filter_values' => $gB]);
check('session group is not B: blocked', !trySolve(ticket(Ticket::INCIDENT_TYPE, $wifi)));
$f->delete(['id' => $fid], true);

// --- requester group via actors
$fid = $f->add([RuleFilter::$items_id => 1, 'criterion' => 'requester_group', 'operator' => 'equals', 'filter_values' => $gB]);
$tB = ticket(Ticket::INCIDENT_TYPE, $wifi, ['_groups_id_requester' => $gB]);
$tA = ticket(Ticket::INCIDENT_TYPE, $wifi, ['_groups_id_requester' => $gA]);
check('requester group is B, ticket of B: blocked', !trySolve($tB));
check('requester group is B, ticket of A: allowed', trySolve($tA));
// Form-style _actors switching the group in the same save.
$t = ticket(Ticket::INCIDENT_TYPE, $wifi, ['_groups_id_requester' => $gA]);
$t->update(['id' => $t->getID(), 'status' => CommonITILObject::SOLVED, '_actors' => ['requester' => [['itemtype' => 'Group', 'items_id' => $gB]], 'observer' => [], 'assign' => []]]);
$t->getFromDB($t->getID()); messages();
check('requester group changed to B in same save: blocked', (int) $t->fields['status'] !== CommonITILObject::SOLVED);

// Summary
$filters = RuleFilter::getForRules([1])[1];
echo '  summary: ', RuleFilter::summarize($filters, 'AND'), "\n";
$f->delete(['id' => $fid], true);
$DB->update(ActionRule::getTable(), ['is_active' => 1], ['id' => [2, 3]]);
echo $fails === 0 ? "ALL PASSED\n" : "$fails FAILED\n";
