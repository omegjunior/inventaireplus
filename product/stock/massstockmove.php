<?php
/* Copyright (C) 2013-2022 Laurent Destaileur	<ely@users.sourceforge.net>
 * Copyright (C) 2014	   Regis Houssin		<regis.houssin@inodbox.com>
 * Copyright (C) 2024-2025 MDW				<mdeweerd@users.noreply.github.com>
 * Copyright (C) 2024      Frédéric France		<frederic.france@free.fr>
 * Copyright (C) 2023	    Frédéric H Omega Junior <omegajunior.apps@gmail.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *  \file       htdocs/product/stock/massstockmove.php
 *  \ingroup    stock
 *  \brief      This page allows to select several products, then incoming warehouse and
 *  			outgoing warehouse and create all stock movements for this.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/stock/class/entrepot.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formother.class.php';
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.commande.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/html.formproduct.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/modules/import/import_csv.modules.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/import.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
require_once DOL_DOCUMENT_ROOT . '/reception/class/reception.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

$confirm = GETPOST('confirm', 'alpha');
$filetoimport = GETPOST('filetoimport');

// Load translation files required by the page
$langs->loadLangs(array('products', 'stocks', 'orders', 'productbatch', 'inventaireplus@inventaireplus'));

//init Hook
$hookmanager->initHooks(array('massstockmoveinventaireplus'));

// Security check
if ($user->socid) {
	$socid = $user->socid;
}
$result = restrictedArea($user, 'produit|service');

//checks if a product has been ordered

$action = GETPOST('action', 'aZ09');
$id_product = GETPOSTINT('productid');
$id_sw = GETPOSTINT('id_sw');
$id_tw = GETPOSTINT('id_tw');
$batch = GETPOST('batch');
$sellby = GETPOST('sellby');
$eatby = GETPOST('eatby');
$qty = GETPOST('qty');
$idline = GETPOST('idline');
$receptionid = GETPOSTINT('receptionid');
$isFromReceptionMode = (GETPOSTINT('from_reception') > 0 || ($action == 'fromreception' && $receptionid > 0));
$initFromReception = ($action == 'fromreception' && $receptionid > 0);
$receptionSourceWarehouseId = GETPOSTINT('sourcewarehouseid');
$receptionObject = null;

// Load variable for pagination
$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT("page");
if (empty($page) || $page < 0 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$page = 0;
} // If $page is not defined, or '' or -1 or if we click on clear filters
$offset = $limit * $page;
$pageprev = $page - 1;
$pagenext = $page + 1;
if (!$sortfield) {
	$sortfield = 'p.ref';
}
if (!$sortorder) {
	$sortorder = 'ASC';
}

if (GETPOST('init')) {
	unset($_SESSION['massstockmove']);
}
$listofdata = array();
if (!empty($_SESSION['massstockmove'])) {
	$listofdata = json_decode($_SESSION['massstockmove'], true);
	if (!is_array($listofdata)) {
		$listofdata = array();
	}
}

$error = 0;

$permissiontodelete = $user->hasRight('stock', 'mouvement', 'creer');

/**
 * Resolve transfer category snapshot for a product.
 *
 * Rule:
 * - first category by llx_categorie_product.rowid ASC
 * - fallback to "Non classé"
 *
 * @param	DoliDB	$db			Database handler
 * @param	int		$productId	Product id
 * @return array|false
 */
function inventaireplusGetTransferCategorySnapshot($db, $productId)
{
	global $conf;
	// changement de la règle de sélection de la catégorie du produit. Etant donné qu'on a pas de rowid sur la table categorie_product, 
	// on prend la première catégorie liée au produit par ordre de label de la table categorie. 
	$sql = "SELECT cp.fk_categorie, c.label";
	$sql .= " FROM ".MAIN_DB_PREFIX."categorie_product AS cp";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."categorie AS c ON c.rowid = cp.fk_categorie";
	$sql .= " WHERE cp.fk_product = ".((int) $productId);
	$sql .= " AND c.entity IN (".getEntity('category').")";
	$sql .= " ORDER BY c.label ASC";

	$resql = $db->query($sql);
	if (!$resql) {
		return false;
	}

	$obj = $db->fetch_object($resql);
	$db->free($resql);

	if ($obj) {
		return array(
			'id' => (int) $obj->fk_categorie,
			'label' => (string) $obj->label,
		);
	}

	return array(
		'id' => 0,
		'label' => 'Non classé',
	);
}

/**
 * Inject stock movement extrafields values into POST for Product::correct_stock*.
 *
 * @param	array	$values	Values without "options_" prefix
 * @return array
 */
function inventaireplusPushStockMovementExtraFieldsToPost(array $values)
{
	$backup = array();

	foreach ($values as $key => $value) {
		$postKey = 'options_'.$key;
		$backup[$postKey] = array_key_exists($postKey, $_POST) ? $_POST[$postKey] : null;
		$backup[$postKey.'_exists'] = array_key_exists($postKey, $_POST);
		$_POST[$postKey] = $value;
	}

	return $backup;
}

/**
 * Restore POST values after Product::correct_stock* extrafields injection.
 *
 * @param	array	$backup	Backup returned by inventaireplusPushStockMovementExtraFieldsToPost
 * @return void
 */
function inventaireplusPopStockMovementExtraFieldsFromPost(array $backup)
{
	foreach ($backup as $key => $value) {
		if (substr($key, -7) === '_exists') {
			continue;
		}

		if (!empty($backup[$key.'_exists'])) {
			$_POST[$key] = $value;
		} else {
			unset($_POST[$key]);
		}
	}
}

/**
 * Build preloaded lines for a reception transfer from stock movements.
 *
 * @param DoliDB $db Database handler
 * @param int    $receptionId Reception id
 * @return array|false
 */
function inventaireplusBuildMassStockMoveLinesFromReception($db, $receptionId)
{
	$receivedByKey = array();
	$transferredByKey = array();

	$sql = "SELECT sm.fk_product, sm.fk_entrepot AS id_sw, sm.batch, sm.eatby, sm.sellby, SUM(sm.value) AS qty_received";
	$sql .= " FROM ".MAIN_DB_PREFIX."stock_mouvement AS sm";
	$sql .= " WHERE sm.origintype = 'reception'";
	$sql .= " AND sm.fk_origin = ".((int) $receptionId);
	$sql .= " AND sm.value > 0";
	$sql .= " GROUP BY sm.fk_entrepot, sm.fk_product, sm.batch, sm.eatby, sm.sellby";
	$sql .= " ORDER BY MIN(sm.rowid) ASC";

	$resql = $db->query($sql);
	if (!$resql) {
		return false;
	}

	while ($obj = $db->fetch_object($resql)) {
		$key = ((int) $obj->id_sw).'|'.((int) $obj->fk_product).'|'.((string) $obj->batch).'|'.((string) $obj->eatby).'|'.((string) $obj->sellby);
		$receivedByKey[$key] = array(
			'id_sw' => (int) $obj->id_sw,
			'id_tw' => 0,
			'id_product' => (int) $obj->fk_product,
			'batch' => (string) $obj->batch,
			'eatby' => $obj->eatby,
			'sellby' => $obj->sellby,
			'qty_received' => price2num($obj->qty_received, 'MS'),
		);
	}
	$db->free($resql);

	$sql = "SELECT sm.fk_entrepot AS id_sw, sm.fk_product, sm.batch, sm.eatby, sm.sellby, SUM(-sm.value) AS qty_transferred_net";
	$sql .= " FROM ".MAIN_DB_PREFIX."stock_mouvement AS sm";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."stock_mouvement_extrafields AS ef ON ef.fk_object = sm.rowid";
	$sql .= " WHERE ef.transfer_origin_type = 'reception'";
	$sql .= " AND ef.transfer_origin_id = ".((int) $receptionId);
	$sql .= " AND sm.type_mouvement IN (0, 1)";
	$sql .= " GROUP BY sm.fk_entrepot, sm.fk_product, sm.batch, sm.eatby, sm.sellby";

	$resql = $db->query($sql);
	if (!$resql) {
		return false;
	}

	while ($obj = $db->fetch_object($resql)) {
		$key = ((int) $obj->id_sw).'|'.((int) $obj->fk_product).'|'.((string) $obj->batch).'|'.((string) $obj->eatby).'|'.((string) $obj->sellby);
		$transferredByKey[$key] = max(0, (float) price2num($obj->qty_transferred_net, 'MS'));
	}
	$db->free($resql);

	$listofdata = array();
	$lineId = 1;
	foreach ($receivedByKey as $key => $line) {
		$qtyTransferred = isset($transferredByKey[$key]) ? price2num($transferredByKey[$key], 'MS') : 0;
		$qtyRemaining = price2num($line['qty_received'] - $qtyTransferred, 'MS');
		$qtyCurrent = inventaireplusGetCurrentStockQtyForTransferLine($db, $line['id_sw'], $line['id_product'], $line['batch'], $line['eatby'], $line['sellby']);
		$qtyTransferable = price2num(min($qtyRemaining, $qtyCurrent), 'MS');
		if ($qtyTransferable <= 0) {
			continue;
		}

		$listofdata[$lineId] = array(
			'id' => $lineId,
			'id_product' => $line['id_product'],
			'qty' => $qtyTransferable,
			'id_sw' => $line['id_sw'],
			'id_tw' => 0,
			'batch' => $line['batch'],
		);
		$lineId++;
	}

	return $listofdata;
}

/**
 * Get current physical stock for one transfer line.
 *
 * @param DoliDB      $db           Database handler
 * @param int         $warehouseId  Source warehouse id
 * @param int         $productId    Product id
 * @param string      $batch        Batch/serial
 * @param string|null $eatby        Eatby date
 * @param string|null $sellby       Sellby date
 * @return float
 */
function inventaireplusGetCurrentStockQtyForTransferLine($db, $warehouseId, $productId, $batch = '', $eatby = null, $sellby = null)
{
	$warehouseId = (int) $warehouseId;
	$productId = (int) $productId;
	$batch = (string) $batch;

	if ($warehouseId <= 0 || $productId <= 0) {
		return 0;
	}

	if ($batch !== '') {
		$sql = "SELECT SUM(pb.qty) AS qty_current";
		$sql .= " FROM ".MAIN_DB_PREFIX."product_stock AS ps";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_batch AS pb ON pb.fk_product_stock = ps.rowid";
		//let join with product_lot to be able to get correct values of eatby and sellby columns, which are not inserted on product_batch table.
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_lot AS pl ON pl.batch = pb.batch AND pl.fk_product = ps.fk_product";
		$sql .= " WHERE ps.fk_entrepot = ".$warehouseId;
		$sql .= " AND ps.fk_product = ".$productId;
		$sql .= " AND pb.batch = '".$db->escape($batch)."'";
		if ($eatby === null || $eatby === '') {
			$sql .= " AND pl.eatby IS NULL";
		} else {
			$sql .= " AND pl.eatby = '".$db->idate(dol_stringtotime($eatby), 'gmt')."'";
		}
		if ($sellby === null || $sellby === '') {
			$sql .= " AND pl.sellby IS NULL";
		} else {
			$sql .= " AND pl.sellby = '".$db->idate(dol_stringtotime($sellby), 'gmt')."'";
		}
	} else {
		$sql = "SELECT ps.reel AS qty_current";
		$sql .= " FROM ".MAIN_DB_PREFIX."product_stock AS ps";
		$sql .= " WHERE ps.fk_entrepot = ".$warehouseId;
		$sql .= " AND ps.fk_product = ".$productId;
	}

	$resql = $db->query($sql);
	if (!$resql) {
		return 0;
	}

	$obj = $db->fetch_object($resql);
	$db->free($resql);

	if (!$obj || !isset($obj->qty_current)) {
		return 0;
	}

	return max(0, (float) price2num($obj->qty_current, 'MS'));
}

/**
 * Validate a selected batch against available stock in the source warehouse.
 *
 * @param DoliDB      $db          Database handler
 * @param int         $warehouseId Source warehouse id
 * @param int         $productId   Product id
 * @param string      $batch       Batch/serial
 * @param float       $qty         Requested quantity
 * @param string|null $eatby       Selected eatby date
 * @param string|null $sellby      Selected sellby date
 * @return array
 */
function inventaireplusValidateSelectedBatchForTransfer($db, $warehouseId, $productId, $batch, $qty, $eatby = null, $sellby = null)
{
	$warehouseId = (int) $warehouseId;
	$productId = (int) $productId;
	$batch = trim((string) $batch);
	$qty = (float) price2num($qty, 'MS');

	if ($warehouseId <= 0 || $productId <= 0 || $batch === '') {
		return array('ok' => false, 'qty_available' => 0, 'eatby' => null, 'sellby' => null, 'error_key' => 'missing');
	}

	$sql = "SELECT pb.batch, pl.eatby, pl.sellby, SUM(pb.qty) AS qty_available";
	$sql .= " FROM ".MAIN_DB_PREFIX."product_stock AS ps";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_batch AS pb ON pb.fk_product_stock = ps.rowid";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_lot AS pl ON pl.batch = pb.batch AND pl.fk_product = ps.fk_product";
	$sql .= " WHERE ps.fk_entrepot = ".$warehouseId;
	$sql .= " AND ps.fk_product = ".$productId;
	$sql .= " AND pb.batch = '".$db->escape($batch)."'";
	$sql .= " GROUP BY pb.batch, pl.eatby, pl.sellby";

	$resql = $db->query($sql);
	if (!$resql) {
		return array('ok' => false, 'qty_available' => 0, 'eatby' => null, 'sellby' => null, 'error_key' => 'sql');
	}

	$obj = $db->fetch_object($resql);
	$db->free($resql);

	if (!$obj || price2num($obj->qty_available, 'MS') <= 0) {
		return array('ok' => false, 'qty_available' => 0, 'eatby' => null, 'sellby' => null, 'error_key' => 'not_available');
	}

	$resolvedEatby = (!empty($obj->eatby) ? $obj->eatby : null);
	$resolvedSellby = (!empty($obj->sellby) ? $obj->sellby : null);

	if (($eatby !== null && $eatby !== '' && $resolvedEatby !== $eatby) || ($sellby !== null && $sellby !== '' && $resolvedSellby !== $sellby)) {
		return array(
			'ok' => false,
			'qty_available' => (float) price2num($obj->qty_available, 'MS'),
			'eatby' => $resolvedEatby,
			'sellby' => $resolvedSellby,
			'error_key' => 'date_mismatch',
		);
	}

	$qtyAvailable = (float) price2num($obj->qty_available, 'MS');
	if ($qty > $qtyAvailable) {
		return array(
			'ok' => false,
			'qty_available' => $qtyAvailable,
			'eatby' => $resolvedEatby,
			'sellby' => $resolvedSellby,
			'error_key' => 'qty',
		);
	}

	return array(
		'ok' => true,
		'qty_available' => $qtyAvailable,
		'eatby' => $resolvedEatby,
		'sellby' => $resolvedSellby,
		'error_key' => null,
	);
}

/**
 * Normalize a batch date value for Product::correct_stock_batch().
 *
 * The core expects a timestamp, while our flow may carry SQL date strings.
 *
 * @param DoliDB      $db        Database handler
 * @param int|string  $dateValue Raw date value
 * @return string|int
 */
function inventaireplusNormalizeBatchDateForStockMovement($db, $dateValue)
{
	if ($dateValue === null || $dateValue === '' || $dateValue === -1 || $dateValue === '-1') {
		return '';
	}

	if (is_numeric($dateValue)) {
		return (int) $dateValue;
	}

	$timestamp = $db->jdate($dateValue);
	if ($timestamp > 0) {
		return $timestamp;
	}

	$timestamp = dol_stringtotime((string) $dateValue);
	if ($timestamp > 0) {
		return $timestamp;
	}

	return '';
}

/**
 * Build a human-readable expiry label for a batch.
 *
 * @param DoliDB      $db     Database handler
 * @param string|null $sellby Sellby date
 * @param string|null $eatby  Eatby date
 * @return string
 */
function inventaireplusGetBatchExpiryLabel($db, $sellby = null, $eatby = null)
{
	global $langs;

	if (!empty($sellby)) {
		$sellbyTimestamp = $db->jdate($sellby);
		if ($sellbyTimestamp > 0) {
			return $langs->trans("SellByDate").': '.dol_print_date($sellbyTimestamp, 'day', false, $langs, true);
		}
	}

	if (!empty($eatby)) {
		$eatbyTimestamp = $db->jdate($eatby);
		if ($eatbyTimestamp > 0) {
			return $langs->trans("EatByDate").': '.dol_print_date($eatbyTimestamp, 'day', false, $langs, true);
		}
	}

	return '';
}

/**
 * Push a user-facing error message for a batch validation result.
 *
 * @param array  $batchValidation Validation result
 * @param string $batch           Batch/serial
 * @return void
 */
function inventaireplusSetBatchValidationErrorMessage(array $batchValidation, $batch)
{
	global $langs;

	if ($batchValidation['error_key'] === 'qty') {
		setEventMessages($langs->trans("InventairePlusInsufficientBatchStock", $batch, price2num($batchValidation['qty_available'], 'MS')), null, 'errors');
	} elseif ($batchValidation['error_key'] === 'date_mismatch') {
		setEventMessages($langs->trans("InventairePlusBatchDateMismatch", $batch), null, 'errors');
	} else {
		setEventMessages($langs->trans("InventairePlusBatchNotAvailableInWarehouse", $batch), null, 'errors');
	}
}


/*
 * Actions
 */

if ($initFromReception && $user->hasRight('inventaireplus', 'transferreceptiontowarehouseinventaireplus', 'write')) {
	$receptionObject = new Reception($db);
	$result = $receptionObject->fetch($receptionid);
	if ($result <= 0) {
		$isFromReceptionMode = false;
		setEventMessages($langs->trans("ErrorRecordNotFound"), null, 'errors');
	} else {
		$receptionStatus = isset($receptionObject->statut) ? (int) $receptionObject->statut : (isset($receptionObject->status) ? (int) $receptionObject->status : -1);
		if ($receptionStatus <= 0) {
			$isFromReceptionMode = false;
			setEventMessages($langs->trans("ReceptionMustBeValidated"), null, 'errors');
		} else {
			$receptionLines = inventaireplusBuildMassStockMoveLinesFromReception($db, $receptionid);
			if ($receptionLines === false) {
				$isFromReceptionMode = false;
				setEventMessages($db->lasterror(), null, 'errors');
			} else {
				$listofdata = $receptionLines;
				if (count($listofdata) > 0) {
					$firstLine = reset($listofdata);
					$receptionSourceWarehouseId = !empty($firstLine['id_sw']) ? (int) $firstLine['id_sw'] : 0;
					$_SESSION['massstockmove'] = json_encode($listofdata);
					setEventMessages($langs->trans("ReceptionTransferLinesLoaded", $receptionObject->ref), null, 'mesgs');
				} else {
					unset($_SESSION['massstockmove']);
					setEventMessages($langs->trans("NoTransferableLineForReception"), null, 'warnings');
				}
			}
		}
	}

	$action = '';
}

if ($action == 'addline' && $user->hasRight('stock', 'mouvement', 'creer')) {
	if ($isFromReceptionMode) {
		setEventMessages($langs->trans("ReceptionManualLineDisabled"), null, 'warnings');
		$action = '';
	}
}

if ($action == 'addline' && $user->hasRight('stock', 'mouvement', 'creer')) {
	if (!($id_sw > 0)) {
		//$error++;
		//setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("WarehouseSource")), null, 'errors');
		if ($id_sw < 0) {
			$id_sw = 0;
		}
	}
	if (!($id_tw > 0)) {
		$error++;
		setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("WarehouseTarget")), null, 'errors');
	}
	if ($id_sw > 0 && $id_tw == $id_sw) {
		$error++;
		$langs->load("errors");
		setEventMessages($langs->trans("ErrorWarehouseMustDiffers"), null, 'errors');
	}
	if (!($id_product > 0)) {
		$error++;
		setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("Product")), null, 'errors');
	}
	if (!$qty) {
		$error++;
		setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("Qty")), null, 'errors');
	}

	// Check a batch number is provided if product need it
	if (!$error) {
		$producttmp = new Product($db);
		$producttmp->fetch($id_product);
		if ($producttmp->hasbatch()) {
			if (empty($batch)) {
				$error++;
				$langs->load("errors");
				setEventMessages($langs->trans("ErrorTryToMakeMoveOnProductRequiringBatchData", $producttmp->ref), null, 'errors');
			} elseif ($id_sw > 0) {
				$batchValidation = inventaireplusValidateSelectedBatchForTransfer($db, $id_sw, $id_product, $batch, $qty, $eatby, $sellby);
				if (empty($batchValidation['ok'])) {
					$error++;
					inventaireplusSetBatchValidationErrorMessage($batchValidation, $batch);
				} else {
					$eatby = $batchValidation['eatby'];
					$sellby = $batchValidation['sellby'];
				}
			}
		}
	}

	// TODO Check qty is ok for stock move. Note qty may not be enough yet, but we make a check now to report a warning.
	// What is more important is to have qty when doing action 'createmovements'
	if (!$error) {
		// Warning, don't forget lines already added into the $_SESSION['massstockmove']
		if ($producttmp->hasbatch()) {
		} else {
		}
	}

	//var_dump($_SESSION['massstockmove']);exit;
	if (!$error) {
		if (count(array_keys($listofdata)) > 0) {
			$id = max(array_keys($listofdata)) + 1;
		} else {
			$id = 1;
		}
		$listofdata[$id] = array(
			'id' => $id,
			'id_product' => $id_product,
			'qty' => $qty,
			'id_sw' => $id_sw,
			'id_tw' => $id_tw,
			'batch' => $batch,
			'sellby' => $sellby,
			'eatby' => $eatby,
		);
		$_SESSION['massstockmove'] = json_encode($listofdata);

		//unset($id_sw);
		//unset($id_tw);
		unset($id_product);
		unset($batch);
		unset($qty);
	}
}

if ($action == 'delline' && $idline != '' && $user->hasRight('stock', 'mouvement', 'creer')) {
	if (!empty($listofdata[$idline])) {
		unset($listofdata[$idline]);
	}
	if (count($listofdata) > 0) {
		$_SESSION['massstockmove'] = json_encode($listofdata);
	} else {
		unset($_SESSION['massstockmove']);
	}
}

if ($action == 'createmovements' && $user->hasRight('stock', 'mouvement', 'creer')) {
	$error = 0;
	$commonTargetWarehouseId = 0;

	if (!GETPOST("label")) {
		$error++;
		setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("MovementLabel")), null, 'errors');
	}
	if ($isFromReceptionMode) {
		$commonTargetWarehouseId = GETPOSTINT('targetwarehouseid');
		if ($commonTargetWarehouseId <= 0) {
			$error++;
			setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("WarehouseTarget")), null, 'errors');
		}
	}

	$db->begin();

	if (!$error) {
		$product = new Product($db);
		$extrafields = new ExtraFields($db);
		$extrafields->fetch_name_optionals_label('stock_mouvement');
		$categorySnapshotCache = array();
		$categoryRanks = array();
		$nextCategoryRank = 1;

		foreach ($listofdata as $key => $val) {	// Loop on each movement to do
			$id = $val['id'];
			$id_product = $val['id_product'];
			$id_sw = $val['id_sw'];
			$id_tw = $isFromReceptionMode && $commonTargetWarehouseId > 0 ? $commonTargetWarehouseId : $val['id_tw'];
			$qty = price2num($val['qty']);
			$batch = $val['batch'];
			$dlc = (!empty($val['eatby']) ? $val['eatby'] : -1);
			$dluo = (!empty($val['sellby']) ? $val['sellby'] : -1);

			if (!$error && $id_sw != $id_tw && is_numeric($qty) && $id_product) {
				$result = $product->fetch($id_product);
				if ($result <= 0) {
					$error++;
					setEventMessages($langs->trans("ErrorRecordNotFound"), null, 'errors');
					continue;
				}

				if (!isset($categorySnapshotCache[$id_product])) {
					$result = inventaireplusGetTransferCategorySnapshot($db, $id_product);

					if ($result === false) {
						$error++;
						setEventMessages($db->lasterror(), null, 'errors');
						continue;
					}

					$categorySnapshotCache[$id_product] = $result;
				}

				$categorySnapshot = $categorySnapshotCache[$id_product];
				$categoryKey = $categorySnapshot['id'].'|'.$categorySnapshot['label'];
				if (!isset($categoryRanks[$categoryKey])) {
					$categoryRanks[$categoryKey] = $nextCategoryRank;
					$nextCategoryRank++;
				}

				$stockMovementExtraValues = array(
					'transfer_source' => (int) $id_sw,
					'transfer_target' => (int) $id_tw,
					'transfer_category_id' => (int) $categorySnapshot['id'],
					'transfer_category_label' => $categorySnapshot['label'],
					'transfer_category_rank' => (int) $categoryRanks[$categoryKey],
				);
				if ($isFromReceptionMode && $receptionid > 0) {
					$stockMovementExtraValues['transfer_origin_type'] = 'reception';
					$stockMovementExtraValues['transfer_origin_id'] = (int) $receptionid;
				}

				$product->load_stock('novirtual'); // Load array product->stock_warehouse

				// Define value of products moved
				$pricesrc = 0;
				if (!empty($product->pmp)) {
					$pricesrc = (float) $product->pmp;
				}
				$pricedest = $pricesrc;

				//print 'price src='.$pricesrc.', price dest='.$pricedest;exit;

				if (empty($conf->productbatch->enabled) || !$product->hasbatch()) {	// If product does not need lot/serial
					// Remove stock if source warehouse defined
					if ($id_sw > 0) {
						$stockMovementExtraPostBackup = inventaireplusPushStockMovementExtraFieldsToPost($stockMovementExtraValues);
						$result1 = $product->correct_stock(
							$user,
							$id_sw,
							(float) $qty,
							1,
							GETPOST("label"),
							$pricesrc,
							GETPOST("codemove"),
							'',
							null,
							0,
							$extrafields
						);
						inventaireplusPopStockMovementExtraFieldsFromPost($stockMovementExtraPostBackup);
						if ($result1 < 0) {
							$error++;
							setEventMessages($product->error, $product->errors, 'errors');
						}
					}

					// Add stock
					$stockMovementExtraPostBackup = inventaireplusPushStockMovementExtraFieldsToPost($stockMovementExtraValues);
					$result2 = $product->correct_stock(
						$user,
						$id_tw,
						(float) $qty,
						0,
						GETPOST("label"),
						$pricedest,
						GETPOST("codemove"),
						'',
						null,
						0,
						$extrafields
					);
					inventaireplusPopStockMovementExtraFieldsFromPost($stockMovementExtraPostBackup);
					if ($result2 < 0) {
						$error++;
						setEventMessages($product->error, $product->errors, 'errors');
					}
				} else {
					if ($id_sw > 0) {
						$batchValidation = inventaireplusValidateSelectedBatchForTransfer($db, $id_sw, $id_product, $batch, $qty, ($dlc === -1 ? null : $dlc), ($dluo === -1 ? null : $dluo));
						if (empty($batchValidation['ok'])) {
							$error++;
							inventaireplusSetBatchValidationErrorMessage($batchValidation, $batch);
							continue;
						}

						$dlc = $batchValidation['eatby'];
						$dluo = $batchValidation['sellby'];
					} else {
						$arraybatchinfo = array();
						if (($dlc === -1 || $dlc === '') && ($dluo === -1 || $dluo === '')) {
							$arraybatchinfo = $product->loadBatchInfo($batch);
						}
						if (count($arraybatchinfo) > 0) {
							$firstrecord = array_shift($arraybatchinfo);
							$dlc = $firstrecord['eatby'];
							$dluo = $firstrecord['sellby'];
						} else {
							$dlc = '';
							$dluo = '';
						}
					}

					$dlc = inventaireplusNormalizeBatchDateForStockMovement($db, $dlc);
					$dluo = inventaireplusNormalizeBatchDateForStockMovement($db, $dluo);

					// Remove stock
					if ($id_sw > 0) {
						$stockMovementExtraPostBackup = inventaireplusPushStockMovementExtraFieldsToPost($stockMovementExtraValues);
						$result1 = $product->correct_stock_batch(
							$user,
							$id_sw,
							(float) $qty,
							1,
							GETPOST("label"),
							$pricesrc,
							$dlc,
							$dluo,
							$batch,
							GETPOST("codemove"),
							'',
							null,
							0,
							$extrafields
						);
						inventaireplusPopStockMovementExtraFieldsFromPost($stockMovementExtraPostBackup);
						if ($result1 < 0) {
							$error++;
							setEventMessages($product->error, $product->errors, 'errors');
						}
					}

					// Add stock
					$stockMovementExtraPostBackup = inventaireplusPushStockMovementExtraFieldsToPost($stockMovementExtraValues);
					$result2 = $product->correct_stock_batch(
						$user,
						$id_tw,
						(float) $qty,
						0,
						GETPOST("label"),
						$pricedest,
						$dlc,
						$dluo,
						$batch,
						GETPOST("codemove"),
						'',
						null,
						0,
						$extrafields
					);
					inventaireplusPopStockMovementExtraFieldsFromPost($stockMovementExtraPostBackup);
					if ($result2 < 0) {
						$error++;
						setEventMessages($product->error, $product->errors, 'errors');
					}
				}
			} else {
				// dol_print_error(null,"Bad value saved into sessions");
				$error++;
			}
		}
	}
	//var_dump($_SESSION['massstockmove']);exit;

	if (!$error) {
		unset($_SESSION['massstockmove']);

		$db->commit();
		setEventMessages($langs->trans("StockMovementRecorded"), null, 'mesgs');
		header("Location: " . DOL_URL_ROOT . '/custom/inventaireplus/product/stock/list.php'); // Redirect to avoid pb when using back
		exit;
	} else {
		$db->rollback();
		setEventMessages($langs->trans("Error"), null, 'errors');
	}
}

if ($action == 'importCSV' && $user->hasRight('stock', 'mouvement', 'creer')) {
	if ($isFromReceptionMode) {
		setEventMessages($langs->trans("ReceptionManualLineDisabled"), null, 'warnings');
		$action = '';
	}
}

if ($action == 'importCSV' && $user->hasRight('stock', 'mouvement', 'creer')) {
	dol_mkdir($conf->stock->dir_temp);
	$nowyearmonth = dol_print_date(dol_now(), '%Y%m%d%H%M%S');

	$fullpath = $conf->stock->dir_temp . "/" . $user->id . '-csvfiletotimport.csv';
	$resultupload = dol_move_uploaded_file($_FILES['userfile']['tmp_name'], $fullpath, 1);
	if (is_numeric($resultupload) && $resultupload > 0) {
		dol_syslog("File " . $fullpath . " was added for import");
	} else {
		$error++;
		$langs->load("errors");
		if ($resultupload === 'ErrorDirNotWritable') {
			setEventMessages($langs->trans("ErrorFailedToSaveFile") . ' - ' . $langs->trans($resultupload, $fullpath), null, 'errors');
		} else {
			setEventMessages($langs->trans("ErrorFailedToSaveFile"), null, 'errors');
		}
	}

	if (!$error) {
		$importcsv = new ImportCsv($db, 'massstocklist');
		//print $importcsv->separator;

		$nblinesrecord = $importcsv->import_get_nb_of_lines($fullpath) - 1;
		$importcsv->import_open_file($fullpath);
		$labelsrecord = $importcsv->import_read_record();

		if ($nblinesrecord < 1) {
			setEventMessages($langs->trans("BadNumberOfLinesMustHaveAtLeastOneLinePlusTitle"), null, 'errors');
		} else {
			$i = 0;
			$data = array();
			$productstatic = new Product($db);
			$warehousestatics = new Entrepot($db);
			$warehousestatict = new Entrepot($db);
			// Loop on each line in CSV file
			while (($i < $nblinesrecord) && !$error) {
				$newrecord = $importcsv->import_read_record();

				$data[$i] = $newrecord;
				if (count($data[$i]) == 1) {
					// Only 1 empty line
					unset($data[$i]);
					$i++;
					continue;
				}
				$tmp_id_sw = $data[$i][0]['val'];
				$tmp_id_tw = $data[$i][1]['val'];
				$tmp_id_product = $data[$i][2]['val'];
				$tmp_qty = $data[$i][3]['val'];
				$tmp_batch = $data[$i][4]['val'];

				$errorforproduct = 0;
				$isidorref = 'ref';
				if (!is_numeric($tmp_id_product) && $tmp_id_product != '' && preg_match('/^id:/i', $tmp_id_product)) {
					$isidorref = 'id';
				}
				$tmp_id_product = preg_replace('/^(id|ref):/i', '', $tmp_id_product);

				if ($isidorref === 'ref') {
					$tmp_id_product = preg_replace('/^ref:/', '', $tmp_id_product);
					$result = fetchref($productstatic, $tmp_id_product);
					if ($result === -2) {
						$error++;
						$errorforproduct = 1;
						$langs->load("errors");
						setEventMessages($langs->trans("ErrorMultipleRecordFoundFromRef", $tmp_id_product), null, 'errors');
					} elseif ($result <= 0) {
						$error++;
						$errorforproduct = 1;
						$langs->load("errors");
						setEventMessages($langs->trans("ErrorRefNotFound", $tmp_id_product), null, 'errors');
					}
					$tmp_id_product = $result;
				}
				$data[$i][2]['val'] = $tmp_id_product;
				if (!$errorforproduct && !($tmp_id_product > 0)) {
					$error++;
					setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("Product")), null, 'errors');
				}

				if ($tmp_id_sw !== '') {
					// For source, we allow empty value
					$errorforwarehouses = 0;
					$isidorref = 'ref';
					if (!is_numeric($tmp_id_sw) && $tmp_id_sw != '' && preg_match('/^id:/i', $tmp_id_sw)) {
						$isidorref = 'id';
					}
					$tmp_id_sw = preg_replace('/^(id|ref):/i', '', $tmp_id_sw);
					if ($isidorref === 'ref') {
						$tmp_id_sw = preg_replace('/^ref:/', '', $tmp_id_sw);
						$result = fetchref($warehousestatics, $tmp_id_sw);
						if ($result === -2) {
							$error++;
							$errorforwarehouses = 1;
							$langs->load("errors");
							setEventMessages($langs->trans("ErrorMultipleRecordFoundFromRef", $tmp_id_sw), null, 'errors');
						} elseif ($result <= 0) {
							$error++;
							$errorforwarehouses = 1;
							$langs->load("errors");
							setEventMessages($langs->trans("ErrorRefNotFound", $tmp_id_sw), null, 'errors');
						}
						$tmp_id_sw = $result;
					}
					$data[$i][0]['val'] = $tmp_id_sw;
					if (!$errorforwarehouses && !($tmp_id_sw > 0)) {
						$error++;
						setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("WarehouseSource")), null, 'errors');
					}
				}

				$errorforwarehouset = 0;
				$isidorref = 'ref';
				if (!is_numeric($tmp_id_tw) && $tmp_id_tw != '' && preg_match('/^id:/i', $tmp_id_tw)) {
					$isidorref = 'id';
				}
				$tmp_id_tw = preg_replace('/^(id|ref):/i', '', $tmp_id_tw);
				if ($isidorref === 'ref') {
					$tmp_id_tw = preg_replace('/^ref:/', '', $tmp_id_tw);
					$result = fetchref($warehousestatict, $tmp_id_tw);
					if ($result === -2) {
						$error++;
						$errorforwarehouset = 1;
						$langs->load("errors");
						setEventMessages($langs->trans("ErrorMultipleRecordFoundFromRef", $tmp_id_tw), null, 'errors');
					} elseif ($result <= 0) {
						$error++;
						$errorforwarehouset = 1;
						$langs->load("errors");
						setEventMessages($langs->trans("ErrorRefNotFound", $tmp_id_tw), null, 'errors');
					}
					$tmp_id_tw = $result;
				}
				$data[$i][1]['val'] = $tmp_id_tw;
				if (!$errorforwarehouset && !($tmp_id_tw > 0)) {
					$error++;
					setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("WarehouseTarget")), null, 'errors');
				}

				if ($tmp_id_sw > 0 && $tmp_id_tw == $tmp_id_sw) {
					$error++;
					$langs->load("errors");
					setEventMessages($langs->trans("ErrorWarehouseMustDiffers"), null, 'errors');
				}
				if (!$tmp_qty) {
					$error++;
					setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("Qty")), null, 'errors');
				}

				// Check a batch number is provided if product need it
				if (!$error) {
					$producttmp = new Product($db);
					$producttmp->fetch($tmp_id_product);
					if ($producttmp->hasbatch()) {
						if (empty($tmp_batch)) {
							$error++;
							$langs->load("errors");
							setEventMessages($langs->trans("ErrorTryToMakeMoveOnProductRequiringBatchData", $producttmp->ref), null, 'errors');
						} elseif ($tmp_id_sw > 0) {
							$batchValidation = inventaireplusValidateSelectedBatchForTransfer($db, $tmp_id_sw, $tmp_id_product, $tmp_batch, $tmp_qty);
							if (empty($batchValidation['ok'])) {
								$error++;
								inventaireplusSetBatchValidationErrorMessage($batchValidation, $tmp_batch);
							} else {
								$data[$i][5]['val'] = $batchValidation['sellby'];
								$data[$i][6]['val'] = $batchValidation['eatby'];
							}
						}
					}
				}

				$i++;
			}

			if (!$error) {
				foreach ($data as $key => $value) {
					if (count(array_keys($listofdata)) > 0) {
						$id = max(array_keys($listofdata)) + 1;
					} else {
						$id = 1;
					}
					$tmp_id_sw = $data[$key][0]['val'];
					$tmp_id_tw = $data[$key][1]['val'];
					$tmp_id_product = $data[$key][2]['val'];
					$tmp_qty = $data[$key][3]['val'];
					$tmp_batch = $data[$key][4]['val'];
					$tmp_sellby = isset($data[$key][5]['val']) ? $data[$key][5]['val'] : null;
					$tmp_eatby = isset($data[$key][6]['val']) ? $data[$key][6]['val'] : null;
					$listofdata[$id] = array(
						'id' => $id,
						'id_sw' => $tmp_id_sw,
						'id_tw' => $tmp_id_tw,
						'id_product' => $tmp_id_product,
						'qty' => $tmp_qty,
						'batch' => $tmp_batch,
						'sellby' => $tmp_sellby,
						'eatby' => $tmp_eatby,
					);
				}
			}
		}
	}

	if ($error) {
		$listofdata = array();
	}

	$_SESSION['massstockmove'] = json_encode($listofdata);
}

if ($action == 'confirm_deletefile' && $confirm == 'yes' && $permissiontodelete) {
	$langs->load("other");

	$file = $conf->stock->dir_temp . '/' . GETPOST('urlfile');
	$ret = dol_delete_file($file);
	if ($ret) {
		setEventMessages($langs->trans("FileWasRemoved", GETPOST('urlfile')), null, 'mesgs');
	} else {
		setEventMessages($langs->trans("ErrorFailToDeleteFile", GETPOST('urlfile')), null, 'errors');
	}
	header('Location: ' . $_SERVER["PHP_SELF"]);
	exit;
}


/*
 * View
 */

$now = dol_now();
$error = 0;

$form = new Form($db);
$formproduct = new FormProduct($db);
$productstatic = new Product($db);
$warehousestatics = new Entrepot($db);
$warehousestatict = new Entrepot($db);

if ($isFromReceptionMode && $receptionid > 0 && !($receptionObject instanceof Reception)) {
	$receptionObject = new Reception($db);
	if ($receptionObject->fetch($receptionid) <= 0) {
		$receptionObject = null;
	}
}
if ($isFromReceptionMode && empty($receptionSourceWarehouseId) && !empty($listofdata)) {
	$firstLine = reset($listofdata);
	$receptionSourceWarehouseId = !empty($firstLine['id_sw']) ? (int) $firstLine['id_sw'] : 0;
}

$help_url = 'EN:Module_Stocks_En|FR:Module_Stock|ES:Módulo_Stocks|DE:Modul_Bestände';

$title = $langs->trans('MassMovementInventairePlus');

llxHeader('', $title, $help_url);

print load_fiche_titre($langs->trans("MassStockTransferShortInventairePlus"), '', 'stock');

$titletoadd = $langs->trans("Select");
$buttonrecord = $langs->trans("RecordMovements");
$titletoaddnoent = $langs->transnoentitiesnoconv("Select");
$buttonrecordnoent = $langs->transnoentitiesnoconv("RecordMovements");
$selectedBatchExpiryLabel = inventaireplusGetBatchExpiryLabel($db, $sellby, $eatby);
$qtyInputValue = (isset($qty) && $qty !== '' ? price2num((float) $qty, 'MS') : '');
print '<span class="opacitymedium">' . $langs->trans("SelectProductInAndOutWareHouse", $titletoaddnoent, $buttonrecordnoent) . '</span>';

print '<br>';
//print '<br>';

if (!$isFromReceptionMode) {
	// Form to upload a file
	print '<form name="userfile" action="' . $_SERVER["PHP_SELF"] . '" enctype="multipart/form-data" method="POST">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="importCSV">';
	if (!empty($conf->dol_optimize_smallscreen)) {
		print '<br>';
	}
	print '<span class="opacitymedium">';
	print $langs->trans("or");
	print ' ';
	$importcsv = new ImportCsv($db, 'massstocklist');
	print $form->textwithpicto($langs->trans('SelectAStockMovementFileToImport'), $langs->transnoentitiesnoconv("InfoTemplateImport", $importcsv->separator));
	print '</span>';

	$maxfilesizearray = getMaxFileSizeArray();
	$maxmin = $maxfilesizearray['maxmin'];
	if ($maxmin > 0) {
		print '<input type="hidden" name="MAX_FILE_SIZE" value="' . ($maxmin * 1024) . '">';	// MAX_FILE_SIZE must precede the field type=file
	}
	print '<input type="file" name="userfile" size="20" maxlength="80"> &nbsp; &nbsp; ';
	$out = (!getDolGlobalString('MAIN_UPLOAD_DOC') ? ' disabled' : '');
	print '<input type="submit" class="button small smallpaddingimp" value="' . $langs->trans("ImportFromCSV") . '"' . $out . ' name="sendit">';
	$out = '';
	if (getDolGlobalString('MAIN_UPLOAD_DOC')) {
		$max = getDolGlobalString('MAIN_UPLOAD_DOC'); // In Kb
		$maxphp = @ini_get('upload_max_filesize'); // In unknown
		if (preg_match('/k$/i', $maxphp)) {
			$maxphp = preg_replace('/k$/i', '', $maxphp);
			$maxphp = (int) $maxphp * 1;
		}
		if (preg_match('/m$/i', $maxphp)) {
			$maxphp = preg_replace('/m$/i', '', $maxphp);
			$maxphp = (int) $maxphp * 1024;
		}
		if (preg_match('/g$/i', $maxphp)) {
			$maxphp = preg_replace('/g$/i', '', $maxphp);
			$maxphp = (int) $maxphp * 1024 * 1024;
		}
		if (preg_match('/t$/i', $maxphp)) {
			$maxphp = preg_replace('/t$/i', '', $maxphp);
			$maxphp = (int) $maxphp * 1024 * 1024 * 1024;
		}
		$maxphp2 = @ini_get('post_max_size'); // In unknown
		if (preg_match('/k$/i', $maxphp2)) {
			$maxphp2 = preg_replace('/k$/i', '', $maxphp2);
			$maxphp2 = (int) $maxphp2 * 1;
		}
		if (preg_match('/m$/i', $maxphp2)) {
			$maxphp2 = preg_replace('/m$/i', '', $maxphp2);
			$maxphp2 = (int) $maxphp2 * 1024;
		}
		if (preg_match('/g$/i', $maxphp2)) {
			$maxphp2 = preg_replace('/g$/i', '', $maxphp2);
			$maxphp2 = (int) $maxphp2 * 1024 * 1024;
		}
		if (preg_match('/t$/i', $maxphp2)) {
			$maxphp2 = preg_replace('/t$/i', '', $maxphp2);
			$maxphp2 = (int) $maxphp2 * 1024 * 1024 * 1024;
		}
		// Now $max and $maxphp and $maxphp2 are in Kb
		$maxmin = $max;
		$maxphptoshow = $maxphptoshowparam = '';
		if ($maxphp > 0) {
			$maxmin = min($max, $maxphp);
			$maxphptoshow = $maxphp;
			$maxphptoshowparam = 'upload_max_filesize';
		}
		if ($maxphp2 > 0) {
			$maxmin = min($max, $maxphp2);
			if ($maxphp2 < $maxphp) {
				$maxphptoshow = $maxphp2;
				$maxphptoshowparam = 'post_max_size';
			}
		}

		$langs->load('other');
		$out .= ' ';
		$out .= info_admin($langs->trans("ThisLimitIsDefinedInSetup", $max, $maxphptoshow), 1);
	} else {
		$out .= ' (' . $langs->trans("UploadDisabled") . ')';
	}
	print $out;

	print '</form>';

	print '<br><br>';
}

// Form to add a line
print '<form action="' . $_SERVER["PHP_SELF"] . '" method="POST" name="formulaire">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="addline">';
if ($isFromReceptionMode && $receptionid > 0) {
	print '<input type="hidden" name="from_reception" value="1">';
	print '<input type="hidden" name="receptionid" value="' . ((int) $receptionid) . '">';
	print '<input type="hidden" name="sourcewarehouseid" value="' . ((int) $receptionSourceWarehouseId) . '">';
}

if ($isFromReceptionMode && $receptionObject instanceof Reception) {
	print '<div class="info">';
	print $langs->trans("TransferFromReception") . ': <strong>' . dol_escape_htmltag($receptionObject->ref) . '</strong>';
	if ($receptionSourceWarehouseId > 0) {
		$warehousestatics->fetch($receptionSourceWarehouseId);
		if ($warehousestatics->id > 0) {
			print ' - ' . $langs->trans("ReceptionSource") . ': <strong>' . dol_escape_htmltag($warehousestatics->label) . '</strong>';
		}
	}
	print '</div><br>';
}


print '<div class="div-table-responsive-no-min">';
print '<table class="liste noborder centpercent">';

$param = '';

print '<tr class="liste_titre">';
print getTitleFieldOfList($langs->trans('WarehouseSource'), 0, $_SERVER["PHP_SELF"], '', $param, '', '', $sortfield, $sortorder, 'tagtd maxwidthonsmartphone ');
print getTitleFieldOfList($langs->trans('WarehouseTarget'), 0, $_SERVER["PHP_SELF"], '', $param, '', '', $sortfield, $sortorder, 'tagtd maxwidthonsmartphone ');
print getTitleFieldOfList($langs->trans('Product'), 0, $_SERVER["PHP_SELF"], '', $param, '', '', $sortfield, $sortorder, 'tagtd maxwidthonsmartphone ');
if (isModEnabled('productbatch')) {
	print getTitleFieldOfList($langs->trans('Batch'), 0, $_SERVER["PHP_SELF"], '', $param, '', '', $sortfield, $sortorder, 'tagtd maxwidthonsmartphone ');
}
print getTitleFieldOfList($langs->trans('Qty'), 0, $_SERVER["PHP_SELF"], '', $param, '', '', $sortfield, $sortorder, 'right tagtd maxwidthonsmartphone ');
print getTitleFieldOfList('', 0);
print '</tr>';

if (!$isFromReceptionMode) {
	print '<tr class="oddeven">';
	// From warehouse
	$id_sw = getDolGlobalInt('INVENTAIREPLUS_MAIN_WAREHOUSE_ID', 0);
	$sql = "SELECT e.rowid identrepot FROM " . MAIN_DB_PREFIX . "entrepot e where e.rowid != " . $id_sw . " AND e.entity IN (" . (int) $conf->entity . ")";
	$resql = $db->query($sql);
	if ($resql) {
		$num = $db->num_rows($resql);
		$i = 0;
		$listexclude = array();
		while ($i < $num) {
			$obj = $db->fetch_object($resql);
			$listexclude[] = $obj->identrepot;
			$i++;
		}
	}

	print '<td class="nowraponall">';
	print img_picto($langs->trans("WarehouseSource"), 'stock', 'class="paddingright"') . $formproduct->selectWarehouses($id_sw, 'id_sw', 'warehouseopen,warehouseinternal', 1, 0, 0, '', 0, 0, array(), 'minwidth200imp maxwidth200', $listexclude);
	print '</td>';
	// To warehouse
	$id_sw = '';
	$formproduct2 = new FormProduct($db);
	print '<td class="nowraponall">';
	print img_picto($langs->trans("WarehouseTarget"), 'stock', 'class="paddingright"') . $formproduct2->selectWarehouses($id_tw, 'id_tw', 'warehouseopen,warehouseinternal', 1, 0, 0, '', 0, 0, array(), 'minwidth200imp maxwidth200', array(getDolGlobalInt('INVENTAIREPLUS_MAIN_WAREHOUSE_ID', 0)));
	print '</td>';
	// Product
	print '<td class="nowraponall">';
	$filtertype = 0;
	if (!empty($conf->global->STOCK_SUPPORTS_SERVICES)) {
		$filtertype = '';
	}
	if ($conf->global->PRODUIT_LIMIT_SIZE <= 0) {
		$limit = 0;
	} else {
		$limit = $conf->global->PRODUIT_LIMIT_SIZE;
	}

	print img_picto($langs->trans("Product"), 'product', 'class="paddingright"');
	print $form->select_produits((isset($id_product) ? $id_product : 0), 'productid', $filtertype, $limit, 0, -1, 2, '', 1, array(), 0, '1', 0, 'minwidth200imp maxwidth300', 1, '', null, 1);
	print '</td>';
	if (isModEnabled('productbatch')) {
		print '<td class="nowraponall">';
		print img_picto($langs->trans("LotSerial"), 'lot', 'class="paddingright"');
		print '<select name="batch" id="inventaireplus_batch_select" class="flat minwidth250 maxwidth400">';
		print '<option value="">'.$langs->trans("Select").'</option>';
		if (!empty($batch)) {
			print '<option value="'.dol_escape_htmltag($batch).'" selected="selected">'.dol_escape_htmltag($batch).'</option>';
		}
		print '</select>';
		print '<input type="hidden" name="sellby" id="inventaireplus_batch_sellby" value="'.dol_escape_htmltag($sellby).'">';
		print '<input type="hidden" name="eatby" id="inventaireplus_batch_eatby" value="'.dol_escape_htmltag($eatby).'">';
		print '<div id="inventaireplus_batch_expiry" class="small opacitymedium'.(empty($selectedBatchExpiryLabel) ? ' hideobject' : '').'">';
		print dol_escape_htmltag($selectedBatchExpiryLabel);
		print '</div>';
		print '</td>';
	}
	print '<td class="right"><input type="text" class="flat maxwidth50 right" name="qty" value="' . dol_escape_htmltag($qtyInputValue) . '"></td>';
	print '<td class="right"><input type="submit" class="button" name="addline" value="' . dol_escape_htmltag($titletoadd) . '"></td>';
	print '</tr>';
}

foreach ($listofdata as $key => $val) {
	$productstatic->fetch($val['id_product']);

	$warehousestatics->id = 0;
	$warehousestatict->id = 0;
	if ($val['id_sw'] > 0) {
		$warehousestatics->fetch($val['id_sw']);
	}
	if ($val['id_tw'] > 0) {
		$warehousestatict->fetch($val['id_tw']);
	}

	if ($productstatic->id <= 0) {
		$error++;
		setEventMessages($langs->trans("ObjectNotFound", $langs->transnoentitiesnoconv("Product") . ' (id=' . $val['id_product'] . ')'), null, 'errors');
	}
	if ($warehousestatics->id < 0) {	// We accept 0 for source warehouse id
		$error++;
		setEventMessages($langs->trans("ObjectNotFound", $langs->transnoentitiesnoconv("WarehouseSource") . ' (id=' . $val['id_sw'] . ')'), null, 'errors');
	}
	if ($warehousestatict->id <= 0 && !($isFromReceptionMode && empty($val['id_tw']))) {
		$error++;
		setEventMessages($langs->trans("ObjectNotFound", $langs->transnoentitiesnoconv("WarehouseTarget") . ' (id=' . $val['id_tw'] . ')'), null, 'errors');
	}

	if (!$error) {
		print '<tr class="oddeven">';
		print '<td>';
		if ($warehousestatics->id > 0) {
			print $warehousestatics->getNomUrl(1);
		} else {
			print '<span class="opacitymedium">';
			print $langs->trans("None");
			print '</span>';
		}
		print '</td>';
		print '<td>';
		if ($warehousestatict->id > 0) {
			print $warehousestatict->getNomUrl(1);
		} elseif ($isFromReceptionMode && empty($val['id_tw'])) {
			print '<span class="opacitymedium">'.$langs->trans("ToDefine").'</span>';
		}
		print '</td>';
		print '<td>';
		print $productstatic->getNomUrl(1) . ' - ' . dol_escape_htmltag($productstatic->label);
		print '</td>';
		if (isModEnabled('productbatch')) {
			print '<td>';
			print dol_escape_htmltag($val['batch']);
			$lineBatchExpiry = inventaireplusGetBatchExpiryLabel($db, isset($val['sellby']) ? $val['sellby'] : null, isset($val['eatby']) ? $val['eatby'] : null);
			if ($lineBatchExpiry !== '') {
				print '<div class="small opacitymedium">'.dol_escape_htmltag($lineBatchExpiry).'</div>';
			}
			print '</td>';
		}
		print '<td class="right">' . price2num((float) $val['qty'], 'MS') . '</td>';
		$url = $_SERVER["PHP_SELF"] . '?action=delline&token=' . newToken() . '&idline=' . $val['id'];
		if ($isFromReceptionMode && $receptionid > 0) {
			$url .= '&from_reception=1&receptionid=' . ((int) $receptionid) . '&sourcewarehouseid=' . ((int) $receptionSourceWarehouseId);
		}
		print '<td class="right"><a href="' . $url . '">' . img_delete($langs->trans("Remove")) . '</a></td>';
		print '</tr>';
	}
}

print '</table>';
print '</div>';

print '</form>';

if (isModEnabled('productbatch') && !$isFromReceptionMode) {
	$batchAjaxUrl = dol_buildpath('/custom/inventaireplus/ajax/getproductbatches.php', 1);
	$batchSelectLabel = dol_escape_js($langs->transnoentitiesnoconv("Select"));
	$noBatchLabel = dol_escape_js($langs->transnoentitiesnoconv("NoRecordFound"));
	$batchErrorLabel = dol_escape_js($langs->transnoentitiesnoconv("Error"));
	$initialBatchValue = (isset($batch) ? (string) $batch : '');
	$script = <<<JS
(function($) {
	$(function() {
		var batchUrl = %s;
		var batchPlaceholder = %s;
		var noBatchLabel = %s;
		var batchErrorLabel = %s;
		var \$form = $('form[name="formulaire"]');
		var initialBatch = %s;
		var loadTimeout = null;

		function getProductField() {
			var \$field = $('#productid');
			if (\$field.length) {
				return \$field.first();
			}

			return \$form.find('input[name="productid"], select[name="productid"]').first();
		}

		function getWarehouseField() {
			return \$form.find('select[name="id_sw"]');
		}

		function getBatchField() {
			return $('#inventaireplus_batch_select');
		}

		function getSellbyField() {
			return $('#inventaireplus_batch_sellby');
		}

		function getEatbyField() {
			return $('#inventaireplus_batch_eatby');
		}

		function getExpiryField() {
			return $('#inventaireplus_batch_expiry');
		}

		function resetBatchOptions(label, disabled) {
			var \$batch = getBatchField();
			var \$sellby = getSellbyField();
			var \$eatby = getEatbyField();
			var \$expiry = getExpiryField();

			\$batch.empty();
			\$batch.append($('<option>').val('').text(label || batchPlaceholder));
			\$batch.prop('disabled', !!disabled);
			\$sellby.val('');
			\$eatby.val('');
			\$expiry.text('').addClass('hideobject');
		}

		function updateExpiryFromSelection() {
			var \$batch = getBatchField();
			var \$sellby = getSellbyField();
			var \$eatby = getEatbyField();
			var \$expiry = getExpiryField();
			var \$selected = \$batch.find('option:selected');
			var expiryLabel = \$selected.data('expiryLabel') || '';

			\$sellby.val(\$selected.data('sellby') || '');
			\$eatby.val(\$selected.data('eatby') || '');

			if (expiryLabel) {
				\$expiry.text(expiryLabel).removeClass('hideobject');
			} else {
				\$expiry.text('').addClass('hideobject');
			}
		}

		function populateBatches(items) {
			var \$batch = getBatchField();
			var selected = false;

			resetBatchOptions(batchPlaceholder, false);

			$.each(items || [], function(_, item) {
				var \$option = $('<option>').val(item.batch).text(item.label || item.batch);

				\$option.attr('data-sellby', item.sellby || '');
				\$option.attr('data-eatby', item.eatby || '');
				\$option.attr('data-expiry-label', item.expiry_label || '');
				\$batch.append(\$option);

				if (initialBatch && item.batch === initialBatch) {
					\$option.prop('selected', true);
					selected = true;
				}
			});

			if (!selected) {
				\$batch.val('');
			}

			updateExpiryFromSelection();
		}

		function loadBatches() {
			var \$product = getProductField();
			var \$warehouse = getWarehouseField();
			var \$batch = getBatchField();
			var productId = parseInt(\$product.val() || 0, 10);
			var warehouseId = parseInt(\$warehouse.val() || 0, 10);

			initialBatch = \$batch.val() || initialBatch;

			if (!(productId > 0) || !(warehouseId > 0)) {
				resetBatchOptions(batchPlaceholder, true);
				return;
			}

			resetBatchOptions(batchPlaceholder, true);

			$.ajax({
				url: batchUrl,
				type: 'GET',
				dataType: 'json',
				data: {
					fk_product: productId,
					fk_entrepot: warehouseId,
					token: $('input[name="token"]').first().val()
				}
			}).done(function(response) {
				if (!response || response.result !== true) {
					resetBatchOptions(batchErrorLabel, true);
					return;
				}

				if (!response.product_is_batch) {
					resetBatchOptions(batchPlaceholder, true);
					return;
				}

				if (!response.batches || !response.batches.length) {
					resetBatchOptions(noBatchLabel, true);
					return;
				}

				populateBatches(response.batches);
				\$batch.prop('disabled', false);
			}).fail(function() {
				resetBatchOptions(batchErrorLabel, true);
			});
		}

		function scheduleLoadBatches() {
			clearTimeout(loadTimeout);
			loadTimeout = window.setTimeout(function() {
				loadBatches();
			}, 80);
		}

		$(document)
			.off('change.inventaireplusbatch', 'select[name="id_sw"]')
			.on('change.inventaireplusbatch', 'select[name="id_sw"]', function() {
				initialBatch = '';
				scheduleLoadBatches();
			});

		$(document)
			.off('change.inventaireplusbatch select2:select.inventaireplusbatch select2:clear.inventaireplusbatch', '#productid')
			.on('change.inventaireplusbatch select2:select.inventaireplusbatch select2:clear.inventaireplusbatch', '#productid', function() {
				initialBatch = '';
				scheduleLoadBatches();
			});

		$(document)
			.off('change.inventaireplusbatch', 'input[name="productid"], select[name="productid"]')
			.on('change.inventaireplusbatch', 'input[name="productid"], select[name="productid"]', function() {
				initialBatch = '';
				scheduleLoadBatches();
			});

		$(document)
			.off('autocompleteselect.inventaireplusbatch autocompletechange.inventaireplusbatch change.inventaireplusbatch blur.inventaireplusbatch', '#search_productid')
			.on('autocompleteselect.inventaireplusbatch autocompletechange.inventaireplusbatch change.inventaireplusbatch blur.inventaireplusbatch', '#search_productid', function() {
				initialBatch = '';
				scheduleLoadBatches();
			});

		$(document)
			.off('change.inventaireplusbatch', '#inventaireplus_batch_select')
			.on('change.inventaireplusbatch', '#inventaireplus_batch_select', updateExpiryFromSelection);

		loadBatches();
	});
})(jQuery);
JS;
	print '<script nonce="'.getNonce().'">'.sprintf(
		$script,
		json_encode($batchAjaxUrl),
		json_encode($batchSelectLabel),
		json_encode($noBatchLabel),
		json_encode($batchErrorLabel),
		json_encode($initialBatchValue)
	).'</script>';
}

print '<br>';

// Form to validate all movements
if (count($listofdata)) {
	print '<form action="' . $_SERVER["PHP_SELF"] . '" method="POST" name="formulaire2" class="formconsumeproduce">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="createmovements">';
	if ($isFromReceptionMode && $receptionid > 0) {
		print '<input type="hidden" name="from_reception" value="1">';
		print '<input type="hidden" name="receptionid" value="' . ((int) $receptionid) . '">';
		print '<input type="hidden" name="sourcewarehouseid" value="' . ((int) $receptionSourceWarehouseId) . '">';
	}

	// Button to record mass movement
	$codemove = (GETPOSTISSET("codemove") ? GETPOST("codemove", 'alpha') : dol_print_date(dol_now(), '%Y%m%d%H%M%S'));
	$labelmovement = GETPOST("label") ? GETPOST('label') : $langs->trans("MassStockTransferShort") . ' ' . dol_print_date($now, '%Y-%m-%d %H:%M');

	print '<div class="center">';
	if ($isFromReceptionMode) {
		$targetwarehouseid = GETPOSTINT('targetwarehouseid');
		$formproduct2 = new FormProduct($db);
		$excludedWarehouses = array();
		if ($receptionSourceWarehouseId > 0) {
			$excludedWarehouses[] = $receptionSourceWarehouseId;
		}
		print '<span class="fieldrequired">' . $langs->trans("WarehouseTarget") . ':</span> ';
		print img_picto($langs->trans("WarehouseTarget"), 'stock', 'class="paddingright"') . $formproduct2->selectWarehouses($targetwarehouseid, 'targetwarehouseid', 'warehouseopen,warehouseinternal', 1, 0, 0, '', 0, 0, array(), 'minwidth200imp maxwidth200', $excludedWarehouses);
		print '<br><br>';
	}
	print '<span class="fieldrequired">' . $langs->trans("InventoryCode") . ':</span> ';
	print '<input type="text" name="codemove" class="maxwidth300" value="' . dol_escape_htmltag($codemove) . '"> &nbsp; ';
	print '<span class="clearbothonsmartphone"></span>';
	print $langs->trans("MovementLabel") . ': ';
	print '<input type="text" name="label" class="minwidth300" value="' . dol_escape_htmltag($labelmovement) . '"><br>';
	print '<br>';

	print '<div class="center"><input type="submit" class="button" name="valid" value="' . dol_escape_htmltag($buttonrecord) . '"></div>';

	print '<br>';
	print '</div>';

	print '</form>';
}

if ($action == 'delete') {
	print $form->formconfirm($_SERVER["PHP_SELF"] . '?urlfile=' . urlencode(GETPOST('urlfile')) . '&step=3' . $param, $langs->trans('DeleteFile'), $langs->trans('ConfirmDeleteFile'), 'confirm_deletefile', '', 0, 1);
}

// End of page
llxFooter();
$db->close();


/**
 * Verify if $haystack startswith $needle
 *
 * @param string $haystack string to test
 * @param string $needle string to find
 * @return bool false if Ko true else
 */
function startsWith($haystack, $needle)
{
	$length = strlen($needle);
	return substr($haystack, 0, $length) === $needle;
}

/**
 * Fetch object with ref
 *
 * @param Object $static_object static object to fetch
 * @param string $tmp_ref ref of the object to fetch
 * @return int <0 if Ko or Id of object
 */
function fetchref($static_object, $tmp_ref)
{
	if (startsWith($tmp_ref, 'ref:')) {
		$tmp_ref = str_replace('ref:', '', $tmp_ref);
	}
	$static_object->fetch('', $tmp_ref);
	return $static_object->id;
}

