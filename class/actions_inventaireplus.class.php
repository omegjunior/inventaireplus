<?php
/* Copyright (C) 2026 Frédéric H Omega Junior <omegajunior.apps@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    inventaireplus/class/actions_inventaireplus.class.php
 * \ingroup inventaireplus
 * \brief   Hooks for transverse inventory documents and discrepancy justifications.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';

class ActionsInventairePlus extends CommonHookActions
{
	public $db;
	public $error = '';
	public $errors = array();
	public $results = array();
	public $resprints;
	public $priority;

	public function __construct($db)
	{
		$this->db = $db;
	}

	protected function upsertInventoryDocumentExtraFields($inventoryId, array $values)
	{
		$inventoryId = (int) $inventoryId;
		if ($inventoryId <= 0) return false;

		$currentValues = array(
			'countsheet_generated_at' => null,
			'countsheet_file' => '',
			'discrepancy_pdf_generated_at' => null,
			'discrepancy_pdf_file' => '',
			'pv_generated_at' => null,
			'pv_file' => '',
		);

		$sql = "SELECT countsheet_generated_at, countsheet_file, discrepancy_pdf_generated_at, discrepancy_pdf_file, pv_generated_at, pv_file FROM ".MAIN_DB_PREFIX."inventory_extrafields WHERE fk_object = ".$inventoryId." LIMIT 1";
		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj) foreach ($currentValues as $key => $value) $currentValues[$key] = $obj->$key;
			$this->db->free($resql);
		}

		$merged = array_merge($currentValues, $values);
		$v = array();
		foreach (array('countsheet_generated_at', 'discrepancy_pdf_generated_at', 'pv_generated_at') as $field) $v[$field] = (!empty($merged[$field]) ? "'".$this->db->escape($merged[$field])."'" : 'NULL');
		foreach (array('countsheet_file', 'discrepancy_pdf_file', 'pv_file') as $field) $v[$field] = "'".$this->db->escape((string) $merged[$field])."'";

		$resqlCheck = $this->db->query("SELECT fk_object FROM ".MAIN_DB_PREFIX."inventory_extrafields WHERE fk_object = ".$inventoryId." LIMIT 1");
		if (!$resqlCheck) return false;
		$exists = ($this->db->fetch_object($resqlCheck) ? true : false);
		$this->db->free($resqlCheck);

		if ($exists) {
			$sql = "UPDATE ".MAIN_DB_PREFIX."inventory_extrafields SET countsheet_generated_at = ".$v['countsheet_generated_at'];
			$sql .= ", countsheet_file = ".$v['countsheet_file'];
			$sql .= ", discrepancy_pdf_generated_at = ".$v['discrepancy_pdf_generated_at'];
			$sql .= ", discrepancy_pdf_file = ".$v['discrepancy_pdf_file'];
			$sql .= ", pv_generated_at = ".$v['pv_generated_at'];
			$sql .= ", pv_file = ".$v['pv_file'];
			$sql .= " WHERE fk_object = ".$inventoryId;
		} else {
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."inventory_extrafields (fk_object, countsheet_generated_at, countsheet_file, discrepancy_pdf_generated_at, discrepancy_pdf_file, pv_generated_at, pv_file)";
			$sql .= " VALUES (".$inventoryId.", ".$v['countsheet_generated_at'].", ".$v['countsheet_file'].", ".$v['discrepancy_pdf_generated_at'].", ".$v['discrepancy_pdf_file'].", ".$v['pv_generated_at'].", ".$v['pv_file'].")";
		}

		return (bool) $this->db->query($sql);
	}

	protected function getOutputLangs()
	{
		global $conf, $langs;
		$outputlangs = $langs;
		$newlang = (getDolGlobalInt('MAIN_MULTILANGS') && GETPOST('lang_id', 'aZ09')) ? GETPOST('lang_id', 'aZ09') : '';
		if (!empty($newlang)) {
			$outputlangs = new Translate('', $conf);
			$outputlangs->setDefaultLang($newlang);
		}
		return $outputlangs;
	}

	protected function generateInventoryPdf($object, $modelFile, $modelClass, $generatedAtField, $fileField, $fallbackError)
	{
		global $conf, $langs;
		if (!is_readable($modelFile)) {
			setEventMessages($langs->trans('InventoryPlusPdfModelMissing'), null, 'errors');
			return -1;
		}
		require_once $modelFile;
		if (!class_exists($modelClass)) {
			setEventMessages($langs->trans('InventoryPlusPdfModelMissing'), null, 'errors');
			return -1;
		}

		$inventoryRefSafe = dol_sanitizeFileName(!empty($object->ref) ? $object->ref : 'inventory_'.$object->id);
		$stockDirOutput = (!empty($conf->stock->multidir_output[$conf->entity]) ? $conf->stock->multidir_output[$conf->entity] : $conf->stock->dir_output);
		if (empty($stockDirOutput)) {
			setEventMessages($langs->trans('InventoryPlusStockDocumentDirectoryMissing'), null, 'errors');
			return -1;
		}

		$pdfModel = new $modelClass($this->db);
		$res = $pdfModel->write_file(array('inventoryid' => (int) $object->id, 'diroutput' => $stockDirOutput.'/movement/inventaireplus/'.$inventoryRefSafe), $this->getOutputLangs());
		if ($res <= 0) {
			setEventMessages(!empty($pdfModel->error) ? $pdfModel->error : $fallbackError, null, 'errors');
			return -1;
		}

		$relativeFile = (!empty($pdfModel->result['relativefile']) ? 'inventaireplus/'.$pdfModel->result['relativefile'] : '');
		if (empty($relativeFile)) {
			setEventMessages($langs->trans('InventoryPlusGeneratedPdfPathMissing'), null, 'errors');
			return -1;
		}

		if (!$this->upsertInventoryDocumentExtraFields((int) $object->id, array($generatedAtField => $this->db->idate(dol_now()), $fileField => $relativeFile))) {
			setEventMessages($langs->trans('InventoryPlusInventoryExtraFieldsUpdateWarning'), null, 'warnings');
		}

		header('Location: '.DOL_URL_ROOT.'/document.php?modulepart=movement&file='.urlencode($relativeFile));
		exit;
	}

	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;
		if (empty($parameters['currentcontext']) || !in_array($parameters['currentcontext'], array('inventorycard'), true)) return 0;
		$langs->loadLangs(array('stocks', 'inventaireplus@inventaireplus'));

		$managedActions = array('buildcountsheetinventaireplus', 'builddiscrepanciespdfinventaireplus', 'buildinventoryminutesinventaireplus');
		if (!in_array($action, $managedActions, true)) return 0;

		$permissionInventoryWrite = ($user->hasRight('stock', 'inventory_advance', 'write') || $user->hasRight('stock', 'creer'));
		if (!$permissionInventoryWrite) {
			setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
			return -1;
		}
		if (empty($object) || !is_object($object) || empty($object->id) || empty($object->element) || $object->element !== 'inventory') {
			setEventMessages($langs->trans('InventoryPlusInvalidInventory'), null, 'errors');
			return -1;
		}

		require_once DOL_DOCUMENT_ROOT.'/custom/inventaireplus/lib/inventorydocs.lib.php';

		if ($action === 'buildcountsheetinventaireplus') {
			if ((int) $object->status !== (int) $object::STATUS_VALIDATED) {
				setEventMessages($langs->trans('InventoryPlusCountSheetOnlyForValidatedInventory'), null, 'errors');
				return -1;
			}
			return $this->generateInventoryPdf($object, DOL_DOCUMENT_ROOT.'/custom/inventaireplus/core/modules/inventory/doc/pdf_fichedecompte.modules.php', 'pdf_fichedecompte', 'countsheet_generated_at', 'countsheet_file', $langs->trans('InventoryPlusCountSheetGenerationFailed'));
		}

		if ($action === 'builddiscrepanciespdfinventaireplus') {
			if (!in_array((int) $object->status, array((int) $object::STATUS_VALIDATED, (int) $object::STATUS_RECORDED), true)) {
				setEventMessages($langs->trans('InventoryPlusDiscrepancyReportOnlyForValidatedOrRecordedInventory'), null, 'errors');
				return -1;
			}
			$dataset = inventaireplusBuildInventoryDocumentDataset($this->db, (int) $object->id, true);
			if (empty($dataset['lines'])) {
				setEventMessages($langs->trans('NoInventoryDiscrepancy'), null, 'warnings');
				return 0;
			}
			return $this->generateInventoryPdf($object, DOL_DOCUMENT_ROOT.'/custom/inventaireplus/core/modules/inventory/doc/pdf_pointdecarts.modules.php', 'pdf_pointdecarts', 'discrepancy_pdf_generated_at', 'discrepancy_pdf_file', $langs->trans('InventoryPlusDiscrepancyReportGenerationFailed'));
		}

		if ((int) $object->status !== (int) $object::STATUS_RECORDED) {
			setEventMessages($langs->trans('InventoryPlusMinutesOnlyForRecordedInventory'), null, 'errors');
			return -1;
		}
		$dataset = inventaireplusBuildInventoryDocumentDataset($this->db, (int) $object->id, false);
		if (empty($dataset['lines'])) {
			setEventMessages($langs->trans('InventoryPlusNoLineForMinutes'), null, 'warnings');
			return 0;
		}
		return $this->generateInventoryPdf($object, DOL_DOCUMENT_ROOT.'/custom/inventaireplus/core/modules/inventory/doc/pdf_pvinventaire.modules.php', 'pdf_pvinventaire', 'pv_generated_at', 'pv_file', $langs->trans('InventoryPlusMinutesGenerationFailed'));
	}

	public function formDolBanner($parameters, &$object, &$action, $hookmanager)
	{
		if (empty($parameters['currentcontext']) || !in_array($parameters['currentcontext'], array('inventorycard'), true)) return 0;
		if (empty($object) || !is_object($object) || empty($object->element) || $object->element !== 'inventory') return 0;

		$existingMoreParam = (!empty($parameters['moreparam']) && is_string($parameters['moreparam'])) ? ltrim($parameters['moreparam'], '&') : '';
		$queryParams = array();
		if ($existingMoreParam !== '') parse_str($existingMoreParam, $queryParams);
		$queryParams['sortfield'] = 'id.rowid';
		$queryParams['sortorder'] = 'ASC';
		$parameters['moreparam'] = '&'.http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
		return 0;
	}

	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;
		if (empty($parameters['currentcontext']) || !in_array($parameters['currentcontext'], array('inventorycard'), true)) return 0;
		if (empty($object) || !is_object($object) || empty($object->id) || empty($object->element) || $object->element !== 'inventory') return 0;
		if (!($user->hasRight('stock', 'inventory_advance', 'write') || $user->hasRight('stock', 'creer'))) return 0;

		$langs->load('inventaireplus@inventaireplus');
		$status = isset($object->status) ? (int) $object->status : -1;
		$currentPage = $_SERVER['PHP_SELF'];

		if ($status === (int) $object::STATUS_VALIDATED) {
			print '<a class="butAction" href="'.$currentPage.'?id='.(int) $object->id.'&action=buildcountsheetinventaireplus">'.$langs->trans('PrintInventoryCountSheet').'</a>';
			print '<a class="butAction" href="'.$currentPage.'?id='.(int) $object->id.'&action=builddiscrepanciespdfinventaireplus">'.$langs->trans('GenerateInventoryDiscrepancyReport').'</a>';
			print '<a class="butAction" href="'.dol_buildpath('/custom/inventaireplus/product/inventory/discrepancies.php', 1).'?id='.(int) $object->id.'&mainmenu=products">'.$langs->trans('EditInventoryJustifications').'</a>';
		}
		if ($status === (int) $object::STATUS_RECORDED) {
			print '<a class="butAction" href="'.$currentPage.'?id='.(int) $object->id.'&action=buildinventoryminutesinventaireplus">'.$langs->trans('GenerateInventoryMinutes').'</a>';
		}
		return 0;
	}
}
