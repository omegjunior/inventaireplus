<?php
/* Copyright (C) 2004-2017 Laurent Destailleur <eldy@users.sourceforge.net>
 * Copyright (C) 2019      Frédéric France <frederic.france@free.fr>
 * Copyright (C) 2026	    Frédéric H Omega Junior <omegajunior.apps@gmail.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * @var Conf               $conf
 * @var Translate          $langs
 * @var DoliDB             $db
 * @var CommonObject       $object
 * @var Productbatch       $pdluo
 * @var Form               $form
 * @var FormProduct        $formproduct
 * @var FormProjets        $formproject
 * @var int                $id
 * @var string             $backtopage
 */

if (!defined('DOL_VERSION')) {
	exit(1);
}

$productref = '';
if (!empty($object->ref) && $object->element == 'product') {
	$productref = $object->ref;
}

$langs->loadLangs(array('productbatch', 'inventaireplus@inventaireplus'));

if (empty($id)) {
	$id = $object->id;
}

$pdluoid = GETPOSTINT('pdluoid');
$pdluo = new Productbatch($db);

if ($pdluoid > 0) {
	$result = $pdluo->fetch($pdluoid);
	if ($result > 0) {
		$pdluoid = $pdluo->id;
	} else {
		dol_print_error($db, $pdluo->error, $pdluo->errors);
	}
}

$isProductCorrection = ($object->element == 'product');
$isMovementCorrection = in_array($object->element, array('stock', 'stockmouvement'), true);
$defaultWarehouseId = GETPOST('dwid') ? GETPOSTINT('dwid') : (GETPOST('id_entrepot') ? GETPOSTINT('id_entrepot') : 0);
if (empty($defaultWarehouseId) && !empty($object->fk_default_warehouse)) {
	$defaultWarehouseId = (int) $object->fk_default_warehouse;
}
if (empty($defaultWarehouseId) && getDolGlobalInt('MAIN_DEFAULT_WAREHOUSE') > 0) {
	$defaultWarehouseId = getDolGlobalInt('MAIN_DEFAULT_WAREHOUSE');
}

$sellByCss = empty($conf->global->PRODUCT_DISABLE_SELLBY) ? '' : ' class="hideonsmartphone"';
$eatByCss = empty($conf->global->PRODUCT_DISABLE_EATBY) ? '' : ' class="hideonsmartphone"';
$disableSellBy = ($pdluoid > 0 ? 1 : 0);
$disableEatBy = ($pdluoid > 0 ? 1 : 0);

$sellbyselected = dol_mktime(0, 0, 0, GETPOST('sellbymonth'), GETPOST('sellbyday'), GETPOST('sellbyyear'));
$eatbyselected = dol_mktime(0, 0, 0, GETPOST('eatbymonth'), GETPOST('eatbyday'), GETPOST('eatbyyear'));
$selectedSellBy = ($pdluo->id > 0 ? $pdluo->sellby : $sellbyselected);
$selectedEatBy = ($pdluo->id > 0 ? $pdluo->eatby : $eatbyselected);
$selectedBatch = (GETPOST('batch_number') ? GETPOST('batch_number') : $pdluo->batch);
$productIdForLookup = $isProductCorrection ? (int) $object->id : 0;

print '<script type="text/javascript">
jQuery(document).ready(function() {
	function initPriceStatus() {
		if (jQuery("#mouvement").val() == "0") {
			jQuery("#unitprice").prop("disabled", false);
		} else {
			jQuery("#unitprice").prop("disabled", true);
		}
	}

	function getLookupProductId() {
		'.($isProductCorrection ? 'return '.$productIdForLookup.';' : 'return parseInt(jQuery("#product_id").val() || "0", 10);').'
	}

	function fillBatchDatesFromLot() {
		var batchNumber = jQuery("#batch_number").val();
		var productId = getLookupProductId();
		if (!batchNumber || !productId) {
			return;
		}

		jQuery.getJSON("'.DOL_URL_ROOT.'/product/ajax/product_lot.php", {
			action: "fetch",
			productid: productId,
			batch: batchNumber
		}).done(function(response) {
			if (!response || !response.result || !response.product_lot) {
				return;
			}

			if (response.product_lot.sellby && !'.$disableSellBy.') {
				var sellByDate = new Date(response.product_lot.sellby * 1000);
				jQuery("#sellbyday").val(String(sellByDate.getDate()).padStart(2, "0"));
				jQuery("#sellbymonth").val(String(sellByDate.getMonth() + 1).padStart(2, "0"));
				jQuery("#sellbyyear").val(String(sellByDate.getFullYear()));
			}

			if (response.product_lot.eatby && !'.$disableEatBy.') {
				var eatByDate = new Date(response.product_lot.eatby * 1000);
				jQuery("#eatbyday").val(String(eatByDate.getDate()).padStart(2, "0"));
				jQuery("#eatbymonth").val(String(eatByDate.getMonth() + 1).padStart(2, "0"));
				jQuery("#eatbyyear").val(String(eatByDate.getFullYear()));
			}
		});
	}

	initPriceStatus();

	jQuery("#mouvement").on("change", function() {
		initPriceStatus();
	});

	jQuery("#nbpiece").on("keyup", function(event) {
		if (event.key == "-") {
			jQuery("#nbpiece").val(jQuery("#nbpiece").val().replace("-", ""));
			jQuery("#mouvement").val("1").trigger("change");
		} else if (event.key == "+") {
			jQuery("#nbpiece").val(jQuery("#nbpiece").val().replace("+", ""));
			jQuery("#mouvement").val("0").trigger("change");
		}
	});

	jQuery("#batch_number").on("change blur", function() {
		fillBatchDatesFromLot();
	});
});
</script>';

print load_fiche_titre($langs->trans('StockCorrection'), '', 'generic');

print '<form action="'.$_SERVER['PHP_SELF'].'?id='.$id.'" method="post" id="stockcorrection" name="stockcorrection">'."\n";

print dol_get_fiche_head(array(), '', '', 0, '', 0, '', '', 0, '', 0, 'marginbottomonly');

print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="correct_stock">';
print '<input type="hidden" name="backtopage" value="'.$backtopage.'">';
if ($pdluoid) {
	print '<input type="hidden" name="pdluoid" value="'.$pdluoid.'">';
}

print '<table class="border centpercent">';

print '<tr>';
if ($isProductCorrection) {
	print '<td class="fieldrequired">'.$langs->trans('Warehouse').'</td>';
	print '<td>';
	print img_picto('', 'stock', 'class="pictofixedwidth"').$formproduct->selectWarehouses($defaultWarehouseId, 'id_entrepot', 'warehouseopen,warehouseinternal', 1, 0, 0, '', 0, 0, array(), 'minwidth100 maxwidth300 widthcentpercentminusx');
	print '</td>';
}
if ($isMovementCorrection) {
	print '<td class="fieldrequired">'.$langs->trans('Product').'</td>';
	print '<td>';
	print img_picto('', 'product');
	print $form->select_produits(GETPOSTINT('product_id'), 'product_id', (empty($conf->global->STOCK_SUPPORTS_SERVICES) ? '0' : ''), 0, 0, -1, 1, '', 0, 'warehouseopen', null, 1, 0, 'maxwidth500');
	print '</td>';
}
print '<td class="fieldrequired">'.$langs->trans('NumberOfUnit').'</td>';
print '<td>';
if ($isProductCorrection || $isMovementCorrection) {
	print '<select name="mouvement" id="mouvement" class="minwidth100 valignmiddle">';
	print '<option value="1" selected="selected">'.$langs->trans('DeleteInventairePlus').'</option>';
	print '</select>';
}
$quantityFieldType = (isset($conf->global->STOCK_QUANTITY_ALLOW_DECIMAL_VALUE) && $conf->global->STOCK_QUANTITY_ALLOW_DECIMAL_VALUE === '0') ? ' type="number"' : '';
print '<input name="nbpiece" id="nbpiece" class="center valignmiddle maxwidth75"'.$quantityFieldType.' value="'.GETPOST('nbpiece').'">';
print '</td>';
print '</tr>';

if (!empty($conf->global->PRODUIT_SOUSPRODUITS) && $isProductCorrection && $object->hasFatherOrChild(1)) {
	print '<tr>';
	print '<td></td>';
	print '<td colspan="3">';
	print '<input type="checkbox" name="disablesubproductstockchange" id="disablesubproductstockchange" value="1"'.(GETPOST('disablesubproductstockchange') ? ' checked="checked"' : '').'>';
	print ' <label for="disablesubproductstockchange">'.$langs->trans('DisableStockChangeOfSubProduct').'</label>';
	print '</td>';
	print '</tr>';
}

if (isModEnabled('productbatch') && (($isProductCorrection && $object->hasbatch()) || $isMovementCorrection)) {
	print '<tr>';
	print '<td'.($isMovementCorrection ? '' : ' class="fieldrequired"').'>'.$langs->trans('batch_number').'</td><td colspan="3">';
	if ($pdluoid > 0) {
		print '<input type="text" name="batch_number_bis" size="40" disabled="disabled" value="'.$selectedBatch.'">';
		print '<input type="hidden" name="batch_number" id="batch_number" value="'.$selectedBatch.'">';
	} else {
		print img_picto('', 'barcode', 'class="pictofixedwidth"').'<input type="text" name="batch_number" id="batch_number" class="minwidth300" value="'.$selectedBatch.'">';
	}
	print '</td>';
	print '</tr>';

	print '<tr>';
	if (empty($conf->global->PRODUCT_DISABLE_SELLBY)) {
		print '<td'.$sellByCss.'>'.$langs->trans('SellByDate').'</td><td'.$sellByCss.'>';
		print $form->selectDate($selectedSellBy, 'sellby', '', '', 1, '', 1, 0, $disableSellBy);
		print '</td>';
	}
	if (empty($conf->global->PRODUCT_DISABLE_EATBY)) {
		print '<td'.$eatByCss.'>'.$langs->trans('EatByDate').'</td><td'.$eatByCss.'>';
		print $form->selectDate($selectedEatBy, 'eatby', '', '', 1, '', 1, 0, $disableEatBy);
		print '</td>';
	}
	print '</tr>';
}

print '<tr>';
print '<td>'.$langs->trans('UnitPurchaseValue').'</td>';
print '<td colspan="'.(isModEnabled('project') ? '1' : '3').'"><input name="unitprice" id="unitprice" size="10" value="'.GETPOST('unitprice').'"></td>';
if (isModEnabled('project')) {
	print '<td>'.$langs->trans('Project').'</td>';
	print '<td>';
	print img_picto('', 'project');
	print $formproject->select_projects(-1, '', 'projectid', 0, 0, 1, 0, 0, 0, 0, '', 0, 0, 'maxwidth300 widthcentpercentminusx');
	print '</td>';
}
print '</tr>';

$valformovementlabel = ((GETPOST('label') && (GETPOST('label') != $langs->trans('MovementCorrectStockInventairePlus', ''))) ? GETPOST('label') : $langs->trans('MovementCorrectStockInventairePlus', $productref));
print '<tr>';
print '<td>'.$langs->trans('MovementLabel').'</td>';
print '<td>';
print '<input type="text" name="label" class="minwidth400" value="'.dol_escape_htmltag($valformovementlabel).'">';
print '</td>';
print '<td>'.$langs->trans('InventoryCode').'</td>';
print '<td>';
print '<input class="maxwidth100onsmartphone" name="inventorycode" id="inventorycode" value="'.(GETPOSTISSET('inventorycode') ? GETPOST('inventorycode', 'alpha') : dol_print_date(dol_now(), '%Y%m%d%H%M%S')).'">';
print '</td>';
print '</tr>';

print '</table>';

print dol_get_fiche_end();

print '<div class="center">';
print '<input type="submit" class="button button-save" name="save" value="'.dol_escape_htmltag($langs->trans('Save')).'">';
print '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
print '<input type="submit" class="button button-cancel" name="cancel" value="'.dol_escape_htmltag($langs->trans('Cancel')).'">';
print '</div>';

print '</form>';


