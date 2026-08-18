<?php
/* Copyright (C) 2026 Omega Junior
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file        htdocs/custom/inventaireplus/product/inventory/customselection.php
 * \ingroup     inventaireplus
 * \brief       Create a native Dolibarr inventory from a user-defined product selection
 */

$res = 0;
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/html.formproduct.class.php';
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/inventaireplus/lib/inventoryselection.lib.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('main', 'stocks', 'products', 'categories', 'inventaireplus@inventaireplus'));

if ($user->socid > 0) {
	accessforbidden();
}

$permissionInventoryWrite = ($user->hasRight('stock', 'inventory_advance', 'write') || $user->hasRight('stock', 'creer'));
$permissionInventairePlus = $user->hasRight('inventaireplus', 'custominventoryselection', 'create');
if (!$permissionInventoryWrite || !$permissionInventairePlus) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$ref = trim(GETPOST('ref', 'alphanohtml'));
$title = trim(GETPOST('title', 'restricthtml'));
$warehouseId = GETPOSTINT('fk_warehouse');
$selectedProductIds = array_values(array_filter(array_map('intval', GETPOST('product_multiselect', 'array'))));
$selectedCategoryIds = array_values(array_filter(array_map('intval', GETPOST('category_multiselect', 'array'))));
$productSelectionRaw = trim(GETPOST('product_selection', 'restricthtml'));
$dateInventory = dol_mktime(12, 0, 0, GETPOSTINT('date_inventorymonth'), GETPOSTINT('date_inventoryday'), GETPOSTINT('date_inventoryyear'));

$form = new Form($db);
$formproduct = new FormProduct($db);

/*
 * Actions
 */
if ($action === 'createfromselection') {
	$error = 0;
	$tokens = inventaireplusParseProductSelectionTokens($productSelectionRaw);
	$selectedProductIds = array_values(array_unique(array_filter($selectedProductIds, function ($value) { return $value > 0; })));
	$selectedCategoryIds = array_values(array_unique(array_filter($selectedCategoryIds, function ($value) { return $value > 0; })));
	if ($ref === '') {
		$error++;
		setEventMessages($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('Ref')), null, 'errors');
	}
	if ($warehouseId <= 0) {
		$error++;
		setEventMessages($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('Warehouse')), null, 'errors');
	}
	if (empty($tokens) && empty($selectedProductIds) && empty($selectedCategoryIds)) {
		$error++;
		setEventMessages($langs->trans('InventoryPlusProductSelectionRequired'), null, 'errors');
	}

	if (!$error) {
		$selection = inventaireplusResolveProductSelection($db, $tokens);
		if (!empty($selection['unresolved'])) {
			setEventMessages($langs->trans('InventoryPlusUnresolvedProducts', implode(', ', $selection['unresolved'])), null, 'warnings');
		}
		$categoryProductIds = inventaireplusResolveProductCategorySelection($db, $selectedCategoryIds, true);
		$productIds = array_values(array_unique(array_merge($selectedProductIds, $categoryProductIds, $selection['ids'])));
		if (empty($productIds)) {
			$error++;
			setEventMessages($langs->trans('InventoryPlusNoResolvedProduct'), null, 'errors');
		} else {
			$lines = inventaireplusFetchSelectedInventoryLines($db, $productIds, $warehouseId);
			if (empty($lines)) {
				$error++;
				setEventMessages($langs->trans('InventoryPlusNoLineForSelectedProducts'), null, 'errors');
			} else {
				$inventoryId = inventaireplusCreateInventoryFromSelection($db, $user, $ref, $title, $warehouseId, $dateInventory, $lines);
				if ($inventoryId > 0) {
					header('Location: '.DOL_URL_ROOT.'/product/inventory/inventory.php?id='.$inventoryId);
					exit;
				}
				setEventMessages($langs->trans('InventoryPlusCustomSelectionCreateFailed'), null, 'errors');
			}
		}
	}
}

/*
 * View
 */
$titlePage = $langs->trans('InventoryPlusCustomSelectionTitle');
llxHeader('', $titlePage, '', '', 0, 0, array(), array(), '', 'mod-inventaireplus page-custom-inventory-selection');

print load_fiche_titre($titlePage, '<a href="'.DOL_URL_ROOT.'/product/inventory/card.php?action=create&leftmenu=stock_inventories">'.$langs->trans('BackToStandardCreation').'</a>', 'inventory');

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="createfromselection">';

print dol_get_fiche_head(array(), '');
print '<table class="border centpercent tableforfieldcreate">';
print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans('Ref').'</td><td><input class="flat minwidth300" type="text" name="ref" value="'.dol_escape_htmltag($ref !== '' ? $ref : 'INVSEL-'.dol_print_date(dol_now(), '%Y%m%d%H%M%S')).'"></td></tr>';
print '<tr><td>'.$langs->trans('Label').'</td><td><input class="flat minwidth500" type="text" name="title" value="'.dol_escape_htmltag($title).'"></td></tr>';
print '<tr><td class="fieldrequired">'.$langs->trans('Warehouse').'</td><td>'.$formproduct->selectWarehouses($warehouseId, 'fk_warehouse', 'warehouseopen,warehouseinternal', 1, 0, 0, '', 0, 0, array(), 'minwidth300').'</td></tr>';
if (getDolGlobalString('STOCK_INVENTORY_ADD_A_VALUE_DATE')) {
	print '<tr><td>'.$langs->trans('DateValue').'</td><td>'.$form->selectDate($dateInventory, 'date_inventory', 0, 0, 1, '', 1, 1).'</td></tr>';
}
print '<tr><td class="tdtop">'.$langs->trans('InventoryPlusProductMultiSelection').'</td><td>';
print '<select class="flat quatrevingtpercent inventaireplus-product-multiselect" id="product_multiselect" name="product_multiselect[]" multiple="multiple">';
if (!empty($selectedProductIds)) {
	$productLabels = inventaireplusFetchProductSelectionLabels($db, $selectedProductIds);
	foreach ($selectedProductIds as $selectedProductId) {
		if (!empty($productLabels[$selectedProductId])) {
			print '<option value="'.((int) $selectedProductId).'" selected="selected">'.dol_escape_htmltag($productLabels[$selectedProductId]).'</option>';
		}
	}
}
print '</select>';
print '<div class="opacitymedium">'.$langs->trans('InventoryPlusProductMultiSelectionHelp').'</div>';
print '</td></tr>';
print '<tr><td class="tdtop">'.$langs->trans('InventoryPlusCategoryMultiSelection').'</td><td>';
$productCategoryOptions = $form->select_all_categories(Categorie::TYPE_PRODUCT, '', '', 64, 0, 1);
print $form->multiselectarray('category_multiselect', $productCategoryOptions, $selectedCategoryIds, 0, 0, 'quatrevingtpercent', 0, 0, '', '', $langs->transnoentitiesnoconv('InventoryPlusCategoryMultiSelectionPlaceholder'), 1);
print '<div class="opacitymedium">'.$langs->trans('InventoryPlusCategoryMultiSelectionHelp').'</div>';
print '</td></tr>';
print '<tr><td class="tdtop">'.$langs->trans('InventoryPlusProductSelection').'</td><td>';
print '<textarea class="flat quatrevingtpercent" name="product_selection" rows="12">'.dol_escape_htmltag($productSelectionRaw).'</textarea>';
print '<div class="opacitymedium">'.$langs->trans('InventoryPlusProductSelectionHelp').'</div>';
print '</td></tr>';
print '</table>';
print dol_get_fiche_end();

print $form->buttonsSaveCancel($langs->trans('InventoryPlusCreateAndStart'), DOL_URL_ROOT.'/product/inventory/card.php?action=create&leftmenu=stock_inventories');
print '</form>';

print '<script>
jQuery(function() {
	var productSelect = jQuery("#product_multiselect");
	if (!productSelect.length || !jQuery.fn.select2) return;

	function inventoryPlusCleanProductLabel(label) {
		if (label === undefined || label === null) return "";
		var cleanLabel = jQuery("<textarea/>").html(String(label)).text();
		cleanLabel = cleanLabel.replace(/<[^>]*>/g, " ");
		cleanLabel = cleanLabel.replace(/\s+/g, " ");
		return jQuery.trim(cleanLabel);
	}

	function inventoryPlusProductLabel(item) {
		var label = "";
		if (item && item.label !== undefined && item.label !== "") {
			label = item.label;
		} else if (item && item.text !== undefined && item.text !== "") {
			label = item.text;
		} else if (item && item.value !== undefined && item.value !== "") {
			label = item.value;
		} else if (item && item.label2 !== undefined && item.label2 !== "") {
			label = item.label2;
		} else if (item && item.key !== undefined) {
			label = item.key;
		} else if (item && item.id !== undefined) {
			label = item.id;
		}
		return inventoryPlusCleanProductLabel(label);
	}

	productSelect.select2({
		width: "resolve",
		placeholder: "'.dol_escape_js($langs->transnoentitiesnoconv('InventoryPlusProductMultiSelectionPlaceholder')).'",
		allowClear: true,
		minimumInputLength: 2,
		ajax: {
			url: "'.DOL_URL_ROOT.'/product/ajax/products.php",
			dataType: "json",
			delay: 250,
			data: function(params) {
				return {
					htmlname: "product_multiselect",
					product_multiselect: params.term || "",
					outjson: 1,
					type: "'.(getDolGlobalString('STOCK_SUPPORTS_SERVICES') ? '' : '0').'",
					mode: 1,
					status: -1,
					status_purchase: -1,
					finished: 2,
					hidepriceinlabel: 1,
					warehouseid: jQuery("#fk_warehouse").val() || 0
				};
			},
			processResults: function(data) {
				var results = [];
				jQuery.each(data || [], function(index, item) {
					if (item && item.key !== undefined) {
						results.push({ id: item.key, text: inventoryPlusProductLabel(item) });
					} else if (item && item.id !== undefined) {
						results.push({ id: item.id, text: inventoryPlusProductLabel(item) });
					}
				});
				return { results: results };
			}
		}
	});

	jQuery("#fk_warehouse").on("change", function() {
		productSelect.val(null).trigger("change");
	});
});
</script>';

llxFooter();
$db->close();
