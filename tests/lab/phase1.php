<?php
require __DIR__ . '/boot.php';
use GlpiPlugin\Fieldconstraints\ActionBlock;
use GlpiPlugin\Fieldconstraints\ActionRule;
$rede = ensure('ITILCategory', ['name' => 'Rede', 'itilcategories_id' => 0], ['is_recursive' => 1]);
$wifi = ensure('ITILCategory', ['name' => 'Wi-Fi', 'itilcategories_id' => $rede], ['is_recursive' => 1]);
$imp  = ensure('ITILCategory', ['name' => 'Impressoras', 'itilcategories_id' => 0], ['is_recursive' => 1]);
$block = ensure(ActionBlock::class, ['name' => "Solucionar d'avó \"teste\""], ['status' => CommonITILObject::SOLVED, 'entities_id' => 0, 'is_recursive' => 1]);
$r = new ActionRule();
$r->add([ActionRule::$items_id => $block, 'itemtype' => 'Ticket', 'itilcategories' => [$rede], '_itilcategories_posted' => 1, 'ticket_type' => Ticket::INCIDENT_TYPE, 'required_fields' => ['native:locations_id'], '_required_fields_posted' => 1]);
$r->add([ActionRule::$items_id => $block, 'itemtype' => 'Change', 'itilcategories' => [$imp, $rede], '_itilcategories_posted' => 1, 'required_fields' => ['native:locations_id'], '_required_fields_posted' => 1]);
$r->add([ActionRule::$items_id => $block, 'itemtype' => 'Problem', 'required_fields' => ['native:locations_id'], '_required_fields_posted' => 1]);
global $DB;
foreach ($DB->request(['FROM' => ActionRule::getTable()]) as $row) { echo json_encode([$row['id'], $row['itemtype'], $row['itilcategories'], $row['ticket_type']]), "\n"; }
echo messages(), "\n";
