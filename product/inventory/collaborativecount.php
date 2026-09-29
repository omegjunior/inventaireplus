<?php
/* Copyright (C) 2026 Omega Junior
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * Concurrent and traceable counting UI for a native Dolibarr inventory.
 */

$res = 0;
if (!$res && file_exists('../../../../main.inc.php')) {
	$res = @include '../../../../main.inc.php';
}
if (!$res) die('Include of main fails');

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/inventory/class/inventory.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/inventaireplus/lib/inventorycollaborative.lib.php';

/** @var Conf $conf */
/** @var DoliDB $db */
/** @var Translate $langs */
/** @var User $user */

$langs->loadLangs(array('main', 'stocks', 'products', 'productbatch', 'inventaireplus@inventaireplus'));
if ($user->socid > 0) accessforbidden();

$inventoryId = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$canReadInventory = ($user->hasRight('stock', 'lire') || $user->hasRight('stock', 'inventory_advance', 'read') || $user->hasRight('stock', 'inventory_advance', 'write'));
$canCount = $canReadInventory && $user->hasRight('inventaireplus', 'collaborativecount', 'write');
$canConsolidate = $canReadInventory && $user->hasRight('inventaireplus', 'collaborativecount', 'consolidate') && ($user->hasRight('stock', 'inventory_advance', 'write') || $user->hasRight('stock', 'mouvement', 'creer') || $user->hasRight('stock', 'creer'));
if (!$canCount && !$canConsolidate) accessforbidden();

$object = new Inventory($db);
if ($inventoryId <= 0 || $object->fetch($inventoryId) <= 0) accessforbidden();
if ((int) $object->entity !== (int) getEntity('inventory')) accessforbidden();

$session = inventaireplusFetchCountSession($db, $inventoryId);

/*
 * Actions
 */
if ($action === 'addcontribution' && $canCount) {
	$productToken = trim(GETPOST('product_token', 'alphanohtml'));
	$batch = trim(GETPOST('batch', 'restricthtml'));
	$zone = trim(GETPOST('zone', 'restricthtml'));
	$qtyRaw = GETPOST('qty', 'alphanohtml');
	$qty = (float) price2num($qtyRaw, 'MS');
	$result = inventaireplusAddCountContribution($db, $user, $inventoryId, $productToken, $batch, $zone, $qty, GETPOST('scan_key', 'alphanohtml'));
	if (!empty($result['ok'])) {
		setEventMessages($langs->trans('InventoryPlusCollaborativeContributionSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$inventoryId);
		exit;
	}
	setEventMessages($langs->trans($result['error']), null, 'errors');
}

if ($action === 'voidcontribution' && $confirm === 'yes' && ($canCount || $canConsolidate)) {
	$contributionId = GETPOSTINT('contribution_id');
	if (inventaireplusVoidCountContribution($db, $user, $inventoryId, $contributionId, $canConsolidate)) {
		setEventMessages($langs->trans('InventoryPlusCollaborativeContributionVoided'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('InventoryPlusCollaborativeContributionVoidFailed'), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$inventoryId);
	exit;
}

if ($action === 'consolidate' && $confirm === 'yes' && $canConsolidate) {
	$result = inventaireplusConsolidateCollaborativeCount($db, $user, $inventoryId);
	if (!empty($result['ok'])) {
		setEventMessages($langs->trans('InventoryPlusCollaborativeConsolidated', $result['lines']), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$inventoryId);
		exit;
	}
	setEventMessages($langs->trans($result['error']), null, 'errors');
}

$session = inventaireplusFetchCountSession($db, $inventoryId);
$sessionStatus = ($session ? (int) $session->status : 0);
$totals = ($session ? inventaireplusFetchCollaborativeTotals($db, (int) $session->rowid) : array());
$contributions = ($session ? inventaireplusFetchRecentContributions($db, (int) $session->rowid) : array());
$campaignOpen = ($sessionStatus === 0 && (int) $object->status === Inventory::STATUS_VALIDATED);
$scanKey = bin2hex(random_bytes(16));
$form = new Form($db);
$formConfirm = '';
if ($action === 'confirm_consolidate' && $canConsolidate && $campaignOpen) {
	$formConfirm = $form->formconfirm(
		$_SERVER['PHP_SELF'].'?id='.$inventoryId,
		$langs->trans('InventoryPlusCollaborativeConsolidate'),
		$langs->trans('InventoryPlusCollaborativeConfirmConsolidate'),
		'consolidate',
		'',
		0,
		1
	);
} elseif ($action === 'confirm_voidcontribution' && ($canCount || $canConsolidate) && $campaignOpen) {
	$contributionId = GETPOSTINT('contribution_id');
	$contributionToVoid = null;
	foreach ($contributions as $contribution) {
		if ((int) $contribution->rowid === $contributionId && (int) $contribution->active === 1 && ($canConsolidate || (int) $contribution->fk_user_author === (int) $user->id)) {
			$contributionToVoid = $contribution;
			break;
		}
	}
	if ($contributionToVoid) {
		$formConfirm = $form->formconfirm(
			$_SERVER['PHP_SELF'].'?id='.$inventoryId.'&contribution_id='.$contributionId,
			$langs->trans('InventoryPlusCollaborativeVoidContribution'),
			$langs->trans('InventoryPlusCollaborativeConfirmVoidContribution', dol_escape_htmltag($contributionToVoid->ref), price($contributionToVoid->qty), dol_escape_htmltag($contributionToVoid->zone)),
			'voidcontribution',
			'',
			0,
			1
		);
	}
}

/*
 * View
 */
$title = $langs->trans('InventoryPlusCollaborativeCount');
llxHeader('', $title, '', '', 0, 0, array(), array(), '', 'mod-inventaireplus page-collaborative-count');

print $formConfirm;

print load_fiche_titre($title, '<a href="'.DOL_URL_ROOT.'/product/inventory/inventory.php?id='.$inventoryId.'">'.$langs->trans('BackToInventory').'</a>', 'barcode');
print '<div class="fichecenter">';
print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">'.$langs->trans('Ref').'</td><td>'.dol_escape_htmltag($object->ref).'</td></tr>';
print '<tr><td>'.$langs->trans('Label').'</td><td>'.dol_escape_htmltag($object->title).'</td></tr>';
print '<tr><td>'.$langs->trans('Status').'</td><td>'.(($sessionStatus === 0) ? $langs->trans('InventoryPlusCollaborativeOpen') : $langs->trans('InventoryPlusCollaborativeClosed')).'</td></tr>';
print '</table>';
print '</div><br>';

if ($campaignOpen && $canCount) {
	print '<form id="inventaireplus-count-form" method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="addcontribution">';
	print '<input type="hidden" name="id" value="'.$inventoryId.'">';
	print '<input type="hidden" name="scan_key" value="'.dol_escape_htmltag($scanKey).'">';
	print dol_get_fiche_head(array(), '');
	print '<table class="border centpercent tableforfieldcreate">';
	print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans('InventoryPlusCollaborativeZone').'</td><td><input class="flat minwidth300" id="inventaireplus-zone" name="zone" value="'.dol_escape_htmltag(GETPOST('zone', 'restricthtml')).'" maxlength="128" required></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('Qty').'</td><td><input class="flat maxwidth100 right" id="inventaireplus-qty" name="qty" value="'.dol_escape_htmltag(GETPOST('qty', 'alphanohtml')).'" inputmode="decimal" required></td></tr>';
	if (isModEnabled('productbatch')) {
		print '<tr><td>'.$langs->trans('Batch').'</td><td><input class="flat minwidth300" name="batch" value="'.dol_escape_htmltag(GETPOST('batch', 'restricthtml')).'" maxlength="128" autocomplete="off"></td></tr>';
	}
	print '<tr><td class="fieldrequired">'.$langs->trans('Product').'</td><td><input class="flat minwidth300" id="inventaireplus-product-token" name="product_token" value="'.dol_escape_htmltag(GETPOST('product_token', 'alphanohtml')).'" maxlength="255" autocomplete="off" required> <span class="opacitymedium">'.$langs->trans('InventoryPlusCollaborativeProductHelp').'</span></td></tr>';
	print '</table>';
	print dol_get_fiche_end();
	print '<div class="center"><input class="button button-save" type="submit" value="'.$langs->trans('InventoryPlusCollaborativeAddContribution').'"></div>';
	print '</form><br>';
} elseif ((int) $object->status !== Inventory::STATUS_VALIDATED) {
	print '<div class="warning">'.$langs->trans('InventoryPlusCollaborativeInventoryNotOpen').'</div>';
}

print load_fiche_titre($langs->trans('InventoryPlusCollaborativeTotals'), '', 'list');
print '<div class="info">'.$langs->trans('InventoryPlusCollaborativeConsolidationScope').'</div>';
print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('Ref').'</th><th>'.$langs->trans('Label').'</th>'.(isModEnabled('productbatch') ? '<th>'.$langs->trans('Batch').'</th>' : '').'<th class="right">'.$langs->trans('InventoryPlusCollaborativeScans').'</th><th class="right">'.$langs->trans('InventoryTheoreticalQty').'</th><th class="right">'.$langs->trans('InventoryPhysicalQty').'</th><th class="right">'.$langs->trans('InventoryDeltaQty').'</th></tr>';
if (empty($totals)) print '<tr><td colspan="'.(isModEnabled('productbatch') ? 7 : 6).'" class="opacitymedium">'.$langs->trans('None').'</td></tr>';
foreach ($totals as $row) {
	print '<tr class="oddeven"><td>'.dol_escape_htmltag($row->ref).'</td><td>'.dol_escape_htmltag($row->label).'</td>';
	if (isModEnabled('productbatch')) print '<td>'.dol_escape_htmltag($row->batch).'</td>';
	print '<td class="right">'.((int) $row->contribution_count).'</td><td class="right">'.price($row->qty_stock).'</td><td class="right">'.price($row->counted_qty).'</td><td class="right">'.price($row->counted_qty - $row->qty_stock).'</td></tr>';
}
print '</table></div><br>';

print load_fiche_titre($langs->trans('InventoryPlusCollaborativeRecentContributions'), '', 'history');
print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('Date').'</th><th>'.$langs->trans('User').'</th><th>'.$langs->trans('InventoryPlusCollaborativeZone').'</th><th>'.$langs->trans('Product').'</th>'.(isModEnabled('productbatch') ? '<th>'.$langs->trans('Batch').'</th>' : '').'<th class="right">'.$langs->trans('Qty').'</th><th></th></tr>';
if (empty($contributions)) print '<tr><td colspan="'.(isModEnabled('productbatch') ? 7 : 6).'" class="opacitymedium">'.$langs->trans('None').'</td></tr>';
foreach ($contributions as $row) {
	print '<tr class="oddeven'.(!$row->active ? ' opacitymedium' : '').'"><td>'.dol_print_date($db->jdate($row->datec), 'dayhour').'</td><td>'.dol_escape_htmltag($row->login).'</td><td>'.dol_escape_htmltag($row->zone).'</td><td>'.dol_escape_htmltag($row->ref.' - '.$row->label).'</td>';
	if (isModEnabled('productbatch')) print '<td>'.dol_escape_htmltag($row->batch).'</td>';
	print '<td class="right">'.price($row->qty).(!$row->active ? ' ('.$langs->trans('Canceled').')' : '').'</td><td class="center">';
	if ($campaignOpen && $row->active && ($canConsolidate || (int) $row->fk_user_author === (int) $user->id)) {
		$voidUrl = $_SERVER['PHP_SELF'].'?id='.$inventoryId.'&action=confirm_voidcontribution&contribution_id='.((int) $row->rowid).'&token='.newToken();
		print '<a class="reposition" href="'.dol_escape_htmltag($voidUrl).'">'.img_delete().'</a>';
	}
	print '</td></tr>';
}
print '</table></div>';

if ($canConsolidate && $campaignOpen) {
	print '<div class="tabsAction">';
	print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?id='.$inventoryId.'&action=confirm_consolidate&token='.newToken().'">'.$langs->trans('InventoryPlusCollaborativeConsolidate').'</a>';
	print '</div>';
}

print '<script>
jQuery(function() {
	var form = jQuery("#inventaireplus-count-form");
	var zone = jQuery("#inventaireplus-zone");
	var qty = jQuery("#inventaireplus-qty");
	var product = jQuery("#inventaireplus-product-token");
	if (!form.length) return;

	var storageSuffix = "_'.((int) $inventoryId).'_'.((int) $user->id).'";
	var zoneStorageKey = "inventaireplus_count_zone" + storageSuffix;
	var qtyStorageKey = "inventaireplus_count_qty" + storageSuffix;
	var savedZone = window.localStorage.getItem(zoneStorageKey);
	var savedQty = window.localStorage.getItem(qtyStorageKey);
	if (!zone.val() && savedZone) zone.val(savedZone);
	if (!qty.val() && savedQty) qty.val(savedQty);
	zone.on("change input", function() {
		window.localStorage.setItem(zoneStorageKey, zone.val());
	});
	qty.on("change input", function() {
		qty[0].setCustomValidity("");
		window.localStorage.setItem(qtyStorageKey, qty.val());
	});

	product.on("keydown", function(event) {
		if (event.key !== "Enter" && event.which !== 13) return;
		event.preventDefault();
		if (!zone[0].checkValidity()) {
			zone[0].reportValidity();
			zone.trigger("focus");
			return;
		}
		var numericQty = Number(qty.val().replace(/\\s/g, "").replace(",", "."));
		if (!qty.val().trim() || !Number.isFinite(numericQty) || numericQty <= 0) {
			qty[0].setCustomValidity("'.dol_escape_js($langs->trans('InventoryPlusCollaborativePositiveQtyRequired')).'");
			qty[0].reportValidity();
			qty.trigger("focus");
			return;
		}
		if (!product[0].checkValidity()) {
			product[0].reportValidity();
			return;
		}
		if (form[0].requestSubmit) {
			form[0].requestSubmit();
		} else {
			form[0].submit();
		}
	});

	if (!zone.val()) zone.trigger("focus");
	else if (!qty.val()) qty.trigger("focus");
	else product.trigger("focus");
});
</script>';

llxFooter();
$db->close();
