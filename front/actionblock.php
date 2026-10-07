<?php

/**
 * List of the action rule blocks.
 */

if (!defined('GLPI_ROOT')) {
    // GLPI 10 runs plugin front scripts cold; GLPI 11 has already booted.
    include_once __DIR__ . '/../../../inc/includes.php';
}

use GlpiPlugin\Fieldconstraints\ActionBlock;

Session::checkRight('config', READ);

Html::header(
    ActionBlock::getTypeName(Session::getPluralNumber()),
    '',
    'config',
    ActionBlock::class
);

Search::show(ActionBlock::class);

Html::footer();
