<?php
/* Copyright (C) 2026 Omega Junior
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file        htdocs/custom/inventaireplus/product/inventory/volumeactions.php
 * \ingroup     inventaireplus
 * \brief       Optimized server-side actions for large native inventories.
 */

$res = 0;
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/inventory/class/inventory.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/inventaireplus/lib/inventoryvolume.lib.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('main', 'stocks', 'inventaireplus@inventaireplus'));

if ($user->socid > 0) {
	accessforbidden();
}
if (!inventaireplusCanUseOptimizedInventoryActions($user)) {
	accessforbidden();
}

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');

$object = new Inventory($db);
if ($id <= 0 || $object->fetch($id) <= 0) {
	accessforbidden($langs->trans('InventoryPlusInvalidInventory'));
}

$backUrl = DOL_URL_ROOT.'/product/inventory/inventory.php?id='.(int) $object->id;

if ($action === 'optimized_savelines') {
	if (!checkToken()) {
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('ok' => false, 'error' => 'Invalid token'));
		exit;
	}

	$payload = isset($_POST['payload']) ? (string) $_POST['payload'] : '';
	$decodedPayload = json_decode($payload, true);
	if (!is_array($decodedPayload)) {
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('ok' => false, 'error' => $langs->trans('InventoryPlusOptimizedSaveInvalidPayload')));
		exit;
	}

	$lines = isset($decodedPayload['lines']) && is_array($decodedPayload['lines']) ? $decodedPayload['lines'] : array();
	$result = inventaireplusSaveLargeInventoryLines($db, $user, (int) $object->id, $lines);

	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(array(
		'ok' => !empty($result['ok']),
		'updated' => isset($result['updated']) ? (int) $result['updated'] : 0,
		'error' => empty($result['error']) ? '' : $langs->trans($result['error']),
		'line' => empty($result['line']) ? 0 : (int) $result['line'],
	));
	exit;
}

if ($action === 'optimized_autofill' || $action === 'optimized_record') {
	if (!checkToken()) {
		accessforbidden('Invalid token');
	}
	if ($confirm !== 'yes') {
		header('Location: '.$backUrl);
		exit;
	}

	if ($action === 'optimized_autofill') {
		$result = inventaireplusAutofillLargeInventory($db, $user, (int) $object->id);
		if (!empty($result['ok'])) {
			setEventMessages($langs->trans('InventoryPlusOptimizedAutofillDone', (int) $result['updated']), null, 'mesgs');
		} else {
			$errorMessage = !empty($result['error']) ? $langs->trans($result['error']) : '';
			setEventMessages($langs->trans('InventoryPlusOptimizedAutofillFailed').($errorMessage ? ' : '.$errorMessage : ''), null, 'errors');
		}
		header('Location: '.$backUrl);
		exit;
	}

	$result = inventaireplusRecordLargeInventory($db, $user, (int) $object->id, $langs);
	if (!empty($result['ok'])) {
		setEventMessages($langs->trans('InventoryPlusOptimizedRecordDone', (int) $result['processed'], (int) $result['movements']), null, 'mesgs');
	} else {
		$errorMessage = !empty($result['error']) ? $langs->trans($result['error']) : '';
		setEventMessages($langs->trans('InventoryPlusOptimizedRecordFailed').($errorMessage ? ' : '.$errorMessage : ''), null, 'errors');
	}
	header('Location: '.$backUrl);
	exit;
}

$title = $langs->trans('InventoryPlusLargeInventoryActions');
llxHeader('', $title, '', '', 0, 0, array(), array(), '', 'mod-inventaireplus page-large-inventory-actions');

$form = new Form($db);
$formConfirm = '';

if ($action === 'confirm_optimized_autofill') {
	$formConfirm = $form->formconfirm(
		$_SERVER['PHP_SELF'].'?id='.(int) $object->id.'&action=optimized_autofill&token='.newToken(),
		$langs->trans('InventoryPlusOptimizedAutofill'),
		$langs->trans('InventoryPlusConfirmOptimizedAutofill'),
		'optimized_autofill',
		'',
		0,
		1
	);
} elseif ($action === 'confirm_optimized_record') {
	$formConfirm = $form->formconfirm(
		$_SERVER['PHP_SELF'].'?id='.(int) $object->id.'&action=optimized_record&token='.newToken(),
		$langs->trans('InventoryPlusOptimizedRecord'),
		$langs->trans('InventoryPlusConfirmOptimizedRecord'),
		'optimized_record',
		'',
		0,
		1
	);
}

print load_fiche_titre($title, '<a href="'.$backUrl.'">'.$langs->trans('BackToInventory').'</a>', 'inventory');
print $formConfirm;

if (empty($formConfirm)) {
	print '<div class="info">'.$langs->trans('InventoryPlusOptimizedActionUnavailable').'</div>';
}

llxFooter();
$db->close();
