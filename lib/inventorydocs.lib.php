<?php
/* Copyright (C) 2026 Frédéric H Omega Junior <omegajunior.apps@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file        htdocs/custom/inventaireplus/lib/inventorydocs.lib.php
 * \brief       Shared helpers for custom inventory documents
 */

/**
 * Return a SQL subquery that resolves the retained category label for a product.
 * If several categories exist, the first label in alphabetical order is used.
 *
 * @param string $productAlias SQL alias of product table
 * @return string
 */
function inventaireplusGetInventoryCategoryLabelSubquery($productAlias = 'p')
{
	return "(SELECT c.label"
		." FROM ".MAIN_DB_PREFIX."categorie_product as cp"
		." INNER JOIN ".MAIN_DB_PREFIX."categorie as c ON c.rowid = cp.fk_categorie"
		." WHERE cp.fk_product = ".$productAlias.".rowid"
		." ORDER BY c.label ASC, c.rowid ASC"
		." LIMIT 1)";
}

/**
 * Fetch inventory header context used by custom documents.
 *
 * @param DoliDB $db Database handler
 * @param int    $inventoryId Inventory id
 * @return array<string,mixed>
 */
function inventaireplusFetchInventoryDocumentContext($db, $inventoryId)
{
	$inventoryId = (int) $inventoryId;
	$context = array(
		'inventory_id' => $inventoryId,
		'inventory_ref' => '',
		'inventory_title' => '',
		'inventory_status' => 0,
		'warehouse_id' => 0,
		'warehouse_ref' => '',
		'warehouse_label' => '',
		'date_validation' => null,
		'date_inventory' => null,
		'date_creation' => null,
		'date_update' => null,
		'document_date' => null,
	);

	if ($inventoryId <= 0) {
		return $context;
	}

	$sql = "SELECT i.rowid, i.ref, i.title, i.status, i.fk_warehouse, i.date_validation, i.date_inventory, i.date_creation, i.tms,";
	$sql .= " e.ref AS warehouse_ref, e.description AS warehouse_label";
	$sql .= " FROM ".MAIN_DB_PREFIX."inventory AS i";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."entrepot AS e ON e.rowid = i.fk_warehouse";
	$sql .= " WHERE i.rowid = ".$inventoryId;

	$resql = $db->query($sql);
	if (!$resql) {
		return $context;
	}

	$obj = $db->fetch_object($resql);
	if (!$obj) {
		return $context;
	}

	$context['inventory_ref'] = (!empty($obj->ref) ? (string) $obj->ref : 'INVENTORY-'.$inventoryId);
	$context['inventory_title'] = (string) $obj->title;
	$context['inventory_status'] = (int) $obj->status;
	$context['warehouse_id'] = (int) $obj->fk_warehouse;
	$context['warehouse_ref'] = (string) $obj->warehouse_ref;
	$context['warehouse_label'] = (!empty($obj->warehouse_label) ? (string) $obj->warehouse_label : (string) $obj->warehouse_ref);
	$context['date_validation'] = $obj->date_validation;
	$context['date_inventory'] = $obj->date_inventory;
	$context['date_creation'] = $obj->date_creation;
	$context['date_update'] = $obj->tms;
	$context['document_date'] = (!empty($obj->date_validation) ? $obj->date_validation : (!empty($obj->date_inventory) ? $obj->date_inventory : (!empty($obj->tms) ? $obj->tms : $obj->date_creation)));

	return $context;
}

/**
 * Fetch detailed lines for inventory custom documents.
 *
 * @param DoliDB $db Database handler
 * @param int    $inventoryId Inventory id
 * @param bool   $onlyDiscrepancies True to keep only lines with discrepancy
 * @return array<int,array<string,mixed>>
 */
function inventaireplusFetchInventoryDocumentLines($db, $inventoryId, $onlyDiscrepancies = false)
{
	$inventoryId = (int) $inventoryId;
	if ($inventoryId <= 0) {
		return array();
	}

	$lines = array();
	$categorySql = "COALESCE(".inventaireplusGetInventoryCategoryLabelSubquery('p').", 'Non classé')";

	$sql = "SELECT id.rowid, id.fk_inventory, id.fk_warehouse, id.fk_product, id.batch,";
	$sql .= " id.qty_stock, id.qty_view, id.qty_regulated, id.pmp_real, id.pmp_expected,";
	$sql .= " p.ref AS product_ref, p.label AS product_label,";
	$sql .= " ef.product_ref_snapshot, ef.product_label_snapshot, ef.category_label_snapshot,";
	$sql .= " ef.batch_snapshot, ef.eatby_snapshot, ef.sellby_snapshot, ef.justification_text,";
	$sql .= " ".$categorySql." AS category_label_default";
	$sql .= " FROM ".MAIN_DB_PREFIX."inventorydet AS id";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product AS p ON p.rowid = id.fk_product";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."inventorydet_extrafields AS ef ON ef.fk_object = id.rowid";
	$sql .= " WHERE id.fk_inventory = ".$inventoryId;
	$sql .= " ORDER BY id.rowid ASC";

	$resql = $db->query($sql);
	if (!$resql) {
		return $lines;
	}

	while ($obj = $db->fetch_object($resql)) {
		$qtyTheoretical = (float) price2num($obj->qty_stock, 'MS');
		$qtyPhysical = ($obj->qty_view === null ? null : (float) price2num($obj->qty_view, 'MS'));
		$qtyDelta = ($qtyPhysical === null ? null : (float) price2num($qtyPhysical - $qtyTheoretical, 'MS'));
		$hasDiscrepancy = ($qtyDelta !== null && (float) price2num($qtyDelta, 'MS') != 0.0);

		if ($onlyDiscrepancies && !$hasDiscrepancy) {
			continue;
		}

		$categoryLabel = (!empty($obj->category_label_snapshot) ? (string) $obj->category_label_snapshot : (string) $obj->category_label_default);
		if ($categoryLabel === '') {
			$categoryLabel = 'Non classé';
		}

		$lines[] = array(
			'rowid' => (int) $obj->rowid,
			'fk_inventory' => (int) $obj->fk_inventory,
			'fk_warehouse' => (int) $obj->fk_warehouse,
			'fk_product' => (int) $obj->fk_product,
			'product_ref' => (!empty($obj->product_ref_snapshot) ? (string) $obj->product_ref_snapshot : (string) $obj->product_ref),
			'product_label' => (!empty($obj->product_label_snapshot) ? (string) $obj->product_label_snapshot : (string) $obj->product_label),
			'batch' => (!empty($obj->batch_snapshot) ? (string) $obj->batch_snapshot : (string) $obj->batch),
			'eatby' => $obj->eatby_snapshot,
			'sellby' => $obj->sellby_snapshot,
			'category_label' => $categoryLabel,
			'qty_theoretical' => $qtyTheoretical,
			'qty_physical' => $qtyPhysical,
			'qty_delta' => $qtyDelta,
			'qty_regulated' => ($obj->qty_regulated === null ? null : (float) price2num($obj->qty_regulated, 'MS')),
			'pmp_real' => ($obj->pmp_real === null ? null : (float) price2num($obj->pmp_real, 'MU')),
			'pmp_expected' => ($obj->pmp_expected === null ? null : (float) price2num($obj->pmp_expected, 'MU')),
			'justification_text' => (string) $obj->justification_text,
			'has_discrepancy' => $hasDiscrepancy,
		);
	}

	usort($lines, function ($lineA, $lineB) {
		$categoryCompare = strcmp((string) $lineA['category_label'], (string) $lineB['category_label']);
		if ($categoryCompare !== 0) {
			return $categoryCompare;
		}
		$refCompare = strcmp((string) $lineA['product_ref'], (string) $lineB['product_ref']);
		if ($refCompare !== 0) {
			return $refCompare;
		}
		$labelCompare = strcmp((string) $lineA['product_label'], (string) $lineB['product_label']);
		if ($labelCompare !== 0) {
			return $labelCompare;
		}
		$batchCompare = strcmp((string) $lineA['batch'], (string) $lineB['batch']);
		if ($batchCompare !== 0) {
			return $batchCompare;
		}

		return ((int) $lineA['rowid'] <=> (int) $lineB['rowid']);
	});

	return $lines;
}

/**
 * Build grouped inventory dataset for custom documents.
 *
 * @param DoliDB $db Database handler
 * @param int    $inventoryId Inventory id
 * @param bool   $onlyDiscrepancies True to keep only lines with discrepancy
 * @return array<string,mixed>
 */
function inventaireplusBuildInventoryDocumentDataset($db, $inventoryId, $onlyDiscrepancies = false)
{
	$lines = inventaireplusFetchInventoryDocumentLines($db, $inventoryId, $onlyDiscrepancies);
	$dataset = array(
		'context' => inventaireplusFetchInventoryDocumentContext($db, $inventoryId),
		'lines' => $lines,
		'categories' => array(),
		'line_count' => 0,
		'has_discrepancies' => false,
		'total_theoretical' => 0.0,
		'total_physical' => 0.0,
		'total_delta' => 0.0,
	);

	foreach ($lines as $line) {
		$categoryLabel = (!empty($line['category_label']) ? $line['category_label'] : 'Non classé');
		if (!isset($dataset['categories'][$categoryLabel])) {
			$dataset['categories'][$categoryLabel] = array(
				'label' => $categoryLabel,
				'lines' => array(),
				'total_theoretical' => 0.0,
				'total_physical' => 0.0,
				'total_delta' => 0.0,
				'has_discrepancies' => false,
			);
		}

		$dataset['categories'][$categoryLabel]['lines'][] = $line;
		$dataset['categories'][$categoryLabel]['total_theoretical'] += (float) $line['qty_theoretical'];
		if ($line['qty_physical'] !== null) {
			$dataset['categories'][$categoryLabel]['total_physical'] += (float) $line['qty_physical'];
		}
		if ($line['qty_delta'] !== null) {
			$dataset['categories'][$categoryLabel]['total_delta'] += (float) $line['qty_delta'];
		}
		if (!empty($line['has_discrepancy'])) {
			$dataset['categories'][$categoryLabel]['has_discrepancies'] = true;
			$dataset['has_discrepancies'] = true;
		}

		$dataset['line_count']++;
		$dataset['total_theoretical'] += (float) $line['qty_theoretical'];
		if ($line['qty_physical'] !== null) {
			$dataset['total_physical'] += (float) $line['qty_physical'];
		}
		if ($line['qty_delta'] !== null) {
			$dataset['total_delta'] += (float) $line['qty_delta'];
		}
	}

	return $dataset;
}

