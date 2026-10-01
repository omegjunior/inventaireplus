<?php
/* Copyright (C) 2026 Omega Junior
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * Product autocomplete for collaborative inventory counting.
 */

if (!defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL', 1);
if (!defined('NOREQUIREMENU')) define('NOREQUIREMENU', '1');
if (!defined('NOREQUIREHTML')) define('NOREQUIREHTML', '1');
if (!defined('NOREQUIREAJAX')) define('NOREQUIREAJAX', '1');

$res = 0;
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/inventory/class/inventory.class.php';

/** @var Conf $conf */
/** @var DoliDB $db */
/** @var HookManager $hookmanager */
/** @var Translate $langs */
/** @var User $user */

top_httphead('application/json');

$inventoryId = GETPOSTINT('inventoryid');
$warehouseId = GETPOSTINT('warehouseid');
$htmlname = GETPOST('htmlname', 'aZ09');

$canCount = empty($user->socid)
	&& ($user->admin || $user->hasRight('stock', 'lire') || $user->hasRight('stock', 'inventory_advance', 'read') || $user->hasRight('stock', 'inventory_advance', 'write'))
	&& $user->hasRight('inventaireplus', 'collaborativecount', 'write');
if (!$canCount || $inventoryId <= 0 || $warehouseId <= 0 || $htmlname !== 'product_token') {
	print json_encode(array());
	exit;
}

$inventory = new Inventory($db);
if ($inventory->fetch($inventoryId) <= 0
	|| (int) $inventory->entity !== (int) getEntity('inventory')
	|| (int) $inventory->fk_warehouse !== $warehouseId
	|| (int) $inventory->status !== (int) Inventory::STATUS_VALIDATED) {
	print json_encode(array());
	exit;
}

$searchKey = trim(GETPOST($htmlname, 'alphanohtml'));
if ($searchKey === '') {
	print json_encode(array());
	exit;
}

$langs->loadLangs(array('main', 'products', 'stocks'));
$hookmanager->initHooks(array('inventaireplusproductsearch'));

$form = new Form($db);
$result = $form->select_produits_list(
	0,
	$htmlname,
	'0',
	getDolGlobalInt('PRODUIT_LIMIT_SIZE', 1000),
	0,
	$searchKey,
	-1,
	2,
	1,
	0,
	'1',
	0,
	'',
	1,
	'',
	-1,
	0
);

print json_encode($result);
$db->close();
