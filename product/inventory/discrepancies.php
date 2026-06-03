<?php
/* Copyright (C) 2026 Omega Junior
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file        htdocs/custom/inventaireplus/product/inventory/discrepancies.php
 * \ingroup     inventaireplus
 * \brief       Custom page to edit justifications for inventory discrepancies
 */

$res = 0;
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}
require_once DOL_DOCUMENT_ROOT.'/product/inventory/class/inventory.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/inventory/lib/inventory.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/inventaireplus/lib/inventorydocs.lib.php';

$langs->loadLangs(array('stocks', 'products', 'inventaireplus@inventaireplus'));

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');

$permissiontoread = ($user->hasRight('stock', 'lire') || $user->hasRight('stock', 'inventory_advance', 'read'));
$permissiontowrite = ($user->hasRight('stock', 'creer') || $user->hasRight('stock', 'inventory_advance', 'write'));

$object = new Inventory($db);
$form = new Form($db);
$loaderror = '';
$errorhtml = '';

/**
 * Upsert justification on one inventory line extrafields row.
 *
 * @param DoliDB $db Database handler
 * @param int    $lineId Inventory line id
 * @param string $justification Justification text
 * @return bool
 */
function inventaireplusUpsertInventoryLineJustification($db, $lineId, $justification)
{
	$lineId = (int) $lineId;
	if ($lineId <= 0) {
		return false;
	}

	$justification = (string) $justification;
	$sql = "SELECT fk_object FROM ".MAIN_DB_PREFIX."inventorydet_extrafields WHERE fk_object = ".$lineId." LIMIT 1";
	$resql = $db->query($sql);
	if (!$resql) {
		return false;
	}
	$exists = ($db->fetch_object($resql) ? true : false);
	$db->free($resql);

	if ($exists) {
		$sql = "UPDATE ".MAIN_DB_PREFIX."inventorydet_extrafields";
		$sql .= " SET justification_text = '".$db->escape($justification)."'";
		$sql .= " WHERE fk_object = ".$lineId;
	} else {
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."inventorydet_extrafields (fk_object, justification_text)";
		$sql .= " VALUES (".$lineId.", '".$db->escape($justification)."')";
	}

	return (bool) $db->query($sql);
}

/**
 * Format expiry date from one dataset line.
 *
 * @param DoliDB $db Database handler
 * @param array  $line Inventory line
 * @return string
 */
function inventaireplusFormatInventoryLineExpiry($db, $line)
{
	$dateValue = (!empty($line['sellby']) ? $line['sellby'] : (!empty($line['eatby']) ? $line['eatby'] : null));
	if (empty($dateValue)) {
		return '';
	}

	return dol_print_date($db->jdate($dateValue), 'day');
}

/*
 * Actions
 */
$dataset = array('context' => array(), 'categories' => array(), 'lines' => array());

if (!$permissiontoread) {
	$loaderror = $langs->trans('NotEnoughPermissions');
} elseif ($id <= 0 || $object->fetch($id) <= 0) {
	$loaderror = 'Inventaire introuvable';
} else {
	$dataset = inventaireplusBuildInventoryDocumentDataset($db, $object->id, true);
}

if (empty($loaderror) && $action === 'savejustifications' && $permissiontowrite) {
	$justifications = GETPOST('justification', 'array');
	$db->begin();
	$error = 0;

	foreach ($dataset['lines'] as $line) {
		$lineId = (int) $line['rowid'];
		$justification = (is_array($justifications) && array_key_exists($lineId, $justifications) ? $justifications[$lineId] : $line['justification_text']);
		if (!inventaireplusUpsertInventoryLineJustification($db, $lineId, $justification)) {
			$error++;
			break;
		}
	}

	if (!$error) {
		$db->commit();
		setEventMessages($langs->trans('InventoryJustificationsSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id .'&mainmenu=products');
		exit;
	}

	$db->rollback();
	$errorhtml = dol_htmloutput_errors($langs->trans('ErrorFailedToSaveInventoryJustifications'), array(), 1);
}

/* 
* View
*/
$title = $langs->trans('InventoryDiscrepanciesTitle');
$help_url = '';

llxHeader('', $title, $help_url, '', 0, 0, '', '', '', 'mod-product page-inventory_inventory');

print '<style>
.inventaireplus-category-row td {
	background: #f3f4f6;
	font-weight: 600;
	border-top: 1px solid #d8dbe1;
}
</style>';

if (!empty($errorhtml)) {
	print $errorhtml;
}
if (!empty($loaderror)) {
	print dol_htmloutput_errors($loaderror, array(), 1);
	llxFooter();
	$db->close();
	exit;
}

$confirmButtonId = 'open-save-justifications-confirm';
$confirmDialogId = 'dialog-confirm-'.$confirmButtonId;
print '<button type="button" id="'.$confirmButtonId.'" class="hideobject"></button>';
print $form->formconfirm(
	$_SERVER['PHP_SELF'].'?id='.(int) $object->id.'&mainmenu=products',
	$langs->trans('Confirm'),
	$langs->trans('ConfirmSaveInventoryJustifications'),
	'noop',
	array(),
	'',
	$confirmButtonId,
	180,
	500
);

$head = inventoryPrepareHead($object);

print dol_get_fiche_head($head, 'inventaireplus-discrepancies', $langs->trans('InventoryDiscrepanciesTitle'), -1, 'stock');

$linkback = '<a href="'.DOL_URL_ROOT.'/product/inventory/list.php">'.$langs->trans("BackToList").'</a>';

// to avoid to show editable button for title when we are in discrepancies page, we set object fiels[title][alwayseditable] with a 0 value. This way, banner will not be editable and will not show pencil icon.
$object->fields['title']['alwayseditable'] = 0;

dol_banner_tab($object, 'ref', $linkback, 0);

print '<div class="fichecenter">';
print '<div class="fichehalfleft">';
print '<div class="underbanner clearboth"></div>';
print '<table class="border centpercent tableforfield">'."\n";

// Common attributes
include DOL_DOCUMENT_ROOT.'/core/tpl/commonfields_view.tpl.php';

// Other attributes. Fields from hook formObjectOptions and Extrafields.
include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_view.tpl.php';

//print '<tr><td class="titlefield fieldname_invcode">'.$langs->trans("InventoryCode").'</td><td>INV'.$object->id.'</td></tr>';

print '</table>';
print '</div>';
print '</div>';

print '<div class="clearboth"></div>';

print dol_get_fiche_end();


if (empty($dataset['lines'])) {
	print '<div class="warning">'.$langs->trans('NoInventoryDiscrepancy').'</div>';
	llxFooter();
	$db->close();
	exit;
}

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.(int) $object->id.'&mainmenu=products" id="inventory-justifications-form">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="savejustifications">';

print '<div class="div-table-responsive">';
print '<table class="liste centpercent">';
print '<tr class="liste_titre">';
print '<th class="center" style="width: 40px;">N°</th>';
print '<th>'.$langs->trans('Ref').'</th>';
print '<th>'.$langs->trans('Designation').'</th>';
print '<th>'.$langs->trans('Batch').'</th>';
print '<th>'.$langs->trans('ExpiryDateInventairePlus').'</th>';
print '<th class="right">'.$langs->trans('InventoryTheoreticalQty').'</th>';
print '<th class="right">'.$langs->trans('InventoryPhysicalQty').'</th>';
print '<th class="right">'.$langs->trans('InventoryDeltaQty').'</th>';
print '<th>'.$langs->trans('InventoryJustification').'</th>';
print '</tr>';

$lineNumber = 1;
foreach ($dataset['categories'] as $category) {
	print '<tr class="inventaireplus-category-row">';
	print '<td colspan="9">'.dol_escape_htmltag($category['label']).'</td>';
	print '</tr>';

	foreach ($category['lines'] as $line) {
		print '<tr class="oddeven">';
		print '<td class="center">'.$lineNumber.'</td>';
		print '<td>'.dol_escape_htmltag($line['product_ref']).'</td>';
		print '<td>'.dol_escape_htmltag($line['product_label']).'</td>';
		print '<td>'.dol_escape_htmltag($line['batch']).'</td>';
		print '<td>'.dol_escape_htmltag(inventaireplusFormatInventoryLineExpiry($db, $line)).'</td>';
		print '<td class="right">'.($line['qty_theoretical']).'</td>';
		print '<td class="right">'.($line['qty_physical']).'</td>';
		print '<td class="right">'.($line['qty_delta']).'</td>';
		print '<td><textarea class="flat centpercent" rows="3" name="justification['.(int) $line['rowid'].']">'.dol_escape_htmltag($line['justification_text']).'</textarea></td>';
		print '</tr>';
		$lineNumber++;
	}
}

print '</table>';
print '</div>';
// Button for actions
print '<div class="tabsAction">';
if ($permissiontowrite) {
	print '<button class="butAction" type="button" id="save-justifications-button">'.$langs->trans('SaveJustifications').'</button>';
}
print '</div>';
print '</form>';

print '<script>
document.addEventListener("DOMContentLoaded", function () {
	var form = document.getElementById("inventory-justifications-form");
	var saveButton = document.getElementById("save-justifications-button");
	var openConfirmButton = document.getElementById('.json_encode($confirmButtonId).');
	var dialogSelector = '.json_encode('#'.$confirmDialogId).';
	if (!form || !saveButton || !openConfirmButton) return;

	saveButton.addEventListener("click", function () {
		openConfirmButton.click();
	});

	if (window.jQuery) {
		window.jQuery(dialogSelector).on("dialogopen", function () {
			var dialog = window.jQuery(this).parent();
			var buttons = dialog.find(".ui-dialog-buttonpane button");
			if (buttons.length > 0) {
				window.jQuery(buttons.get(0)).off("click").on("click", function (event) {
					event.preventDefault();
					window.jQuery(dialogSelector).dialog("close");
					form.submit();
					return false;
				});
			}
			if (buttons.length > 1) {
				window.jQuery(buttons.get(1)).off("click").on("click", function (event) {
					event.preventDefault();
					window.jQuery(dialogSelector).dialog("close");
					return false;
				});
			}
		});
	}
});
</script>';

llxFooter();
$db->close();

