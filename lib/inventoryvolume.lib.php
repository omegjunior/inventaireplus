<?php
/* Copyright (C) 2026 Omega Junior
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file        htdocs/custom/inventaireplus/lib/inventoryvolume.lib.php
 * \ingroup     inventaireplus
 * \brief       Server-side actions for large native inventories.
 */

require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/inventory/class/inventory.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/mouvementstock.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/inventaireplus/lib/inventoryselection.lib.php';

/**
 * Check rights for optimized inventory actions.
 *
 * @param User $user Current user
 * @return bool
 */
function inventaireplusCanUseOptimizedInventoryActions($user)
{
	return (($user->hasRight('stock', 'inventory_advance', 'write') || $user->hasRight('stock', 'mouvement', 'creer')) && $user->hasRight('inventaireplus', 'largeinventory', 'write'));
}

/**
 * Lock and validate an inventory before a large action.
 *
 * @param DoliDB $db Database handler
 * @param int $inventoryId Inventory id
 * @return array<string,mixed>|null
 */
function inventaireplusLockValidatedInventory($db, $inventoryId)
{
	$sql = 'SELECT rowid, ref, status FROM '.MAIN_DB_PREFIX.'inventory WHERE rowid = '.((int) $inventoryId).' FOR UPDATE';
	$resql = $db->query($sql);
	if (!$resql) {
		return null;
	}
	$obj = $db->fetch_object($resql);
	$db->free($resql);
	if (!$obj || (int) $obj->status !== Inventory::STATUS_VALIDATED) {
		return null;
	}

	return array('id' => (int) $obj->rowid, 'ref' => (string) $obj->ref, 'status' => (int) $obj->status);
}

/**
 * Fill all real quantities with the current expected stock without POSTing every line.
 *
 * @param DoliDB $db Database handler
 * @param User $user Current user
 * @param int $inventoryId Inventory id
 * @return array<string,mixed>
 */
function inventaireplusAutofillLargeInventory($db, $user, $inventoryId)
{
	$result = array('ok' => false, 'updated' => 0, 'error' => '');
	$inventoryId = (int) $inventoryId;
	if ($inventoryId <= 0) {
		$result['error'] = 'ErrorBadParameters';
		return $result;
	}

	@set_time_limit(0);
	$db->begin();

	$inventoryData = inventaireplusLockValidatedInventory($db, $inventoryId);
	if (empty($inventoryData)) {
		$db->rollback();
		$result['error'] = 'InventoryPlusOptimizedActionNeedsValidatedInventory';
		return $result;
	}

	$sql = 'SELECT DISTINCT fk_warehouse, fk_product FROM '.MAIN_DB_PREFIX.'inventorydet WHERE fk_inventory = '.$inventoryId;
	$resql = $db->query($sql);
	if (!$resql) {
		$db->rollback();
		$result['error'] = $db->lasterror();
		return $result;
	}
	$productsByWarehouse = array();
	while ($obj = $db->fetch_object($resql)) {
		$warehouseId = (int) $obj->fk_warehouse;
		if (!isset($productsByWarehouse[$warehouseId])) {
			$productsByWarehouse[$warehouseId] = array();
		}
		$productsByWarehouse[$warehouseId][] = (int) $obj->fk_product;
	}
	$db->free($resql);

	foreach ($productsByWarehouse as $warehouseId => $productIds) {
		if (inventaireplusEnsureProductStockRows($db, $warehouseId, $productIds) < 0) {
			$db->rollback();
			$result['error'] = $db->lasterror();
			return $result;
		}
	}

	if (isModEnabled('productbatch')) {
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'inventorydet AS id';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product AS p ON p.rowid = id.fk_product';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product_stock AS ps ON ps.fk_product = id.fk_product AND ps.fk_entrepot = id.fk_warehouse';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'product_batch AS pb ON pb.fk_product_stock = ps.rowid AND pb.batch = id.batch';
		$sql .= ' SET id.qty_stock = COALESCE(pb.qty, 0), id.qty_view = COALESCE(pb.qty, 0)';
		if (getDolGlobalString('INVENTORY_MANAGE_REAL_PMP')) {
			$sql .= ', id.pmp_expected = p.pmp, id.pmp_real = COALESCE(id.pmp_real, p.pmp)';
		}
		$sql .= ' WHERE id.fk_inventory = '.$inventoryId;
		$sql .= ' AND COALESCE(p.tobatch, 0) > 0';
		$sql .= " AND COALESCE(id.batch, '') <> ''";
		$resql = $db->query($sql);
		if (!$resql) {
			$db->rollback();
			$result['error'] = $db->lasterror();
			return $result;
		}
		$result['updated'] += $db->affected_rows($resql);
	}

	$sql = 'UPDATE '.MAIN_DB_PREFIX.'inventorydet AS id';
	$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product AS p ON p.rowid = id.fk_product';
	$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product_stock AS ps ON ps.fk_product = id.fk_product AND ps.fk_entrepot = id.fk_warehouse';
	$sql .= ' SET id.qty_stock = COALESCE(ps.reel, 0), id.qty_view = COALESCE(ps.reel, 0)';
	if (getDolGlobalString('INVENTORY_MANAGE_REAL_PMP')) {
		$sql .= ', id.pmp_expected = p.pmp, id.pmp_real = COALESCE(id.pmp_real, p.pmp)';
	}
	$sql .= ' WHERE id.fk_inventory = '.$inventoryId;
	if (isModEnabled('productbatch')) {
		$sql .= " AND (COALESCE(p.tobatch, 0) = 0 OR COALESCE(id.batch, '') = '')";
	}
	$resql = $db->query($sql);
	if (!$resql) {
		$db->rollback();
		$result['error'] = $db->lasterror();
		return $result;
	}
	$result['updated'] += $db->affected_rows($resql);

	$sql = 'UPDATE '.MAIN_DB_PREFIX.'inventory SET fk_user_modif = '.((int) $user->id).' WHERE rowid = '.$inventoryId;
	if (!$db->query($sql)) {
		$db->rollback();
		$result['error'] = $db->lasterror();
		return $result;
	}

	$db->commit();
	$result['ok'] = true;
	return $result;
}

/**
 * Save visible inventory lines in a small transaction-safe batch.
 *
 * @param DoliDB $db Database handler
 * @param User $user Current user
 * @param int $inventoryId Inventory id
 * @param array<int,array<string,mixed>> $lines Lines from the inventory form
 * @return array<string,mixed>
 */
function inventaireplusSaveLargeInventoryLines($db, $user, $inventoryId, array $lines)
{
	$result = array('ok' => false, 'updated' => 0, 'error' => '');
	$inventoryId = (int) $inventoryId;

	if ($inventoryId <= 0) {
		$result['error'] = 'ErrorBadParameters';
		return $result;
	}
	if (empty($lines)) {
		$result['ok'] = true;
		return $result;
	}
	if (count($lines) > 1000) {
		$result['error'] = 'InventoryPlusOptimizedSaveBatchTooLarge';
		return $result;
	}

	$lineIds = array();
	foreach ($lines as $lineData) {
		if (!is_array($lineData) || empty($lineData['id'])) {
			$result['error'] = 'InventoryPlusOptimizedSaveInvalidPayload';
			return $result;
		}
		$lineId = (int) $lineData['id'];
		if ($lineId <= 0) {
			$result['error'] = 'InventoryPlusOptimizedSaveInvalidPayload';
			return $result;
		}
		$lineIds[$lineId] = $lineId;
	}

	$db->begin();

	$inventoryData = inventaireplusLockValidatedInventory($db, $inventoryId);
	if (empty($inventoryData)) {
		$db->rollback();
		$result['error'] = 'InventoryPlusOptimizedActionNeedsValidatedInventory';
		return $result;
	}

	$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'inventorydet';
	$sql .= ' WHERE fk_inventory = '.$inventoryId;
	$sql .= ' AND rowid IN ('.$db->sanitize(implode(',', $lineIds)).')';
	$resql = $db->query($sql);
	if (!$resql) {
		$db->rollback();
		$result['error'] = $db->lasterror();
		return $result;
	}

	$authorizedLineIds = array();
	while ($obj = $db->fetch_object($resql)) {
		$authorizedLineIds[(int) $obj->rowid] = true;
	}
	$db->free($resql);
	if (count($authorizedLineIds) !== count($lineIds)) {
		$db->rollback();
		$result['error'] = 'InventoryPlusOptimizedSaveInvalidPayload';
		return $result;
	}

	$inventoryLine = new InventoryLine($db);
	foreach ($lines as $lineData) {
		$lineId = (int) $lineData['id'];
		$qtyRaw = array_key_exists('qty_view', $lineData) ? trim((string) $lineData['qty_view']) : '';
		$qtyView = null;

		if ($qtyRaw !== '') {
			$qtyView = (float) price2num($qtyRaw, 'MS');
			if ($qtyView < 0) {
				$db->rollback();
				$result['error'] = 'InventoryPlusOptimizedSaveNegativeQty';
				$result['line'] = $lineId;
				return $result;
			}
		}

		$fetchResult = $inventoryLine->fetch($lineId);
		if ($fetchResult <= 0) {
			$db->rollback();
			$result['error'] = $inventoryLine->error ?: 'InventoryPlusOptimizedSaveInvalidPayload';
			$result['line'] = $lineId;
			return $result;
		}

		if ($qtyView !== null && array_key_exists('qty_stock', $lineData)) {
			$inventoryLine->qty_stock = (float) price2num((string) $lineData['qty_stock'], 'MS');
		}
		$inventoryLine->qty_view = $qtyView;
		if (array_key_exists('pmp_real', $lineData)) {
			$inventoryLine->pmp_real = price2num((string) $lineData['pmp_real'], 'MS');
		}
		if (array_key_exists('pmp_expected', $lineData)) {
			$inventoryLine->pmp_expected = price2num((string) $lineData['pmp_expected'], 'MS');
		}

		$updateResult = $inventoryLine->update($user);
		if ($updateResult < 0) {
			$db->rollback();
			$result['error'] = $inventoryLine->error ?: 'ErrorFailedToUpdateLine';
			$result['line'] = $lineId;
			return $result;
		}

		$result['updated']++;
	}

	$sql = 'UPDATE '.MAIN_DB_PREFIX.'inventory';
	$sql .= ' SET fk_user_modif = '.((int) $user->id);
	$sql .= ' WHERE rowid = '.$inventoryId;
	if (!$db->query($sql)) {
		$db->rollback();
		$result['error'] = $db->lasterror();
		return $result;
	}

	$db->commit();
	$result['ok'] = true;
	return $result;
}

/**
 * Close a validated inventory with server-side streaming and a global transaction.
 *
 * @param DoliDB $db Database handler
 * @param User $user Current user
 * @param int $inventoryId Inventory id
 * @param Translate $langs Translation handler
 * @return array<string,mixed>
 */
function inventaireplusRecordLargeInventory($db, $user, $inventoryId, $langs)
{
	global $conf;

	$result = array('ok' => false, 'movements' => 0, 'processed' => 0, 'error' => '');
	$inventoryId = (int) $inventoryId;
	if ($inventoryId <= 0) {
		$result['error'] = 'ErrorBadParameters';
		return $result;
	}

	@set_time_limit(0);
	$db->begin();

	$inventoryData = inventaireplusLockValidatedInventory($db, $inventoryId);
	if (empty($inventoryData)) {
		$db->rollback();
		$result['error'] = 'InventoryPlusOptimizedActionNeedsValidatedInventory';
		return $result;
	}

	$sql = 'SELECT DISTINCT fk_warehouse, fk_product FROM '.MAIN_DB_PREFIX.'inventorydet WHERE fk_inventory = '.$inventoryId;
	$resql = $db->query($sql);
	if (!$resql) {
		$db->rollback();
		$result['error'] = $db->lasterror();
		return $result;
	}
	$productsByWarehouse = array();
	while ($obj = $db->fetch_object($resql)) {
		$warehouseId = (int) $obj->fk_warehouse;
		if (!isset($productsByWarehouse[$warehouseId])) {
			$productsByWarehouse[$warehouseId] = array();
		}
		$productsByWarehouse[$warehouseId][] = (int) $obj->fk_product;
	}
	$db->free($resql);
	foreach ($productsByWarehouse as $warehouseId => $productIds) {
		if (inventaireplusEnsureProductStockRows($db, $warehouseId, $productIds) < 0) {
			$db->rollback();
			$result['error'] = $db->lasterror();
			return $result;
		}
	}

	$stockMovement = new MouvementStock($db);
	$stockMovement->setOrigin('inventory', $inventoryId);
	$inventoryCode = 'INV-'.$inventoryData['ref'];

	$sql = 'SELECT id.rowid, id.fk_warehouse, id.fk_product, id.batch, id.qty_stock, id.qty_view, id.pmp_real,';
	$sql .= ' p.tobatch, p.pmp, ps.reel AS real_stock, pb.qty AS real_batch';
	$sql .= ' FROM '.MAIN_DB_PREFIX.'inventorydet AS id';
	$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product AS p ON p.rowid = id.fk_product';
	$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product_stock AS ps ON ps.fk_product = id.fk_product AND ps.fk_entrepot = id.fk_warehouse';
	$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'product_batch AS pb ON pb.fk_product_stock = ps.rowid AND pb.batch = id.batch';
	$sql .= ' WHERE id.fk_inventory = '.$inventoryId;
	$sql .= ' ORDER BY id.rowid';
	$resql = $db->query($sql);
	if (!$resql) {
		$db->rollback();
		$result['error'] = $db->lasterror();
		return $result;
	}

	while ($line = $db->fetch_object($resql)) {
		$result['processed']++;
		if ($line->qty_view === null) {
			continue;
		}

		$isBatchLine = (isModEnabled('productbatch') && (int) $line->tobatch > 0);
		if ($isBatchLine && (string) $line->batch === '') {
			$db->free($resql);
			$db->rollback();
			$result['error'] = $langs->transnoentitiesnoconv('InventoryPlusOptimizedBatchLineWithoutBatch', $line->fk_product);
			return $result;
		}
		$realQtyNow = $isBatchLine ? (float) price2num($line->real_batch, 'MS') : (float) price2num($line->real_stock, 'MS');
		$stockMovementQty = (float) price2num(((float) price2num($line->qty_view, 'MS')) - $realQtyNow, 'MS');

		if ($stockMovementQty != 0) {
			$movementType = ($stockMovementQty < 0 ? 1 : 0);
			$price = (!empty($line->pmp_real) && getDolGlobalString('INVENTORY_MANAGE_REAL_PMP')) ? $line->pmp_real : 0;
			$idStockMove = $stockMovement->_create($user, (int) $line->fk_product, (int) $line->fk_warehouse, $stockMovementQty, $movementType, $price, $langs->trans('LabelOfInventoryMovemement', $inventoryData['ref']), $inventoryCode, '', '', '', (string) $line->batch);
			if ($idStockMove < 0) {
				$db->free($resql);
				$db->rollback();
				$result['error'] = !empty($stockMovement->error) ? $stockMovement->error : implode(', ', $stockMovement->errors);
				return $result;
			}

			$sqlUpdate = 'UPDATE '.MAIN_DB_PREFIX.'inventorydet SET fk_movement = '.((int) $idStockMove);
			if ((float) price2num($line->qty_stock, 'MS') != $realQtyNow) {
				$sqlUpdate .= ', qty_stock = '.$realQtyNow;
			}
			$sqlUpdate .= ' WHERE rowid = '.((int) $line->rowid);
			if (!$db->query($sqlUpdate)) {
				$db->free($resql);
				$db->rollback();
				$result['error'] = $db->lasterror();
				return $result;
			}
			$result['movements']++;
		}

		if (!empty($line->pmp_real) && getDolGlobalString('INVENTORY_MANAGE_REAL_PMP')) {
			$sqlPmp = 'UPDATE '.MAIN_DB_PREFIX.'product SET pmp = '.((float) $line->pmp_real).' WHERE rowid = '.((int) $line->fk_product);
			if (!$db->query($sqlPmp)) {
				$db->free($resql);
				$db->rollback();
				$result['error'] = $db->lasterror();
				return $result;
			}
			if (getDolGlobalString('MAIN_PRODUCT_PERENTITY_SHARED')) {
				$sqlPmp = 'UPDATE '.MAIN_DB_PREFIX.'product_perentity SET pmp = '.((float) $line->pmp_real).' WHERE fk_product = '.((int) $line->fk_product).' AND entity='.$conf->entity;
				if (!$db->query($sqlPmp)) {
					$db->free($resql);
					$db->rollback();
					$result['error'] = $db->lasterror();
					return $result;
				}
			}
		}
	}
	$db->free($resql);

	$inventory = new Inventory($db);
	$inventory->fetch($inventoryId);
	$statusResult = $inventory->setRecorded($user);
	if ($statusResult <= 0) {
		$db->rollback();
		$result['error'] = !empty($inventory->error) ? $inventory->error : implode(', ', $inventory->errors);
		return $result;
	}

	$db->commit();
	$result['ok'] = true;
	return $result;
}
