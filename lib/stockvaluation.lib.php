<?php
/* Copyright (C) 2026 Frédéric H Omega Junior <omegajunior.apps@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file        htdocs/custom/inventaireplus/lib/stockvaluation.lib.php
 * \brief       Shared helpers for stock valuation queries and documents
 */

require_once DOL_DOCUMENT_ROOT.'/custom/inventaireplus/lib/productcategory.lib.php';

/**
 * Return shared SQL fragments used to value stock quantities.
 *
 * @param Conf $conf Dolibarr conf
 * @return array<string,mixed>
 */
function inventaireplusGetWarehouseValuationSqlParts($conf)
{
	$separatedPMP = false;
	if (getDolGlobalString('MULTICOMPANY_PRODUCT_SHARING_ENABLED') && getDolGlobalString('MULTICOMPANY_PMP_PER_ENTITY_ENABLED')) {
		$separatedPMP = true;
	}

	$purchaseUnitField = ($separatedPMP ? 'pa.pmp' : 'p.pmp');

	return array(
		'separatedPMP' => $separatedPMP,
		'purchase_unit_field' => $purchaseUnitField,
		'sell_unit_field' => 'p.price',
		'select' => "SUM(".$purchaseUnitField." * ps.reel) as estimatedvalue, SUM(p.price * ps.reel) as sellvalue, SUM(ps.reel) as stockqty",
		'join' => ($separatedPMP ? " LEFT JOIN ".MAIN_DB_PREFIX."product_perentity as pa ON pa.fk_product = p.rowid AND pa.fk_product = ps.fk_product AND pa.entity = ".((int) $conf->entity) : ''),
	);
}

/**
 * Fetch detailed stock valuation rows for one warehouse.
 *
 * @param DoliDB $db Database handler
 * @param Conf   $conf Dolibarr conf
 * @param int    $warehouseId Warehouse id
 * @return array<int, array<string, mixed>>
 */
function inventaireplusFetchWarehouseValuationRows($db, $conf, $warehouseId)
{
	$warehouseId = (int) $warehouseId;
	if ($warehouseId <= 0) {
		return array();
	}

	$rows = array();
	$sqlParts = inventaireplusGetWarehouseValuationSqlParts($conf);
	$categoryIdSql = "COALESCE(".inventaireplusGetProductCategoryIdSubquery('p').", 0)";
	$categorySql = "COALESCE(".inventaireplusGetProductCategoryLabelSubquery('p').", 'Non classé')";
	$purchaseUnitField = $sqlParts['purchase_unit_field'];
	$sellUnitField = $sqlParts['sell_unit_field'];
	$joinPerEntity = (!empty($sqlParts['join']) ? $sqlParts['join'] : '');

	$sql = "SELECT p.rowid AS product_id, p.ref AS product_ref, p.label AS product_label,";
	$sql .= " '' AS batch, NULL AS eatby, NULL AS sellby,";
	$sql .= " ps.reel AS qty,";
	$sql .= " ".$purchaseUnitField." AS purchase_unit_value,";
	$sql .= " ".$sellUnitField." AS sell_unit_value,";
	$sql .= " (ps.reel * ".$purchaseUnitField.") AS purchase_total_value,";
	$sql .= " (ps.reel * ".$sellUnitField.") AS sell_total_value,";
	$sql .= " ".$categoryIdSql." AS category_id,";
	$sql .= " ".$categorySql." AS category_label";
	$sql .= " FROM ".MAIN_DB_PREFIX."product_stock AS ps";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product AS p ON p.rowid = ps.fk_product";
	$sql .= $joinPerEntity;
	$sql .= " WHERE ps.fk_entrepot = ".$warehouseId;
	$sql .= " AND ps.reel <> 0";
	if (isModEnabled('productbatch')) {
		$sql .= " AND COALESCE(p.tobatch, 0) = 0";
	}

	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$rows[] = array(
				'product_id' => (int) $obj->product_id,
				'product_ref' => (string) $obj->product_ref,
				'product_label' => (string) $obj->product_label,
				'batch' => '',
				'eatby' => null,
				'sellby' => null,
				'qty' => (float) price2num($obj->qty, 'MS'),
				'purchase_unit_value' => (float) $obj->purchase_unit_value,
				'sell_unit_value' => (float) $obj->sell_unit_value,
				'purchase_total_value' => (float) $obj->purchase_total_value,
				'sell_total_value' => (float) $obj->sell_total_value,
				'category_id' => (int) $obj->category_id,
				'category_label' => (string) $obj->category_label,
			);
		}
	}

	if (isModEnabled('productbatch')) {
		$sql = "SELECT p.rowid AS product_id, p.ref AS product_ref, p.label AS product_label,";
		$sql .= " pb.batch, pl.eatby, pl.sellby, pb.qty AS qty,";
		$sql .= " ".$purchaseUnitField." AS purchase_unit_value,";
		$sql .= " ".$sellUnitField." AS sell_unit_value,";
		$sql .= " (pb.qty * ".$purchaseUnitField.") AS purchase_total_value,";
		$sql .= " (pb.qty * ".$sellUnitField.") AS sell_total_value,";
		$sql .= " ".$categoryIdSql." AS category_id,";
		$sql .= " ".$categorySql." AS category_label";
		$sql .= " FROM ".MAIN_DB_PREFIX."product_stock AS ps";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product AS p ON p.rowid = ps.fk_product";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_batch AS pb ON pb.fk_product_stock = ps.rowid";
		//let join with product_lot to be able to get correct values of eatby and sellby columns, which are not inserted on product_batch table.
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_lot AS pl ON pl.batch = pb.batch AND pl.fk_product = ps.fk_product";
		$sql .= $joinPerEntity;
		$sql .= " WHERE ps.fk_entrepot = ".$warehouseId;
		$sql .= " AND pb.qty <> 0";
		$sql .= " AND COALESCE(p.tobatch, 0) > 0";

		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$rows[] = array(
					'product_id' => (int) $obj->product_id,
					'product_ref' => (string) $obj->product_ref,
					'product_label' => (string) $obj->product_label,
					'batch' => (string) $obj->batch,
					'eatby' => $obj->eatby,
					'sellby' => $obj->sellby,
					'qty' => (float) price2num($obj->qty, 'MS'),
					'purchase_unit_value' => (float) $obj->purchase_unit_value,
					'sell_unit_value' => (float) $obj->sell_unit_value,
					'purchase_total_value' => (float) $obj->purchase_total_value,
					'sell_total_value' => (float) $obj->sell_total_value,
					'category_id' => (int) $obj->category_id,
					'category_label' => (string) $obj->category_label,
				);
			}
		}
	}

	usort($rows, function ($rowA, $rowB) {
		$categoryCompare = strcmp((string) $rowA['category_label'], (string) $rowB['category_label']);
		if ($categoryCompare !== 0) {
			return $categoryCompare;
		}
		$refCompare = strcmp((string) $rowA['product_ref'], (string) $rowB['product_ref']);
		if ($refCompare !== 0) {
			return $refCompare;
		}
		$labelCompare = strcmp((string) $rowA['product_label'], (string) $rowB['product_label']);
		if ($labelCompare !== 0) {
			return $labelCompare;
		}
		$batchCompare = strcmp((string) $rowA['batch'], (string) $rowB['batch']);
		if ($batchCompare !== 0) {
			return $batchCompare;
		}

		return strcmp((string) $rowA['sellby'], (string) $rowB['sellby']);
	});

	return $rows;
}

/**
 * Build grouped stock valuation dataset for one warehouse.
 *
 * @param DoliDB $db Database handler
 * @param Conf   $conf Dolibarr conf
 * @param int    $warehouseId Warehouse id
 * @return array<string, mixed>
 */
function inventaireplusBuildWarehouseValuationDataset($db, $conf, $warehouseId)
{
	$rows = inventaireplusFetchWarehouseValuationRows($db, $conf, $warehouseId);
	$dataset = array(
		'rows' => $rows,
		'categories' => array(),
		'total_purchase' => 0.0,
		'total_sell' => 0.0,
		'total_qty' => 0.0,
	);

	foreach ($rows as $row) {
		$categoryLabel = (!empty($row['category_label']) ? $row['category_label'] : 'Non classé');
		$categoryNodes = inventaireplusGetCategoryPathNodes($db, (int) ($row['category_id'] ?? 0), $categoryLabel);
		$leafKey = $categoryNodes[count($categoryNodes) - 1]['key'];
		foreach ($categoryNodes as $node) {
			if (!isset($dataset['categories'][$node['key']])) {
				$dataset['categories'][$node['key']] = array(
					'label' => $node['label'],
					'level' => (int) $node['level'],
					'sort' => $node['sort'],
					'rows' => array(),
					'total_purchase' => 0.0,
					'total_sell' => 0.0,
					'total_qty' => 0.0,
				);
			}

			if ($node['key'] === $leafKey) {
				$dataset['categories'][$node['key']]['rows'][] = $row;
			}
			$dataset['categories'][$node['key']]['total_purchase'] += (float) $row['purchase_total_value'];
			$dataset['categories'][$node['key']]['total_sell'] += (float) $row['sell_total_value'];
			$dataset['categories'][$node['key']]['total_qty'] += (float) $row['qty'];
		}
		$dataset['total_purchase'] += (float) $row['purchase_total_value'];
		$dataset['total_sell'] += (float) $row['sell_total_value'];
		$dataset['total_qty'] += (float) $row['qty'];
	}
	uasort($dataset['categories'], 'inventaireplusSortCategoryAggregates');

	return $dataset;
}

