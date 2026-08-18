<?php
/* Copyright (C) 2026 Omega Junior
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file        htdocs/custom/inventaireplus/lib/inventoryselection.lib.php
 * \ingroup     inventaireplus
 * \brief       Helpers for custom inventory product selections.
 */

require_once DOL_DOCUMENT_ROOT.'/product/inventory/class/inventory.class.php';

/**
 * Parse user-defined product tokens.
 *
 * @param string $raw Raw textarea value
 * @return array<int,string>
 */
function inventaireplusParseProductSelectionTokens($raw)
{
	$tokens = preg_split('/[\r\n,;]+/', (string) $raw);
	$result = array();
	foreach ($tokens as $token) {
		$token = trim($token);
		if ($token !== '') {
			$result[$token] = $token;
		}
	}

	return array_values($result);
}

/**
 * Resolve product ids from ids, refs or barcodes.
 *
 * @param DoliDB $db Database handler
 * @param array<int,string> $tokens User tokens
 * @return array<string,mixed>
 */
function inventaireplusResolveProductSelection($db, array $tokens)
{
	$result = array('ids' => array(), 'unresolved' => array());
	if (empty($tokens)) {
		return $result;
	}

	$numericIds = array();
	$textTokens = array();
	foreach ($tokens as $token) {
		if (preg_match('/^[0-9]+$/', $token)) {
			$numericIds[] = (int) $token;
		}
		$textTokens[] = $token;
	}

	$conditions = array();
	if (!empty($numericIds)) {
		$conditions[] = 'p.rowid IN ('.implode(',', array_unique($numericIds)).')';
	}
	$escapedTokens = array();
	foreach ($textTokens as $token) {
		$escapedTokens[] = "'".$db->escape($token)."'";
	}
	if (!empty($escapedTokens)) {
		$conditions[] = 'p.ref IN ('.implode(',', $escapedTokens).')';
		$conditions[] = 'p.barcode IN ('.implode(',', $escapedTokens).')';
	}

	$sql = "SELECT p.rowid, p.ref, p.barcode";
	$sql .= " FROM ".MAIN_DB_PREFIX."product AS p";
	$sql .= " WHERE p.entity IN (".getEntity('product').")";
	if (!getDolGlobalString('STOCK_SUPPORTS_SERVICES')) {
		$sql .= " AND p.fk_product_type = 0";
	}
	$sql .= " AND (".implode(' OR ', $conditions).")";

	$foundTokens = array();
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$result['ids'][(int) $obj->rowid] = (int) $obj->rowid;
			$foundTokens[(string) $obj->rowid] = true;
			$foundTokens[(string) $obj->ref] = true;
			if (!empty($obj->barcode)) {
				$foundTokens[(string) $obj->barcode] = true;
			}
		}
		$db->free($resql);
	}

	foreach ($tokens as $token) {
		if (empty($foundTokens[$token])) {
			$result['unresolved'][] = $token;
		}
	}

	return $result;
}

/**
 * Fetch labels for selected products.
 *
 * @param DoliDB $db Database handler
 * @param array<int,int> $productIds Product ids
 * @return array<int,string>
 */
function inventaireplusFetchProductSelectionLabels($db, array $productIds)
{
	$productIds = array_values(array_unique(array_map('intval', $productIds)));
	$productIds = array_filter($productIds, function ($value) { return $value > 0; });
	if (empty($productIds)) {
		return array();
	}

	$labels = array();
	$sql = "SELECT p.rowid, p.ref, p.label";
	$sql .= " FROM ".MAIN_DB_PREFIX."product AS p";
	$sql .= " WHERE p.entity IN (".getEntity('product').")";
	$sql .= " AND p.rowid IN (".implode(',', $productIds).")";
	$sql .= " ORDER BY p.ref ASC";

	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$labels[(int) $obj->rowid] = (string) $obj->ref.($obj->label !== '' ? ' - '.(string) $obj->label : '');
		}
		$db->free($resql);
	}

	return $labels;
}

/**
 * Resolve products linked to selected product categories.
 *
 * @param DoliDB $db Database handler
 * @param array<int,int> $categoryIds Selected category ids
 * @param bool $includeChildren Include products from child categories
 * @return array<int,int>
 */
function inventaireplusResolveProductCategorySelection($db, array $categoryIds, $includeChildren = true)
{
	$categoryIds = array_values(array_unique(array_map('intval', $categoryIds)));
	$categoryIds = array_filter($categoryIds, function ($value) { return $value > 0; });
	if (empty($categoryIds)) {
		return array();
	}

	$allCategoryIds = array_values($categoryIds);
	if ($includeChildren) {
		$pending = $allCategoryIds;
		while (!empty($pending)) {
			$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'categorie';
			$sql .= ' WHERE type = 0 AND fk_parent IN ('.$db->sanitize(implode(',', $pending)).')';
			$resql = $db->query($sql);
			if (!$resql) {
				break;
			}

			$pending = array();
			while ($obj = $db->fetch_object($resql)) {
				$categoryId = (int) $obj->rowid;
				if (!in_array($categoryId, $allCategoryIds, true)) {
					$allCategoryIds[] = $categoryId;
					$pending[] = $categoryId;
				}
			}
			$db->free($resql);
		}
	}

	$productIds = array();
	$sql = 'SELECT DISTINCT cp.fk_product';
	$sql .= ' FROM '.MAIN_DB_PREFIX.'categorie_product as cp';
	$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product as p ON p.rowid = cp.fk_product';
	$sql .= ' WHERE cp.fk_categorie IN ('.$db->sanitize(implode(',', $allCategoryIds)).')';
	$sql .= ' AND p.entity IN ('.getEntity('product').')';
	$sql .= ' ORDER BY p.ref ASC, p.label ASC';
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$productIds[] = (int) $obj->fk_product;
		}
		$db->free($resql);
	}

	return $productIds;
}

/**
 * Ensure the native stock cache can find a warehouse line for selected products.
 *
 * Native inventory closing reads Product::stock_warehouse[$warehouse]->real directly.
 * A product selected for inventory with no previous stock row in the warehouse must
 * therefore have a zero product_stock row before the native close action runs.
 *
 * @param DoliDB $db Database handler
 * @param int $warehouseId Warehouse id
 * @param array<int,int> $productIds Product ids
 * @return int Number of inserted stock rows, <0 on error
 */
function inventaireplusEnsureProductStockRows($db, $warehouseId, array $productIds)
{
	$warehouseId = (int) $warehouseId;
	$productIds = array_values(array_unique(array_map('intval', $productIds)));
	$productIds = array_filter($productIds, function ($value) { return $value > 0; });
	if ($warehouseId <= 0 || empty($productIds)) {
		return 0;
	}

	$existing = array();
	$sql = 'SELECT fk_product FROM '.MAIN_DB_PREFIX.'product_stock';
	$sql .= ' WHERE fk_entrepot = '.$warehouseId;
	$sql .= ' AND fk_product IN ('.implode(',', $productIds).')';
	$resql = $db->query($sql);
	if (!$resql) {
		return -1;
	}
	while ($obj = $db->fetch_object($resql)) {
		$existing[(int) $obj->fk_product] = true;
	}
	$db->free($resql);

	$inserted = 0;
	foreach ($productIds as $productId) {
		if (!empty($existing[(int) $productId])) {
			continue;
		}
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'product_stock (fk_product, fk_entrepot, reel)';
		$sql .= ' VALUES ('.((int) $productId).', '.$warehouseId.', 0)';
		$resql = $db->query($sql);
		if (!$resql) {
			return -1;
		}
		$inserted++;
	}

	return $inserted;
}

/**
 * Fetch inventory lines to create for selected products.
 *
 * @param DoliDB $db Database handler
 * @param array<int,int> $productIds Product ids
 * @param int $warehouseId Warehouse id
 * @return array<int,array<string,mixed>>
 */
function inventaireplusFetchSelectedInventoryLines($db, array $productIds, $warehouseId)
{
	$productIds = array_values(array_unique(array_map('intval', $productIds)));
	$productIds = array_filter($productIds, function ($value) { return $value > 0; });
	if (empty($productIds) || (int) $warehouseId <= 0) {
		return array();
	}

	$lines = array();
	if (isModEnabled('productbatch')) {
		$sql = "SELECT p.rowid AS fk_product, p.ref, p.tobatch, ps.fk_entrepot AS fk_warehouse, ps.reel, pb.batch, pb.qty";
		$sql .= " FROM ".MAIN_DB_PREFIX."product AS p";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_stock AS ps ON ps.fk_product = p.rowid AND ps.fk_entrepot = ".((int) $warehouseId);
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_batch AS pb ON pb.fk_product_stock = ps.rowid";
		$sql .= " WHERE p.rowid IN (".implode(',', $productIds).")";
		$sql .= " AND COALESCE(p.tobatch, 0) > 0";
		$sql .= " ORDER BY p.ref ASC, pb.batch ASC";
		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$lines[] = array(
					'fk_warehouse' => (int) $obj->fk_warehouse,
					'fk_product' => (int) $obj->fk_product,
					'batch' => (string) $obj->batch,
					'qty_stock' => (float) price2num($obj->qty, 'MS'),
				);
			}
			$db->free($resql);
		}
	}

	$sql = "SELECT p.rowid AS fk_product, p.ref, COALESCE(p.tobatch, 0) AS tobatch, COALESCE(ps.reel, 0) AS reel";
	$sql .= " FROM ".MAIN_DB_PREFIX."product AS p";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_stock AS ps ON ps.fk_product = p.rowid AND ps.fk_entrepot = ".((int) $warehouseId);
	$sql .= " WHERE p.rowid IN (".implode(',', $productIds).")";
	if (isModEnabled('productbatch')) {
		$sql .= " AND COALESCE(p.tobatch, 0) = 0";
	}
	$sql .= " ORDER BY p.ref ASC";

	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$lines[] = array(
				'fk_warehouse' => (int) $warehouseId,
				'fk_product' => (int) $obj->fk_product,
				'batch' => '',
				'qty_stock' => (float) price2num($obj->reel, 'MS'),
			);
		}
		$db->free($resql);
	}

	return $lines;
}

/**
 * Create one native inventory with selected lines.
 *
 * @param DoliDB $db Database handler
 * @param User $user Current user
 * @param string $ref Inventory ref
 * @param string $title Inventory title
 * @param int $warehouseId Warehouse id
 * @param int $dateInventory Inventory date
 * @param array<int,array<string,mixed>> $lines Lines
 * @return int New inventory id, <0 on error
 */
function inventaireplusCreateInventoryFromSelection($db, $user, $ref, $title, $warehouseId, $dateInventory, array $lines)
{
	$inventory = new Inventory($db);
	$inventory->ref = $ref;
	$inventory->title = $title;
	$inventory->fk_warehouse = (int) $warehouseId;
	$inventory->fk_product = 0;
	$inventory->categories_product = '';
	if (!empty($dateInventory)) {
		$inventory->date_inventory = $dateInventory;
	}

	$db->begin();
	$result = $inventory->create($user);
	if ($result <= 0) {
		$db->rollback();
		return -1;
	}

	$inventoryId = (int) $inventory->id;
	$productIds = array();
	foreach ($lines as $line) {
		$productIds[] = (int) $line['fk_product'];
	}
	$resultStockRows = inventaireplusEnsureProductStockRows($db, $warehouseId, $productIds);
	if ($resultStockRows < 0) {
		$db->rollback();
		return -4;
	}

	foreach ($lines as $line) {
		$inventoryLine = new InventoryLine($db);
		$inventoryLine->fk_inventory = $inventoryId;
		$inventoryLine->fk_warehouse = (int) $line['fk_warehouse'];
		$inventoryLine->fk_product = (int) $line['fk_product'];
		$inventoryLine->batch = (string) $line['batch'];
		$inventoryLine->datec = dol_now();
		$inventoryLine->qty_stock = (float) $line['qty_stock'];
		$inventoryLine->qty_view = null;
		$resultLine = $inventoryLine->create($user);
		if ($resultLine <= 0) {
			$db->rollback();
			return -2;
		}
	}

	$resultStatus = $inventory->setStatut($inventory::STATUS_VALIDATED, null, '', 'INVENTORY_VALIDATED');
	if ($resultStatus <= 0) {
		$db->rollback();
		return -3;
	}

	$db->commit();
	return $inventoryId;
}
