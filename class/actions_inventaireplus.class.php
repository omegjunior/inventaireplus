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

	protected function fetchStockMovementsForTransferPdf($movementIds = array(), $inventoryCode = '', $targetWarehouseId = 0)
	{
		$movementIds = array_values(array_filter(array_map('intval', (array) $movementIds)));
		$rows = array();
		if (empty($movementIds) && (empty($inventoryCode) || (int) $targetWarehouseId <= 0)) return $rows;

		$sql = "SELECT sm.rowid, sm.inventorycode, sm.fk_entrepot, sm.type_mouvement, sm.label, sm.datem, sm.fk_product, sm.batch, sm.eatby, sm.sellby, sm.value, sm.price,";
		$sql .= " p.ref AS product_ref, p.label AS product_label, p.tva_tx AS product_tva_tx,";
		$sql .= " ef.transfer_source, ef.transfer_target, ef.transfer_category_id, ef.transfer_category_label, ef.transfer_category_rank, ef.transfer_origin_type, ef.transfer_origin_id, ef.transfer_pdf_file, ef.transfer_pdf_generated_at";
		$sql .= " FROM ".MAIN_DB_PREFIX."stock_mouvement AS sm";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."stock_mouvement_extrafields AS ef ON ef.fk_object = sm.rowid";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product AS p ON p.rowid = sm.fk_product";
		if (!empty($movementIds)) {
			$sql .= " WHERE sm.rowid IN (".implode(',', $movementIds).")";
			$sql .= " ORDER BY sm.inventorycode ASC, CAST(COALESCE(NULLIF(ef.transfer_category_rank, ''), '999') AS SIGNED) ASC, ef.transfer_category_label ASC, sm.rowid ASC";
		} else {
			$sql .= " WHERE sm.inventorycode = '".$this->db->escape($inventoryCode)."'";
			$sql .= " AND sm.fk_entrepot = ".((int) $targetWarehouseId);
			$sql .= " AND sm.type_mouvement = 0";
			$sql .= " ORDER BY CAST(COALESCE(NULLIF(ef.transfer_category_rank, ''), '999') AS SIGNED) ASC, ef.transfer_category_label ASC, sm.rowid ASC";
		}
		$resql = $this->db->query($sql);
		if (!$resql) return $rows;
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = array(
				'rowid' => (int) $obj->rowid,
				'inventorycode' => (string) $obj->inventorycode,
				'fk_entrepot' => (int) $obj->fk_entrepot,
				'type_mouvement' => (int) $obj->type_mouvement,
				'label' => (string) $obj->label,
				'datem' => $obj->datem,
				'fk_product' => (int) $obj->fk_product,
				'batch' => (string) $obj->batch,
				'eatby' => $obj->eatby,
				'sellby' => $obj->sellby,
				'value' => $obj->value,
				'price' => $obj->price,
				'product_ref' => (string) $obj->product_ref,
				'product_label' => (string) $obj->product_label,
				'product_tva_tx' => (float) price2num($obj->product_tva_tx, 'MU'),
				'transfer_source' => (int) $obj->transfer_source,
				'transfer_target' => (int) $obj->transfer_target,
				'transfer_category_id' => (int) $obj->transfer_category_id,
				'transfer_category_label' => (string) $obj->transfer_category_label,
				'transfer_category_rank' => (int) $obj->transfer_category_rank,
				'transfer_origin_type' => (string) $obj->transfer_origin_type,
				'transfer_origin_id' => (int) $obj->transfer_origin_id,
				'transfer_pdf_file' => (string) $obj->transfer_pdf_file,
				'transfer_pdf_generated_at' => $obj->transfer_pdf_generated_at,
			);
		}
		$this->db->free($resql);
		return $rows;
	}

	protected function inferStockTransferCategorySnapshot($productId)
	{
		$sql = "SELECT cp.fk_categorie, c.label FROM ".MAIN_DB_PREFIX."categorie_product AS cp INNER JOIN ".MAIN_DB_PREFIX."categorie AS c ON c.rowid = cp.fk_categorie WHERE cp.fk_product = ".((int) $productId)." AND c.type = 0 AND c.entity IN (".getEntity('category').") ORDER BY c.label ASC, c.rowid ASC LIMIT 1";
		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			$this->db->free($resql);
			if ($obj) return array('id' => (int) $obj->fk_categorie, 'label' => (string) $obj->label);
		}
		return array('id' => 0, 'label' => 'Non classé');
	}

	protected function inferTransferSourceWarehouse($inventoryCode, $targetWarehouseId)
	{
		if (empty($inventoryCode) || (int) $targetWarehouseId <= 0) return 0;
		$sql = "SELECT sm.fk_entrepot FROM ".MAIN_DB_PREFIX."stock_mouvement AS sm WHERE sm.inventorycode = '".$this->db->escape($inventoryCode)."' AND sm.fk_entrepot <> ".((int) $targetWarehouseId)." AND sm.type_mouvement = 1 ORDER BY sm.rowid ASC LIMIT 1";
		$resql = $this->db->query($sql);
		if (!$resql) return 0;
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		return $obj ? (int) $obj->fk_entrepot : 0;
	}

	protected function completeStockTransferRowsForPdf(array &$rows)
	{
		$categoryCache = array();
		$sourceWarehouseCache = array();
		foreach ($rows as $key => $row) {
			$targetWarehouseId = (!empty($row['transfer_target']) ? (int) $row['transfer_target'] : (int) $row['fk_entrepot']);
			if ($targetWarehouseId > 0 && empty($rows[$key]['transfer_target'])) $rows[$key]['transfer_target'] = $targetWarehouseId;
			if (empty($rows[$key]['transfer_category_label'])) {
				$productId = (int) $row['fk_product'];
				if (!array_key_exists($productId, $categoryCache)) $categoryCache[$productId] = $this->inferStockTransferCategorySnapshot($productId);
				$rows[$key]['transfer_category_id'] = (int) $categoryCache[$productId]['id'];
				$rows[$key]['transfer_category_label'] = (string) $categoryCache[$productId]['label'];
				if (empty($rows[$key]['transfer_category_rank'])) $rows[$key]['transfer_category_rank'] = 999;
			}
			if (!empty($rows[$key]['transfer_source']) || empty($row['inventorycode']) || $targetWarehouseId <= 0) continue;
			$cacheKey = $row['inventorycode'].'|'.$targetWarehouseId;
			if (!array_key_exists($cacheKey, $sourceWarehouseCache)) $sourceWarehouseCache[$cacheKey] = $this->inferTransferSourceWarehouse($row['inventorycode'], $targetWarehouseId);
			if ((int) $sourceWarehouseCache[$cacheKey] > 0) $rows[$key]['transfer_source'] = (int) $sourceWarehouseCache[$cacheKey];
		}
	}

	protected function validateStockTransferPdfSelection($movementIds)
	{
		$result = array('ok' => false, 'message' => '', 'inventorycode' => '', 'sourcewarehouseid' => 0, 'targetwarehouseid' => 0);
		$movementIds = array_values(array_filter(array_map('intval', (array) $movementIds)));
		if (empty($movementIds)) { $result['message'] = 'Sélection vide pour la génération du bordereau de transfert.'; return $result; }
		$selectedRows = $this->fetchStockMovementsForTransferPdf($movementIds);
		$this->completeStockTransferRowsForPdf($selectedRows);
		if (empty($selectedRows)) { $result['message'] = 'Aucun mouvement de stock sélectionné n\'a été retrouvé.'; return $result; }
		$inventoryCodes = array(); $sourceWarehouseIds = array(); $targetWarehouseIds = array();
		foreach ($selectedRows as $selectedRow) {
			if (empty($selectedRow['inventorycode'])) { $result['message'] = 'Sélection invalide : au moins une ligne n\'a pas de code mouvement.'; return $result; }
			$targetWarehouseId = (!empty($selectedRow['transfer_target']) ? (int) $selectedRow['transfer_target'] : (int) $selectedRow['fk_entrepot']);
			$sourceWarehouseId = (int) $selectedRow['transfer_source'];
			if ($targetWarehouseId <= 0 || $sourceWarehouseId <= 0) { $result['message'] = 'Sélection invalide : métadonnées de transfert incomplètes sur au moins une ligne.'; return $result; }
			$inventoryCodes[$selectedRow['inventorycode']] = true; $sourceWarehouseIds[$sourceWarehouseId] = true; $targetWarehouseIds[$targetWarehouseId] = true;
		}
		if (count($inventoryCodes) !== 1 || count($sourceWarehouseIds) !== 1 || count($targetWarehouseIds) !== 1) { $result['message'] = 'La sélection doit porter sur un seul transfert source/cible.'; return $result; }
		$result['ok'] = true; $result['inventorycode'] = (string) key($inventoryCodes); $result['sourcewarehouseid'] = (int) key($sourceWarehouseIds); $result['targetwarehouseid'] = (int) key($targetWarehouseIds);
		return $result;
	}

	protected function validateWarehouseStockPdfSelection($warehouseIds)
	{
		$warehouseIds = array_values(array_unique(array_filter(array_map('intval', (array) $warehouseIds))));
		if (empty($warehouseIds)) return array('ok' => false, 'message' => 'Sélection vide pour la génération de l\'état du stock.');
		return array('ok' => true, 'warehouseids' => $warehouseIds, 'message' => '');
	}

	public function doMassActions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;
		if (empty($parameters['massaction'])) return 0;
		$langs->load('inventaireplus@inventaireplus');
		if ($parameters['massaction'] == 'builddocmovestockinventaireplus') {
			$toselect = (!empty($parameters['toselect']) && is_array($parameters['toselect']) ? $parameters['toselect'] : GETPOST('toselect', 'array'));
			$selectionCheck = $this->validateStockTransferPdfSelection($toselect);
			if (empty($selectionCheck['ok'])) { $this->errors[] = $selectionCheck['message']; return -1; }
			$allTransferRows = $this->fetchStockMovementsForTransferPdf(array(), $selectionCheck['inventorycode'], $selectionCheck['targetwarehouseid']);
			$this->completeStockTransferRowsForPdf($allTransferRows);
			if (empty($allTransferRows)) { $this->errors[] = 'Aucune ligne de transfert complète retrouvée pour générer le bordereau.'; return -1; }
			if (empty($parameters['diroutputmassaction'])) { $this->errors[] = 'Le répertoire de sortie massaction n\'est pas défini pour le bordereau de transfert.'; return -1; }
			require_once DOL_DOCUMENT_ROOT.'/custom/inventaireplus/core/modules/stock/doc/pdf_transfertstock.modules.php';
			$pdfParameters = array('inventorycode' => $selectionCheck['inventorycode'], 'sourcewarehouseid' => $selectionCheck['sourcewarehouseid'], 'targetwarehouseid' => $selectionCheck['targetwarehouseid'], 'movements' => $allTransferRows, 'selectedmovementids' => $toselect, 'diroutputmassaction' => $parameters['diroutputmassaction']);
			$firstTransferRow = reset($allTransferRows);
			if (!empty($firstTransferRow['transfer_origin_type'])) { $pdfParameters['transfer_origin_type'] = $firstTransferRow['transfer_origin_type']; $pdfParameters['transfer_origin_id'] = (int) $firstTransferRow['transfer_origin_id']; }
			$pdfModel = new pdf_transfertstock($this->db);
			$res = $pdfModel->write_file($pdfParameters, $this->getOutputLangs());
			if ($res > 0) {
				$generatedFile = (!empty($pdfModel->result['fullpath']) ? $pdfModel->result['fullpath'] : '');
				if (!empty($generatedFile)) { $generatedAt = $this->db->idate(dol_now()); foreach ($allTransferRows as $transferRow) { $sql = "UPDATE ".MAIN_DB_PREFIX."stock_mouvement_extrafields SET transfer_pdf_file = '".$this->db->escape($generatedFile)."', transfer_pdf_generated_at = '".$generatedAt."' WHERE fk_object = ".((int) $transferRow['rowid']); $this->db->query($sql); } }
				$this->resprints = $langs->trans('StockTransferSlip'); return 0;
			}
			$this->errors[] = (!empty($pdfModel->error) ? $pdfModel->error : 'La génération du bordereau de transfert a échoué.'); return -1;
		}
		if ($parameters['massaction'] == 'builddocwarehousevaluationinventaireplus') {
			$toselect = (!empty($parameters['toselect']) && is_array($parameters['toselect']) ? $parameters['toselect'] : GETPOST('toselect', 'array'));
			$selectionCheck = $this->validateWarehouseStockPdfSelection($toselect);
			if (empty($selectionCheck['ok'])) { $this->errors[] = $selectionCheck['message']; return -1; }
			if (empty($parameters['diroutputmassaction'])) { $this->errors[] = 'Le répertoire de sortie massaction n\'est pas défini pour l\'état du stock.'; return -1; }
			require_once DOL_DOCUMENT_ROOT.'/custom/inventaireplus/core/modules/stock/doc/pdf_valorisationstock.modules.php';
			$generatedCount = 0;
			foreach ($selectionCheck['warehouseids'] as $warehouseId) { $pdfModel = new pdf_valorisationstock($this->db); $res = $pdfModel->write_file(array('warehouseid' => (int) $warehouseId, 'diroutputmassaction' => $parameters['diroutputmassaction']), $this->getOutputLangs()); if ($res > 0) $generatedCount++; else $this->errors[] = (!empty($pdfModel->error) ? $pdfModel->error : 'La génération de l\'état du stock a échoué.'); }
			if ($generatedCount > 0 && empty($this->errors)) { $this->resprints = $langs->trans('WarehouseStockValuation'); return 0; }
			return -1;
		}
		return 0;
	}

	public function addMoreMassActions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;
		$langs->load('inventaireplus@inventaireplus');
		$this->resprints = '';
		if (!empty($parameters['currentcontext']) && in_array($parameters['currentcontext'], array('stockmovementlist', 'stockmovementlistInventairePlus'), true)) $this->resprints .= '<option value="builddocmovestockinventaireplus" data-html="'.dol_escape_htmltag(img_picto('', 'pdf', 'class="pictofixedwidth"').$langs->trans('GeneratePDFMoveStock')).'">'.$langs->trans('GeneratePDFMoveStock').'</option>';
		if (!empty($parameters['currentcontext']) && in_array($parameters['currentcontext'], array('stocklist', 'stocklistInventairePlus'), true)) $this->resprints .= '<option value="builddocwarehousevaluationinventaireplus" data-html="'.dol_escape_htmltag(img_picto('', 'pdf', 'class="pictofixedwidth"').$langs->trans('GenerateWarehouseValuationPDF')).'">'.$langs->trans('GenerateWarehouseValuationPDF').'</option>';
		return 0;
	}

	public function selectProductsListWhere($parameters, &$object, &$action, $hookmanager)
	{
		if (empty($parameters['currentcontext']) || !in_array($parameters['currentcontext'], array('massstockmoveinventaireplus', 'stockmovementlistInventairePlus'), true)) return 0;
		$warehouseId = ($parameters['currentcontext'] == 'massstockmoveinventaireplus') ? GETPOSTINT('id_sw') : GETPOSTINT('id');
		if ($warehouseId > 0) $this->resprints = ' AND EXISTS (SELECT 1 FROM '.MAIN_DB_PREFIX.'product_stock psw WHERE psw.fk_product = p.rowid AND psw.fk_entrepot = '.((int) $warehouseId).')';
		return 0;
	}
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;
		$langs->load('inventaireplus@inventaireplus');
		if (!empty($parameters['currentcontext']) && in_array($parameters['currentcontext'], array('receptioncard'), true)) {
			if (empty($object) || !is_object($object) || empty($object->id) || empty($object->element) || $object->element !== 'reception') return 0;
			if (!$user->hasRight('inventaireplus', 'transferreceptiontowarehouseinventaireplus', 'write')) return 0;
			$status = isset($object->status) ? (int) $object->status : -1;
			if ($status <= 0) return 0;
			$url = dol_buildpath('/custom/inventaireplus/product/stock/massstockmove.php', 1).'?init=1&action=fromreception&receptionid='.(int) $object->id;
			print '<a class="butAction" href="'.$url.'">'.$langs->trans('TransferReceptionToWarehouse').'</a>';
			return 0;
		}
		if (empty($parameters['currentcontext']) || !in_array($parameters['currentcontext'], array('inventorycard'), true)) return 0;
		if (empty($object) || !is_object($object) || empty($object->id) || empty($object->element) || $object->element !== 'inventory') return 0;
		if (!($user->hasRight('stock', 'inventory_advance', 'write') || $user->hasRight('stock', 'creer'))) return 0;

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
