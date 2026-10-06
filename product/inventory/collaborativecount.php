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
$view = GETPOST('view', 'aZ09');
$viewProvided = GETPOSTISSET('view');
if (!in_array($view, array('count', 'control'), true)) $view = 'count';
$defaultListLimit = max(1, (int) $conf->liste_limit);
$totalsLimit = max(1, GETPOSTINT('totals_limit') > 0 ? GETPOSTINT('totals_limit') : $defaultListLimit);
$totalsPage = max(0, GETPOSTINT('totals_page'));
$contributionsLimit = max(1, GETPOSTINT('contributions_limit') > 0 ? GETPOSTINT('contributions_limit') : $defaultListLimit);
$contributionsPage = max(0, GETPOSTINT('contributions_page'));
$listContext = GETPOST('list_context', 'aZ09');
$buttonSearch = GETPOST('button_search', 'alphanohtml');
$buttonRemoveFilter = GETPOST('button_removefilter', 'alphanohtml') || GETPOST('button_removefilter_x', 'alphanohtml') || GETPOST('button_removefilter.x', 'alphanohtml');

$searchTotalsRef = GETPOST('search_totals_ref', 'alphanohtml');
$searchTotalsLabel = GETPOST('search_totals_label', 'alphanohtml');
$searchTotalsBatch = GETPOST('search_totals_batch', 'alphanohtml');
$searchTotalsScans = GETPOST('search_totals_scans', 'alphanohtml');
$searchTotalsTheoretical = GETPOST('search_totals_theoretical', 'alphanohtml');
$searchTotalsPhysical = GETPOST('search_totals_physical', 'alphanohtml');
$searchTotalsDelta = GETPOST('search_totals_delta', 'alphanohtml');

$searchContribDateStart = GETPOSTINT('search_contrib_date_start');
if (!$searchContribDateStart && GETPOSTINT('search_contrib_date_startyear')) {
	$searchContribDateStart = dol_mktime(0, 0, 0, GETPOSTINT('search_contrib_date_startmonth'), GETPOSTINT('search_contrib_date_startday'), GETPOSTINT('search_contrib_date_startyear'));
}
$searchContribDateEnd = GETPOSTINT('search_contrib_date_end');
if (!$searchContribDateEnd && GETPOSTINT('search_contrib_date_endyear')) {
	$searchContribDateEnd = dol_mktime(23, 59, 59, GETPOSTINT('search_contrib_date_endmonth'), GETPOSTINT('search_contrib_date_endday'), GETPOSTINT('search_contrib_date_endyear'));
}
$searchContribUser = GETPOST('search_contrib_user', 'alphanohtml');
$searchContribZone = GETPOST('search_contrib_zone', 'alphanohtml');
$searchContribProduct = GETPOST('search_contrib_product', 'alphanohtml');
$searchContribBatch = GETPOST('search_contrib_batch', 'alphanohtml');
$searchContribQty = GETPOST('search_contrib_qty', 'alphanohtml');
if (!isModEnabled('productbatch')) {
	$searchTotalsBatch = '';
	$searchContribBatch = '';
}

if ($buttonRemoveFilter && $listContext === 'totals') {
	$searchTotalsRef = $searchTotalsLabel = $searchTotalsBatch = '';
	$searchTotalsScans = $searchTotalsTheoretical = $searchTotalsPhysical = $searchTotalsDelta = '';
}
if ($buttonRemoveFilter && $listContext === 'contributions') {
	$searchContribDateStart = $searchContribDateEnd = 0;
	$searchContribUser = $searchContribZone = $searchContribProduct = $searchContribBatch = $searchContribQty = '';
}
if (($buttonSearch || $buttonRemoveFilter) && $listContext === 'totals') $totalsPage = 0;
if (($buttonSearch || $buttonRemoveFilter) && $listContext === 'contributions') $contributionsPage = 0;

$totalsFilters = array(
	'ref' => $searchTotalsRef,
	'label' => $searchTotalsLabel,
	'batch' => $searchTotalsBatch,
	'scans' => $searchTotalsScans,
	'theoretical' => $searchTotalsTheoretical,
	'physical' => $searchTotalsPhysical,
	'delta' => $searchTotalsDelta,
);
$contributionsFilters = array(
	'date_start' => $searchContribDateStart,
	'date_end' => $searchContribDateEnd,
	'user' => $searchContribUser,
	'zone' => $searchContribZone,
	'product' => $searchContribProduct,
	'batch' => $searchContribBatch,
	'qty' => $searchContribQty,
);
$countListFilterParameters = array_filter(array(
	'search_totals_ref' => $searchTotalsRef,
	'search_totals_label' => $searchTotalsLabel,
	'search_totals_batch' => $searchTotalsBatch,
	'search_totals_scans' => $searchTotalsScans,
	'search_totals_theoretical' => $searchTotalsTheoretical,
	'search_totals_physical' => $searchTotalsPhysical,
	'search_totals_delta' => $searchTotalsDelta,
	'search_contrib_date_start' => $searchContribDateStart,
	'search_contrib_date_end' => $searchContribDateEnd,
	'search_contrib_user' => $searchContribUser,
	'search_contrib_zone' => $searchContribZone,
	'search_contrib_product' => $searchContribProduct,
	'search_contrib_batch' => $searchContribBatch,
	'search_contrib_qty' => $searchContribQty,
), static function ($value) { return (string) $value !== '' && $value !== 0; });
$selectedFieldsContexts = array(
	'inventaireplus_collaborative_totals' => array(
		'field' => 'selectedfields_totals',
		'allowed' => array('totals_ref', 'totals_label', 'totals_batch', 'totals_scans', 'totals_theoretical', 'totals_physical', 'totals_delta'),
	),
	'inventaireplus_collaborative_contributions' => array(
		'field' => 'selectedfields_contributions',
		'allowed' => array('contrib_date', 'contrib_user', 'contrib_zone', 'contrib_product', 'contrib_batch', 'contrib_qty'),
	),
);
$isAdmin = !empty($user->admin);
$canReadInventory = ($isAdmin || $user->hasRight('stock', 'lire') || $user->hasRight('stock', 'inventory_advance', 'read') || $user->hasRight('stock', 'inventory_advance', 'write'));
$canCount = $canReadInventory && $user->hasRight('inventaireplus', 'collaborativecount', 'write');
$canConsolidate = ($isAdmin || ($canReadInventory && $user->hasRight('inventaireplus', 'collaborativecount', 'consolidate') && ($user->hasRight('stock', 'inventory_advance', 'write') || $user->hasRight('stock', 'mouvement', 'creer') || $user->hasRight('stock', 'creer'))));
$canControl = ($isAdmin || ($canReadInventory && $user->hasRight('inventaireplus', 'collaborativecount', 'control')));
if (!$canCount && !$canConsolidate && !$canControl) accessforbidden();
if (!$viewProvided && !$canCount && ($canConsolidate || $canControl)) $view = 'control';

$object = new Inventory($db);
if ($inventoryId <= 0 || $object->fetch($inventoryId) <= 0) accessforbidden();
if ((int) $object->entity !== (int) getEntity('inventory')) accessforbidden();

$session = inventaireplusFetchCountSession($db, $inventoryId);

/*
 * Actions
 */
if (GETPOST('formfilteraction', 'alphanohtml') === 'listafterchangingselectedfields') {
	$selectedFieldsContext = GETPOST('selectedfields_context', 'aZ09');
	if (isset($selectedFieldsContexts[$selectedFieldsContext])) {
		$fieldDefinition = $selectedFieldsContexts[$selectedFieldsContext];
		$requestedFields = explode(',', GETPOST($fieldDefinition['field'], 'alphanohtml'));
		$selectedFields = array_values(array_intersect($fieldDefinition['allowed'], $requestedFields));
		require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
		dol_set_user_param($db, $conf, $user, array('MAIN_SELECTEDFIELDS_'.$selectedFieldsContext => implode(',', $selectedFields)));
	}
}

if ($action === 'addcontribution' && $canCount) {
	$productToken = trim(GETPOST('product_token', 'alphanohtml'));
	$batch = trim(GETPOST('batch', 'restricthtml'));
	$zone = trim(GETPOST('zone', 'restricthtml'));
	$qtyRaw = GETPOST('qty', 'alphanohtml');
	$qty = (float) price2num($qtyRaw, 'MS');
	$result = inventaireplusAddCountContribution($db, $user, $inventoryId, $productToken, $batch, $zone, $qty, GETPOST('scan_key', 'alphanohtml'));
	if (!empty($result['ok'])) {
		setEventMessages($langs->trans('InventoryPlusCollaborativeContributionSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$inventoryId.'&view=count');
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
	$redirectParameters = array_merge(array('id' => $inventoryId, 'view' => 'count', 'totals_page' => $totalsPage, 'totals_limit' => $totalsLimit, 'contributions_page' => $contributionsPage, 'contributions_limit' => $contributionsLimit), $countListFilterParameters);
	header('Location: '.$_SERVER['PHP_SELF'].'?'.http_build_query($redirectParameters, '', '&'));
	exit;
}

if ($action === 'consolidate' && $confirm === 'yes' && $canConsolidate) {
	$result = inventaireplusConsolidateCollaborativeCount($db, $user, $inventoryId);
	if (!empty($result['ok'])) {
		setEventMessages($langs->trans('InventoryPlusCollaborativeConsolidated', $result['lines']), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.$inventoryId.'&view=control');
		exit;
	}
	setEventMessages($langs->trans($result['error']), null, 'errors');
}

if ($action === 'buildcontrolpdf' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($canCount || $canConsolidate || $canControl)) {
	$result = inventaireplusCreateControlReport($db, $user, $inventoryId, $langs);
	if (!empty($result['ok'])) {
		setEventMessages($langs->trans('InventoryPlusCollaborativeControlGenerated'), null, 'mesgs');
		header('Location: '.DOL_URL_ROOT.'/document.php?modulepart=movement&file='.urlencode($result['relativefile']));
		exit;
	}
	setEventMessages($langs->trans($result['error']), null, 'errors');
}

if ($action === 'approvecontrol' && $confirm === 'yes' && $canControl) {
	$result = inventaireplusApproveControlReport($db, $user, $inventoryId, GETPOSTINT('control_id'));
	if (!empty($result['ok'])) {
		setEventMessages($langs->trans('InventoryPlusCollaborativeControlApproved'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans($result['error']), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$inventoryId.'&view=control');
	exit;
}

$session = inventaireplusFetchCountSession($db, $inventoryId);
$sessionStatus = ($session ? (int) $session->status : 0);
$totalCount = 0;
$contributionCount = 0;
$totals = array();
$contributions = array();
if ($session && $view === 'count') {
	$totalCount = inventaireplusCountCollaborativeTotals($db, (int) $session->rowid, $totalsFilters);
	if ($totalCount > 0 && ($totalsPage * $totalsLimit) >= $totalCount) $totalsPage = max(0, (int) ceil($totalCount / $totalsLimit) - 1);
	$totals = inventaireplusFetchCollaborativeTotals($db, (int) $session->rowid, $totalsLimit, $totalsPage * $totalsLimit, $totalsFilters);
	$contributionCount = inventaireplusCountContributions($db, (int) $session->rowid, $contributionsFilters);
	if ($contributionCount > 0 && ($contributionsPage * $contributionsLimit) >= $contributionCount) $contributionsPage = max(0, (int) ceil($contributionCount / $contributionsLimit) - 1);
	$contributions = inventaireplusFetchRecentContributions($db, (int) $session->rowid, $contributionsLimit, $contributionsPage * $contributionsLimit, $contributionsFilters);
}
$controlStorageAvailable = inventaireplusControlStorageAvailable($db);
$controlReport = ($session && $controlStorageAvailable ? inventaireplusFetchLatestControlReport($db, (int) $session->rowid) : null);
$campaignOpen = ($sessionStatus === 0 && (int) $object->status === Inventory::STATUS_VALIDATED);
$controlReady = ($isAdmin || ($controlReport && (int) $controlReport->status === 1));
$scanKey = bin2hex(random_bytes(16));
$form = new Form($db);
$batchEnabled = isModEnabled('productbatch');
$totalsArrayFields = array(
	'totals_ref' => array('label' => 'Ref', 'checked' => 1, 'position' => 10),
	'totals_label' => array('label' => 'Label', 'checked' => 1, 'position' => 20),
	'totals_batch' => array('label' => 'Batch', 'checked' => 1, 'enabled' => $batchEnabled, 'position' => 30),
	'totals_scans' => array('label' => 'InventoryPlusCollaborativeScans', 'checked' => 1, 'position' => 40),
	'totals_theoretical' => array('label' => 'InventoryTheoreticalQty', 'checked' => 1, 'position' => 50),
	'totals_physical' => array('label' => 'InventoryPhysicalQty', 'checked' => 1, 'position' => 60),
	'totals_delta' => array('label' => 'InventoryDeltaQty', 'checked' => 1, 'position' => 70),
);
$contributionsArrayFields = array(
	'contrib_date' => array('label' => 'Date', 'checked' => 1, 'position' => 10),
	'contrib_user' => array('label' => 'User', 'checked' => 1, 'position' => 20),
	'contrib_zone' => array('label' => 'InventoryPlusCollaborativeZone', 'checked' => 1, 'position' => 30),
	'contrib_product' => array('label' => 'Product', 'checked' => 1, 'position' => 40),
	'contrib_batch' => array('label' => 'Batch', 'checked' => 1, 'enabled' => $batchEnabled, 'position' => 50),
	'contrib_qty' => array('label' => 'Qty', 'checked' => 1, 'position' => 60),
);
$totalsSelectedFields = $form->multiSelectArrayWithCheckbox('selectedfields_totals', $totalsArrayFields, 'inventaireplus_collaborative_totals', getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN'));
$contributionsSelectedFields = $form->multiSelectArrayWithCheckbox('selectedfields_contributions', $contributionsArrayFields, 'inventaireplus_collaborative_contributions', getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN'));
$formConfirm = '';
if ($action === 'confirm_consolidate' && $canConsolidate && $campaignOpen) {
	$confirmConsolidation = $langs->trans('InventoryPlusCollaborativeConfirmConsolidate');
	if ($isAdmin && (!$controlReport || (int) $controlReport->status !== 1)) {
		$confirmConsolidation .= '<br><br><strong>'.$langs->trans('InventoryPlusCollaborativeAdminConsolidation').'</strong>';
	}
	$formConfirm = $form->formconfirm(
		$_SERVER['PHP_SELF'].'?id='.$inventoryId.'&view=control',
		$langs->trans('InventoryPlusCollaborativeConsolidate'),
		$confirmConsolidation,
		'consolidate',
		'',
		0,
		1
	);
} elseif ($action === 'confirm_voidcontribution' && ($canCount || $canConsolidate) && $campaignOpen) {
	$contributionId = GETPOSTINT('contribution_id');
	$contributionToVoid = ($session ? inventaireplusFetchContribution($db, (int) $session->rowid, $contributionId) : null);
	if ($contributionToVoid && (!(int) $contributionToVoid->active || (!$canConsolidate && (int) $contributionToVoid->fk_user_author !== (int) $user->id))) $contributionToVoid = null;
	if ($contributionToVoid) {
		$confirmParameters = array_merge(array('id' => $inventoryId, 'view' => 'count', 'contribution_id' => $contributionId, 'totals_page' => $totalsPage, 'totals_limit' => $totalsLimit, 'contributions_page' => $contributionsPage, 'contributions_limit' => $contributionsLimit), $countListFilterParameters);
		$formConfirm = $form->formconfirm(
			$_SERVER['PHP_SELF'].'?'.http_build_query($confirmParameters, '', '&'),
			$langs->trans('InventoryPlusCollaborativeVoidContribution'),
			$langs->trans('InventoryPlusCollaborativeConfirmVoidContribution', dol_escape_htmltag($contributionToVoid->ref), price($contributionToVoid->qty), dol_escape_htmltag($contributionToVoid->zone)),
			'voidcontribution',
			'',
			0,
			1
		);
	}
} elseif ($action === 'confirm_approvecontrol' && $canControl && $campaignOpen && $controlReport && (int) $controlReport->status === 0 && GETPOSTINT('control_id') === (int) $controlReport->rowid) {
	$formConfirm = $form->formconfirm(
		$_SERVER['PHP_SELF'].'?id='.$inventoryId.'&view=control&control_id='.((int) $controlReport->rowid),
		$langs->trans('InventoryPlusCollaborativeControlApprove'),
		$langs->trans('InventoryPlusCollaborativeControlApproveConfirm'),
		'approvecontrol',
		'',
		0,
		1
	);
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

$controlStatusLabel = 'InventoryPlusCollaborativeControlGeneratedStatus';
$controlStatusClass = 'badge-status4';
if ($controlReport) {
	if ((int) $controlReport->status === 1) {
		$controlStatusLabel = 'InventoryPlusCollaborativeControlApprovedStatus';
		$controlStatusClass = 'badge-status6';
	} elseif ((int) $controlReport->status === 2) {
		$controlStatusLabel = 'InventoryPlusCollaborativeControlObsoleteStatus';
		$controlStatusClass = 'badge-status8';
	}
}

$viewHead = array(
	array($_SERVER['PHP_SELF'].'?id='.$inventoryId.'&view=count', $langs->trans('InventoryPlusCollaborativeCountingTab'), 'count'),
	array($_SERVER['PHP_SELF'].'?id='.$inventoryId.'&view=control', $langs->trans('InventoryPlusCollaborativeControlTab'), 'control'),
);
print dol_get_fiche_head($viewHead, $view, '', -1);

if ($view === 'count') {
	if ($campaignOpen && $canCount) {
		print '<form id="inventaireplus-count-form" method="POST" action="'.$_SERVER['PHP_SELF'].'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="addcontribution">';
		print '<input type="hidden" name="id" value="'.$inventoryId.'">';
		print '<input type="hidden" name="view" value="count">';
		print '<input type="hidden" name="scan_key" value="'.dol_escape_htmltag($scanKey).'">';
		print '<div class="marginbottomonly tabBarWithBottom">';
		print '<table class="border centpercent tableforfieldcreate">';
		print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans('InventoryPlusCollaborativeZone').'</td><td><input class="flat minwidth300" id="inventaireplus-zone" name="zone" value="'.dol_escape_htmltag(GETPOST('zone', 'restricthtml')).'" maxlength="128" required></td></tr>';
		print '<tr><td class="fieldrequired">'.$langs->trans('Qty').'</td><td><input class="flat maxwidth100 right" id="inventaireplus-qty" name="qty" value="'.dol_escape_htmltag(GETPOST('qty', 'alphanohtml')).'" inputmode="decimal" required></td></tr>';
		if (isModEnabled('productbatch')) {
			print '<tr><td>'.$langs->trans('Batch').'</td><td><input class="flat minwidth300" name="batch" value="'.dol_escape_htmltag(GETPOST('batch', 'restricthtml')).'" maxlength="128" autocomplete="off"></td></tr>';
		}
		$warehouseId = (int) $object->fk_warehouse;
		$productSelector = $form->select_produits(GETPOSTINT('product_token'), 'product_token', '0', 0, 0, -1, 2, '', 0, array(), 0, '1', 0, 'minwidth300 maxwidth300', 1, '', null, 1, -1, $warehouseId);
		$nativeProductAjaxUrl = DOL_URL_ROOT.'/product/ajax/products.php';
		$inventoryPlusProductAjaxUrl = dol_buildpath('/inventaireplus/ajax/products.php', 1);
		$productSelector = str_replace($nativeProductAjaxUrl, $inventoryPlusProductAjaxUrl, $productSelector);
		$productSelector = str_replace('warehouseid='.$warehouseId, 'warehouseid='.$warehouseId.'&inventoryid='.$inventoryId, $productSelector);
		print '<tr><td class="fieldrequired">'.$langs->trans('Product').'</td><td>'.$productSelector.' <span class="opacitymedium">'.$langs->trans('InventoryPlusCollaborativeProductHelp').'</span></td></tr>';
		print '</table>';
		print '</div>';
		print '<div class="center"><input class="button button-save" type="submit" value="'.$langs->trans('InventoryPlusCollaborativeAddContribution').'"></div>';
		print '</form><br>';
	} elseif ((int) $object->status !== Inventory::STATUS_VALIDATED) {
		print '<div class="warning">'.$langs->trans('InventoryPlusCollaborativeInventoryNotOpen').'</div>';
	}

	$navigationParameters = array(
		'id' => $inventoryId,
		'view' => 'count',
		'totals_page' => $totalsPage,
		'totals_limit' => $totalsLimit,
		'contributions_page' => $contributionsPage,
		'contributions_limit' => $contributionsLimit,
	);
	$totalsFilterParameters = array(
		'search_totals_ref' => $searchTotalsRef,
		'search_totals_label' => $searchTotalsLabel,
		'search_totals_batch' => $searchTotalsBatch,
		'search_totals_scans' => $searchTotalsScans,
		'search_totals_theoretical' => $searchTotalsTheoretical,
		'search_totals_physical' => $searchTotalsPhysical,
		'search_totals_delta' => $searchTotalsDelta,
	);
	$contributionsFilterParameters = array(
		'search_contrib_date_start' => $searchContribDateStart,
		'search_contrib_date_end' => $searchContribDateEnd,
		'search_contrib_user' => $searchContribUser,
		'search_contrib_zone' => $searchContribZone,
		'search_contrib_product' => $searchContribProduct,
		'search_contrib_batch' => $searchContribBatch,
		'search_contrib_qty' => $searchContribQty,
	);
	$navigationParameters = array_merge($navigationParameters, array_filter($totalsFilterParameters, static function ($value) { return (string) $value !== ''; }), array_filter($contributionsFilterParameters, static function ($value) { return (string) $value !== '' && $value !== 0; }));
	$actionsOnLeft = (bool) getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN');
	$totalsPager = inventaireplusBuildListPager($_SERVER['PHP_SELF'], $navigationParameters, 'totals_page', 'totals_limit', $totalsPage, $totalsLimit, $totalCount);
	print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="id" value="'.$inventoryId.'">';
	print '<input type="hidden" name="view" value="count">';
	print '<input type="hidden" name="formfilteraction" value="list">';
	print '<input type="hidden" name="list_context" value="totals">';
	print '<input type="hidden" name="selectedfields_context" value="inventaireplus_collaborative_totals">';
	print '<input type="hidden" name="totals_page" value="'.$totalsPage.'">';
	print '<input type="hidden" name="contributions_page" value="'.$contributionsPage.'">';
	print '<input type="hidden" name="contributions_limit" value="'.$contributionsLimit.'">';
	foreach ($contributionsFilterParameters as $name => $value) if ((string) $value !== '' && $value !== 0) print '<input type="hidden" name="'.$name.'" value="'.dol_escape_htmltag($value).'">';
	$totalsHiddenFilters = array(
		'totals_ref' => array('search_totals_ref', $searchTotalsRef),
		'totals_label' => array('search_totals_label', $searchTotalsLabel),
		'totals_batch' => array('search_totals_batch', $searchTotalsBatch),
		'totals_scans' => array('search_totals_scans', $searchTotalsScans),
		'totals_theoretical' => array('search_totals_theoretical', $searchTotalsTheoretical),
		'totals_physical' => array('search_totals_physical', $searchTotalsPhysical),
		'totals_delta' => array('search_totals_delta', $searchTotalsDelta),
	);
	foreach ($totalsHiddenFilters as $fieldKey => $filter) if (empty($totalsArrayFields[$fieldKey]['checked']) && (string) $filter[1] !== '') print '<input type="hidden" name="'.$filter[0].'" value="'.dol_escape_htmltag($filter[1]).'">';
	print_barre_liste($langs->trans('InventoryPlusCollaborativeTotals'), $totalsPage, $_SERVER['PHP_SELF'], '', '', '', '', count($totals), $totalCount, 'list', 0, '', '', 0, -1, 1, 0, $totalsPager);
	print '<div class="info">'.$langs->trans('InventoryPlusCollaborativeConsolidationScope').'</div>';
	print '<div class="div-table-responsive">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre_filter">';
	if ($actionsOnLeft) print '<td class="liste_titre center maxwidthsearch">'.$form->showFilterButtons('left').'</td>';
	if (!empty($totalsArrayFields['totals_ref']['checked'])) print '<td class="liste_titre"><input class="flat maxwidth100" type="text" name="search_totals_ref" value="'.dol_escape_htmltag($searchTotalsRef).'"></td>';
	if (!empty($totalsArrayFields['totals_label']['checked'])) print '<td class="liste_titre"><input class="flat maxwidth150" type="text" name="search_totals_label" value="'.dol_escape_htmltag($searchTotalsLabel).'"></td>';
	if (!empty($totalsArrayFields['totals_batch']['checked'])) print '<td class="liste_titre"><input class="flat maxwidth100" type="text" name="search_totals_batch" value="'.dol_escape_htmltag($searchTotalsBatch).'"></td>';
	if (!empty($totalsArrayFields['totals_scans']['checked'])) print '<td class="liste_titre right"><input class="flat width50 right" type="text" name="search_totals_scans" value="'.dol_escape_htmltag($searchTotalsScans).'"></td>';
	if (!empty($totalsArrayFields['totals_theoretical']['checked'])) print '<td class="liste_titre right"><input class="flat width75 right" type="text" name="search_totals_theoretical" value="'.dol_escape_htmltag($searchTotalsTheoretical).'"></td>';
	if (!empty($totalsArrayFields['totals_physical']['checked'])) print '<td class="liste_titre right"><input class="flat width75 right" type="text" name="search_totals_physical" value="'.dol_escape_htmltag($searchTotalsPhysical).'"></td>';
	if (!empty($totalsArrayFields['totals_delta']['checked'])) print '<td class="liste_titre right"><input class="flat width75 right" type="text" name="search_totals_delta" value="'.dol_escape_htmltag($searchTotalsDelta).'"></td>';
	if (!$actionsOnLeft) print '<td class="liste_titre center maxwidthsearch">'.$form->showFilterButtons().'</td>';
	print '</tr>';
	print '<tr class="liste_titre">';
	if ($actionsOnLeft) print '<th class="center maxwidthsearch">'.$totalsSelectedFields.'</th>';
	if (!empty($totalsArrayFields['totals_ref']['checked'])) print '<th>'.$langs->trans('Ref').'</th>';
	if (!empty($totalsArrayFields['totals_label']['checked'])) print '<th>'.$langs->trans('Label').'</th>';
	if (!empty($totalsArrayFields['totals_batch']['checked'])) print '<th>'.$langs->trans('Batch').'</th>';
	if (!empty($totalsArrayFields['totals_scans']['checked'])) print '<th class="right">'.$langs->trans('InventoryPlusCollaborativeScans').'</th>';
	if (!empty($totalsArrayFields['totals_theoretical']['checked'])) print '<th class="right">'.$langs->trans('InventoryTheoreticalQty').'</th>';
	if (!empty($totalsArrayFields['totals_physical']['checked'])) print '<th class="right">'.$langs->trans('InventoryPhysicalQty').'</th>';
	if (!empty($totalsArrayFields['totals_delta']['checked'])) print '<th class="right">'.$langs->trans('InventoryDeltaQty').'</th>';
	if (!$actionsOnLeft) print '<th class="center maxwidthsearch">'.$totalsSelectedFields.'</th>';
	print '</tr>';
	$totalsColumnCount = 1;
	foreach ($totalsArrayFields as $field) if (!empty($field['checked'])) $totalsColumnCount++;
	if (empty($totals)) print '<tr><td colspan="'.$totalsColumnCount.'" class="opacitymedium">'.$langs->trans('None').'</td></tr>';
	foreach ($totals as $row) {
		print '<tr class="oddeven">';
		if ($actionsOnLeft) print '<td></td>';
		if (!empty($totalsArrayFields['totals_ref']['checked'])) print '<td>'.dol_escape_htmltag($row->ref).'</td>';
		if (!empty($totalsArrayFields['totals_label']['checked'])) print '<td>'.dol_escape_htmltag($row->label).'</td>';
		if (!empty($totalsArrayFields['totals_batch']['checked'])) print '<td>'.dol_escape_htmltag($row->batch).'</td>';
		if (!empty($totalsArrayFields['totals_scans']['checked'])) print '<td class="right">'.((int) $row->contribution_count).'</td>';
		if (!empty($totalsArrayFields['totals_theoretical']['checked'])) print '<td class="right">'.price($row->qty_stock).'</td>';
		if (!empty($totalsArrayFields['totals_physical']['checked'])) print '<td class="right">'.price($row->counted_qty).'</td>';
		if (!empty($totalsArrayFields['totals_delta']['checked'])) print '<td class="right">'.price($row->counted_qty - $row->qty_stock).'</td>';
		if (!$actionsOnLeft) print '<td></td>';
		print '</tr>';
	}
	print '</table></div></form><br>';

	$contributionsPager = inventaireplusBuildListPager($_SERVER['PHP_SELF'], $navigationParameters, 'contributions_page', 'contributions_limit', $contributionsPage, $contributionsLimit, $contributionCount);
	print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="id" value="'.$inventoryId.'">';
	print '<input type="hidden" name="view" value="count">';
	print '<input type="hidden" name="formfilteraction" value="list">';
	print '<input type="hidden" name="list_context" value="contributions">';
	print '<input type="hidden" name="selectedfields_context" value="inventaireplus_collaborative_contributions">';
	print '<input type="hidden" name="contributions_page" value="'.$contributionsPage.'">';
	print '<input type="hidden" name="totals_page" value="'.$totalsPage.'">';
	print '<input type="hidden" name="totals_limit" value="'.$totalsLimit.'">';
	foreach ($totalsFilterParameters as $name => $value) if ((string) $value !== '') print '<input type="hidden" name="'.$name.'" value="'.dol_escape_htmltag($value).'">';
	$contributionsHiddenFilters = array(
		'contrib_date' => array('search_contrib_date_start' => $searchContribDateStart, 'search_contrib_date_end' => $searchContribDateEnd),
		'contrib_user' => array('search_contrib_user' => $searchContribUser),
		'contrib_zone' => array('search_contrib_zone' => $searchContribZone),
		'contrib_product' => array('search_contrib_product' => $searchContribProduct),
		'contrib_batch' => array('search_contrib_batch' => $searchContribBatch),
		'contrib_qty' => array('search_contrib_qty' => $searchContribQty),
	);
	foreach ($contributionsHiddenFilters as $fieldKey => $filters) {
		if (!empty($contributionsArrayFields[$fieldKey]['checked'])) continue;
		foreach ($filters as $name => $value) if ((string) $value !== '' && !($fieldKey === 'contrib_date' && empty($value))) print '<input type="hidden" name="'.$name.'" value="'.dol_escape_htmltag($value).'">';
	}
	print_barre_liste($langs->trans('InventoryPlusCollaborativeRecentContributions'), $contributionsPage, $_SERVER['PHP_SELF'], '', '', '', '', count($contributions), $contributionCount, 'history', 0, '', '', 0, -1, 1, 0, $contributionsPager);
	print '<div class="div-table-responsive">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre_filter">';
	if ($actionsOnLeft) print '<td class="liste_titre center maxwidthsearch">'.$form->showFilterButtons('left').'</td>';
	if (!empty($contributionsArrayFields['contrib_date']['checked'])) {
		print '<td class="liste_titre center">';
		print '<div class="nowrapfordate">'.$form->selectDate($searchContribDateStart ?: -1, 'search_contrib_date_start', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('From')).'</div>';
		print '<div class="nowrapfordate">'.$form->selectDate($searchContribDateEnd ?: -1, 'search_contrib_date_end', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('to')).'</div>';
		print '</td>';
	}
	if (!empty($contributionsArrayFields['contrib_user']['checked'])) print '<td class="liste_titre"><input class="flat maxwidth100" type="text" name="search_contrib_user" value="'.dol_escape_htmltag($searchContribUser).'"></td>';
	if (!empty($contributionsArrayFields['contrib_zone']['checked'])) print '<td class="liste_titre"><input class="flat maxwidth100" type="text" name="search_contrib_zone" value="'.dol_escape_htmltag($searchContribZone).'"></td>';
	if (!empty($contributionsArrayFields['contrib_product']['checked'])) print '<td class="liste_titre"><input class="flat maxwidth150" type="text" name="search_contrib_product" value="'.dol_escape_htmltag($searchContribProduct).'"></td>';
	if (!empty($contributionsArrayFields['contrib_batch']['checked'])) print '<td class="liste_titre"><input class="flat maxwidth100" type="text" name="search_contrib_batch" value="'.dol_escape_htmltag($searchContribBatch).'"></td>';
	if (!empty($contributionsArrayFields['contrib_qty']['checked'])) print '<td class="liste_titre right"><input class="flat width75 right" type="text" name="search_contrib_qty" value="'.dol_escape_htmltag($searchContribQty).'"></td>';
	if (!$actionsOnLeft) print '<td class="liste_titre center maxwidthsearch">'.$form->showFilterButtons().'</td>';
	print '</tr>';
	print '<tr class="liste_titre">';
	if ($actionsOnLeft) print '<th class="center maxwidthsearch">'.$contributionsSelectedFields.'</th>';
	if (!empty($contributionsArrayFields['contrib_date']['checked'])) print '<th>'.$langs->trans('Date').'</th>';
	if (!empty($contributionsArrayFields['contrib_user']['checked'])) print '<th>'.$langs->trans('User').'</th>';
	if (!empty($contributionsArrayFields['contrib_zone']['checked'])) print '<th>'.$langs->trans('InventoryPlusCollaborativeZone').'</th>';
	if (!empty($contributionsArrayFields['contrib_product']['checked'])) print '<th>'.$langs->trans('Product').'</th>';
	if (!empty($contributionsArrayFields['contrib_batch']['checked'])) print '<th>'.$langs->trans('Batch').'</th>';
	if (!empty($contributionsArrayFields['contrib_qty']['checked'])) print '<th class="right">'.$langs->trans('Qty').'</th>';
	if (!$actionsOnLeft) print '<th class="center maxwidthsearch">'.$contributionsSelectedFields.'</th>';
	print '</tr>';
	$contributionsColumnCount = 1;
	foreach ($contributionsArrayFields as $field) if (!empty($field['checked'])) $contributionsColumnCount++;
	if (empty($contributions)) print '<tr><td colspan="'.$contributionsColumnCount.'" class="opacitymedium">'.$langs->trans('None').'</td></tr>';
	foreach ($contributions as $row) {
		print '<tr class="oddeven'.(!$row->active ? ' opacitymedium' : '').'">';
		$deleteAction = '';
		if ($campaignOpen && $row->active && ($canConsolidate || (int) $row->fk_user_author === (int) $user->id)) {
			$voidUrl = $_SERVER['PHP_SELF'].'?'.http_build_query(array_merge($navigationParameters, array('action' => 'confirm_voidcontribution', 'contribution_id' => (int) $row->rowid, 'token' => newToken())), '', '&');
			$deleteAction = '<a class="reposition" href="'.dol_escape_htmltag($voidUrl).'">'.img_delete().'</a>';
		}
		if ($actionsOnLeft) print '<td class="center">'.$deleteAction.'</td>';
		if (!empty($contributionsArrayFields['contrib_date']['checked'])) print '<td>'.dol_print_date($db->jdate($row->datec), 'dayhour').'</td>';
		if (!empty($contributionsArrayFields['contrib_user']['checked'])) print '<td>'.dol_escape_htmltag($row->login).'</td>';
		if (!empty($contributionsArrayFields['contrib_zone']['checked'])) print '<td>'.dol_escape_htmltag($row->zone).'</td>';
		if (!empty($contributionsArrayFields['contrib_product']['checked'])) print '<td>'.dol_escape_htmltag($row->ref.' - '.$row->label).'</td>';
		if (!empty($contributionsArrayFields['contrib_batch']['checked'])) print '<td>'.dol_escape_htmltag($row->batch).'</td>';
		if (!empty($contributionsArrayFields['contrib_qty']['checked'])) print '<td class="right">'.price($row->qty).(!$row->active ? ' ('.$langs->trans('Canceled').')' : '').'</td>';
		if (!$actionsOnLeft) print '<td class="center">'.$deleteAction.'</td>';
		print '</tr>';
	}
	print '</table></div></form>';
} else {
	print load_fiche_titre($langs->trans('InventoryPlusCollaborativeControl'), '', 'pdf');
	print '<div class="info">'.$langs->trans('InventoryPlusCollaborativeControlHelp').'</div>';
	if (!$controlStorageAvailable) {
		print '<div class="warning">'.$langs->trans('InventoryPlusCollaborativeControlStorageMissing').'</div>';
	}
	print '<div class="div-table-responsive">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><th>'.$langs->trans('InventoryPlusCollaborativeControlSequenceLabel').'</th><th>'.$langs->trans('Date').'</th><th>'.$langs->trans('Author').'</th><th class="right">'.$langs->trans('InventoryPlusCollaborativeScans').'</th><th>'.$langs->trans('InventoryPlusCollaborativeControlHash').'</th><th>'.$langs->trans('Status').'</th><th>'.$langs->trans('InventoryPlusCollaborativeController').'</th><th></th></tr>';
	if (!$controlReport) {
		print '<tr><td colspan="8" class="opacitymedium">'.$langs->trans('None').'</td></tr>';
	} else {
		$documentUrl = DOL_URL_ROOT.'/document.php?modulepart=movement&file='.urlencode($controlReport->file_path);
		$controller = (!empty($controlReport->approval_login) ? dol_escape_htmltag($controlReport->approval_login).' - '.dol_print_date($db->jdate($controlReport->date_approval), 'dayhour') : '');
		print '<tr class="oddeven"><td>'.((int) $controlReport->sequence).'</td><td>'.dol_print_date($db->jdate($controlReport->datec), 'dayhour').'</td><td>'.dol_escape_htmltag($controlReport->author_login).'</td><td class="right">'.((int) $controlReport->contribution_count).'</td><td><span class="small">'.dol_escape_htmltag(substr($controlReport->content_hash, 0, 16)).'...</span></td><td><span class="badge '.$controlStatusClass.'">'.$langs->trans($controlStatusLabel).'</span></td><td>'.$controller.'</td><td class="right"><a class="reposition" href="'.dol_escape_htmltag($documentUrl).'" target="_blank" rel="noopener">'.img_picto($langs->trans('Download'), 'pdf').'</a></td></tr>';
	}
	print '</table></div>';

	if ($campaignOpen && $controlStorageAvailable && ($canCount || $canConsolidate || $canControl)) {
		print '<div class="tabsAction">';
		print '<form class="inline-block" method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="buildcontrolpdf">';
		print '<input type="hidden" name="id" value="'.$inventoryId.'">';
		print '<input type="hidden" name="view" value="control">';
		print '<button class="butAction" type="submit">'.$langs->trans('InventoryPlusCollaborativeGenerateControl').'</button>';
		print '</form>';
		if ($canControl && $controlReport && (int) $controlReport->status === 0) {
			print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?id='.$inventoryId.'&view=control&action=confirm_approvecontrol&control_id='.((int) $controlReport->rowid).'&token='.newToken().'">'.$langs->trans('InventoryPlusCollaborativeControlApprove').'</a>';
		}
		print '</div>';
	}

	if ($canConsolidate && $campaignOpen) {
		print '<div class="tabsAction">';
		if ($controlReady) {
			print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?id='.$inventoryId.'&view=control&action=confirm_consolidate&token='.newToken().'">'.$langs->trans('InventoryPlusCollaborativeConsolidate').'</a>';
		} else {
			print '<span class="butActionRefused classfortooltip" title="'.dol_escape_htmltag($langs->trans('InventoryPlusCollaborativeControlRequired')).'">'.$langs->trans('InventoryPlusCollaborativeConsolidate').'</span>';
		}
		print '</div>';
	}
}

print dol_get_fiche_end();

print '<script>
jQuery(function() {
	var form = jQuery("#inventaireplus-count-form");
	var zone = jQuery("#inventaireplus-zone");
	var qty = jQuery("#inventaireplus-qty");
	var product = jQuery("#product_token");
	var productSearch = jQuery("#search_product_token");
	var productControl = productSearch.length ? productSearch : product;
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

	function validateAndSubmitContribution() {
		if (!zone[0].checkValidity()) {
			zone[0].reportValidity();
			zone.trigger("focus");
			return false;
		}
		var numericQty = Number(qty.val().replace(/\\s/g, "").replace(",", "."));
		if (!qty.val().trim() || !Number.isFinite(numericQty) || numericQty <= 0) {
			qty[0].setCustomValidity("'.dol_escape_js($langs->transnoentities('InventoryPlusCollaborativePositiveQtyRequired')).'");
			qty[0].reportValidity();
			qty.trigger("focus");
			return false;
		}
		if (!product.val()) {
			if (productControl.length && productControl[0].setCustomValidity) {
				productControl[0].setCustomValidity("'.dol_escape_js($langs->transnoentities('InventoryPlusCollaborativeProductRequired')).'");
				productControl[0].reportValidity();
				productControl.trigger("focus");
			}
			return false;
		}
		if (form[0].requestSubmit) {
			form[0].requestSubmit();
		} else {
			form[0].submit();
		}
		return true;
	}

	productControl.on("input change", function() {
		if (this.setCustomValidity) this.setCustomValidity("");
	});
	form.on("submit", function(event) {
		if (product.val()) return;
		event.preventDefault();
		if (productControl.length && productControl[0].setCustomValidity) {
			productControl[0].setCustomValidity("'.dol_escape_js($langs->transnoentities('InventoryPlusCollaborativeProductRequired')).'");
			productControl[0].reportValidity();
			productControl.trigger("focus");
		}
	});

	if (productSearch.length) {
		var scannerSubmitPending = false;
		productSearch[0].addEventListener("keydown", function(event) {
			if (event.key !== "Enter" && event.keyCode !== 13) return;
			if (scannerSubmitPending) return;
			scannerSubmitPending = true;
			var attempts = 0;
			var submitAfterNativeSelection = function() {
				if (product.val()) {
					scannerSubmitPending = false;
					validateAndSubmitContribution();
					return;
				}
				attempts++;
				if (attempts < 15) {
					window.setTimeout(submitAfterNativeSelection, 100);
				} else {
					scannerSubmitPending = false;
					validateAndSubmitContribution();
				}
			};
			window.setTimeout(submitAfterNativeSelection, 50);
		}, true);
	}

	if (!zone.val()) zone.trigger("focus");
	else if (!qty.val()) qty.trigger("focus");
	else productControl.trigger("focus");
});
</script>';

llxFooter();
$db->close();
