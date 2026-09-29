<?php
/* Copyright (C) 2026 Omega Junior
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * Helpers for concurrent, traceable physical inventory counting.
 */

require_once DOL_DOCUMENT_ROOT.'/product/inventory/class/inventory.class.php';

/**
 * Return whether an inventory has an open collaborative campaign.
 *
 * @param DoliDB $db Database handler
 * @param int $inventoryId Inventory id
 * @return bool
 */
function inventaireplusHasOpenCollaborativeCount($db, $inventoryId)
{
	$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'inventaireplus_count_session';
	$sql .= ' WHERE entity = '.((int) getEntity('inventory'));
	$sql .= ' AND fk_inventory = '.((int) $inventoryId).' AND status = 0';
	$resql = $db->query($sql);
	return ($resql && $db->num_rows($resql) > 0);
}

/**
 * Atomically reserve an inventory for the native close action.
 *
 * A contribution locks the same inventory row before opening/writing a campaign,
 * so either the contribution wins and closing is refused, or closing wins and
 * subsequent contributions are refused.
 *
 * @param DoliDB $db Database handler
 * @param int $inventoryId Inventory id
 * @param User $user Current user
 * @return bool False when an open count campaign prevents closing
 */
function inventaireplusReserveInventoryForClosing($db, $inventoryId, $user)
{
	$entity = (int) getEntity('inventory');
	$db->begin();
	$resql = $db->query('SELECT rowid, status FROM '.MAIN_DB_PREFIX.'inventory WHERE rowid = '.((int) $inventoryId).' AND entity = '.$entity.' FOR UPDATE');
	$inventory = ($resql ? $db->fetch_object($resql) : null);
	if (!$inventory || (int) $inventory->status !== Inventory::STATUS_VALIDATED) {
		$db->rollback();
		return false;
	}
	$session = inventaireplusFetchCountSession($db, $inventoryId);
	if ($session && (int) $session->status === 0) {
		$db->rollback();
		return false;
	}
	if ($session) {
		$resql = $db->query('UPDATE '.MAIN_DB_PREFIX.'inventaireplus_count_session SET status = 2 WHERE rowid = '.((int) $session->rowid).' AND status <> 0');
	} else {
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'inventaireplus_count_session (entity, fk_inventory, status, datec, fk_user_author) VALUES (';
		$sql .= $entity.', '.((int) $inventoryId).', 2, \''.$db->idate(dol_now()).'\', '.((int) $user->id).')';
		$resql = $db->query($sql);
	}
	if (!$resql) {
		$db->rollback();
		return false;
	}
	$db->commit();
	return true;
}

/**
 * Fetch the collaborative campaign for an inventory, without creating it.
 *
 * @param DoliDB $db Database handler
 * @param int $inventoryId Inventory id
 * @return object|null
 */
function inventaireplusFetchCountSession($db, $inventoryId)
{
	$sql = 'SELECT rowid, fk_inventory, status, datec, date_close, fk_user_author, fk_user_close';
	$sql .= ' FROM '.MAIN_DB_PREFIX.'inventaireplus_count_session';
	$sql .= ' WHERE entity = '.((int) getEntity('inventory')).' AND fk_inventory = '.((int) $inventoryId);
	$resql = $db->query($sql);
	return ($resql ? $db->fetch_object($resql) : null);
}

/**
 * Fetch or create the single collaborative campaign for an inventory.
 *
 * @param DoliDB $db Database handler
 * @param int $inventoryId Inventory id
 * @param User $user Current user
 * @return object|null
 */
function inventaireplusGetOrCreateCountSession($db, $inventoryId, $user)
{
	$entity = (int) getEntity('inventory');
	$session = inventaireplusFetchCountSession($db, $inventoryId);
	if ($session) {
		return $session;
	}

	$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'inventaireplus_count_session';
	$sql .= ' (entity, fk_inventory, status, datec, fk_user_author) VALUES (';
	$sql .= $entity.', '.((int) $inventoryId).', 0, \''.$db->idate(dol_now()).'\', '.((int) $user->id).')';
	if (!$db->query($sql)) {
		// A concurrent first access may have created the unique session.
		$resql = $db->query('SELECT rowid, fk_inventory, status, datec, date_close, fk_user_author, fk_user_close FROM '.MAIN_DB_PREFIX.'inventaireplus_count_session WHERE entity = '.$entity.' AND fk_inventory = '.((int) $inventoryId));
		return ($resql ? $db->fetch_object($resql) : null);
	}

	$resql = $db->query('SELECT rowid, fk_inventory, status, datec, date_close, fk_user_author, fk_user_close FROM '.MAIN_DB_PREFIX.'inventaireplus_count_session WHERE rowid = '.((int) $db->last_insert_id(MAIN_DB_PREFIX.'inventaireplus_count_session')));
	return ($resql ? $db->fetch_object($resql) : null);
}

/**
 * Resolve exactly one native inventory line from a scanned product token.
 *
 * @param DoliDB $db Database handler
 * @param int $inventoryId Inventory id
 * @param string $token Product id, ref or barcode
 * @param string $batch Batch/serial, when relevant
 * @return array<string,mixed>
 */
function inventaireplusResolveScannedInventoryLine($db, $inventoryId, $token, $batch)
{
	$token = trim($token);
	$batch = trim($batch);
	if ($token === '') {
		return array('ok' => false, 'error' => 'InventoryPlusCollaborativeProductRequired');
	}

	$matches = array();
	$sql = 'SELECT id.rowid, id.fk_inventory, id.fk_warehouse, id.fk_product, id.batch, p.ref, p.label, p.barcode';
	$sql .= ' FROM '.MAIN_DB_PREFIX.'inventorydet AS id';
	$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product AS p ON p.rowid = id.fk_product';
	$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'inventory AS i ON i.rowid = id.fk_inventory';
	$sql .= ' WHERE id.fk_inventory = '.((int) $inventoryId);
	$sql .= ' AND i.entity = '.((int) getEntity('inventory'));
	$sql .= ' AND (';
	if (preg_match('/^[0-9]+$/', $token)) {
		$sql .= 'p.rowid = '.((int) $token).' OR ';
	}
	$sql .= 'p.ref = \''.$db->escape($token).'\' OR p.barcode = \''.$db->escape($token).'\')';
	if ($batch !== '') {
		$sql .= ' AND id.batch = \''.$db->escape($batch).'\'';
	}
	$sql .= ' ORDER BY '.(preg_match('/^[0-9]+$/', $token) ? 'CASE WHEN p.rowid = '.((int) $token).' THEN 0 ELSE 1 END, ' : '').'id.rowid ASC';
	$resql = $db->query($sql);
	if (!$resql) {
		return array('ok' => false, 'error' => 'InventoryPlusCollaborativeDatabaseError');
	}
	while ($obj = $db->fetch_object($resql)) {
		$matches[] = $obj;
	}
	$db->free($resql);
	if (preg_match('/^[0-9]+$/', $token) && !empty($matches) && (int) $matches[0]->fk_product === (int) $token) {
		$matches = array_values(array_filter($matches, function ($line) use ($token) {
			return (int) $line->fk_product === (int) $token;
		}));
	}
	if (empty($matches)) {
		return array('ok' => false, 'error' => 'InventoryPlusCollaborativeProductNotInInventory');
	}
	if (count($matches) > 1 || ($batch === '' && (string) $matches[0]->batch !== '')) {
		return array('ok' => false, 'error' => 'InventoryPlusCollaborativeBatchRequired');
	}
	return array('ok' => true, 'line' => $matches[0]);
}

/**
 * Add one idempotent contribution while holding the campaign lock.
 *
 * @param DoliDB $db Database handler
 * @param User $user Current user
 * @param int $inventoryId Inventory id
 * @param string $token Product token
 * @param string $batch Batch/serial
 * @param string $zone Physical zone
 * @param float $qty Positive counted quantity
 * @param string $scanKey Browser-generated idempotency key
 * @return array<string,mixed>
 */
function inventaireplusAddCountContribution($db, $user, $inventoryId, $token, $batch, $zone, $qty, $scanKey)
{
	$zone = trim($zone);
	$scanKey = preg_replace('/[^a-zA-Z0-9_-]/', '', $scanKey);
	if ($zone === '') return array('ok' => false, 'error' => 'InventoryPlusCollaborativeZoneRequired');
	if (strlen($zone) > 128 || strlen($batch) > 128 || strlen($token) > 255) return array('ok' => false, 'error' => 'ErrorBadParameters');
	if (!is_finite($qty) || $qty <= 0 || $qty > 1000000000000) return array('ok' => false, 'error' => 'InventoryPlusCollaborativePositiveQtyRequired');
	if ($scanKey === '' || strlen($scanKey) > 64) return array('ok' => false, 'error' => 'InventoryPlusCollaborativeInvalidScanKey');

	$db->begin();
	$resql = $db->query('SELECT rowid, status FROM '.MAIN_DB_PREFIX.'inventory WHERE rowid = '.((int) $inventoryId).' AND entity = '.((int) getEntity('inventory')).' FOR UPDATE');
	$inventory = ($resql ? $db->fetch_object($resql) : null);
	if (!$inventory || (int) $inventory->status !== Inventory::STATUS_VALIDATED) {
		$db->rollback();
		return array('ok' => false, 'error' => 'InventoryPlusCollaborativeInventoryNotOpen');
	}

	$session = inventaireplusGetOrCreateCountSession($db, $inventoryId, $user);
	if (!$session) {
		$db->rollback();
		return array('ok' => false, 'error' => 'InventoryPlusCollaborativeDatabaseError');
	}
	$resql = $db->query('SELECT rowid, status FROM '.MAIN_DB_PREFIX.'inventaireplus_count_session WHERE rowid = '.((int) $session->rowid).' FOR UPDATE');
	$session = ($resql ? $db->fetch_object($resql) : null);
	if (!$session || (int) $session->status !== 0) {
		$db->rollback();
		return array('ok' => false, 'error' => 'InventoryPlusCollaborativeCampaignClosed');
	}

	$resql = $db->query('SELECT rowid, fk_user_author FROM '.MAIN_DB_PREFIX.'inventaireplus_count_contribution WHERE fk_session = '.((int) $session->rowid).' AND scan_key = \''.$db->escape($scanKey).'\'');
	if ($resql && ($duplicate = $db->fetch_object($resql))) {
		if ((int) $duplicate->fk_user_author === (int) $user->id) {
			$db->commit();
			return array('ok' => true, 'duplicate' => true);
		}
		$db->rollback();
		return array('ok' => false, 'error' => 'InventoryPlusCollaborativeInvalidScanKey');
	}

	$resolved = inventaireplusResolveScannedInventoryLine($db, $inventoryId, $token, $batch);
	if (empty($resolved['ok'])) {
		$db->rollback();
		return $resolved;
	}
	$line = $resolved['line'];
	$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'inventaireplus_count_contribution';
	$sql .= ' (entity, fk_session, fk_inventory, fk_inventorydet, fk_warehouse, fk_product, batch, zone, qty, scan_key, active, datec, fk_user_author) VALUES (';
	$sql .= ((int) getEntity('inventory')).', '.((int) $session->rowid).', '.((int) $inventoryId).', '.((int) $line->rowid).', '.((int) $line->fk_warehouse).', '.((int) $line->fk_product).', ';
	$sql .= ($line->batch === null ? 'NULL' : '\''.$db->escape($line->batch).'\'').', \''.$db->escape($zone).'\', '.((float) $qty).', \''.$db->escape($scanKey).'\', 1, \''.$db->idate(dol_now()).'\', '.((int) $user->id).')';
	if (!$db->query($sql)) {
		$db->rollback();
		return array('ok' => false, 'error' => 'InventoryPlusCollaborativeDatabaseError');
	}
	$db->commit();
	return array('ok' => true, 'line' => $line);
}

/**
 * Void one contribution without destroying its audit trail.
 *
 * @param DoliDB $db Database handler
 * @param User $user Current user
 * @param int $inventoryId Inventory id
 * @param int $contributionId Contribution id
 * @param bool $canManage Whether the user may void another user's entry
 * @return bool
 */
function inventaireplusVoidCountContribution($db, $user, $inventoryId, $contributionId, $canManage)
{
	$db->begin();
	$sql = 'SELECT c.rowid, c.fk_user_author, c.active, s.status FROM '.MAIN_DB_PREFIX.'inventaireplus_count_contribution AS c';
	$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'inventaireplus_count_session AS s ON s.rowid = c.fk_session';
	$sql .= ' WHERE c.rowid = '.((int) $contributionId).' AND c.fk_inventory = '.((int) $inventoryId).' FOR UPDATE';
	$resql = $db->query($sql);
	$row = ($resql ? $db->fetch_object($resql) : null);
	if (!$row || (int) $row->status !== 0 || !(int) $row->active || (!$canManage && (int) $row->fk_user_author !== (int) $user->id)) {
		$db->rollback();
		return false;
	}
	$sql = 'UPDATE '.MAIN_DB_PREFIX.'inventaireplus_count_contribution SET active = 0, date_void = \''.$db->idate(dol_now()).'\', fk_user_void = '.((int) $user->id).' WHERE rowid = '.((int) $contributionId).' AND active = 1';
	if (!$db->query($sql)) {
		$db->rollback();
		return false;
	}
	$db->commit();
	return true;
}

/**
 * Atomically close a campaign and copy contribution totals to native qty_view.
 *
 * @param DoliDB $db Database handler
 * @param User $user Current user
 * @param int $inventoryId Inventory id
 * @return array<string,mixed>
 */
function inventaireplusConsolidateCollaborativeCount($db, $user, $inventoryId)
{
	$db->begin();
	$resql = $db->query('SELECT rowid, status FROM '.MAIN_DB_PREFIX.'inventory WHERE rowid = '.((int) $inventoryId).' AND entity = '.((int) getEntity('inventory')).' FOR UPDATE');
	$inventory = ($resql ? $db->fetch_object($resql) : null);
	if (!$inventory || (int) $inventory->status !== Inventory::STATUS_VALIDATED) {
		$db->rollback();
		return array('ok' => false, 'error' => 'InventoryPlusCollaborativeInventoryNotOpen');
	}
	$resql = $db->query('SELECT rowid, status FROM '.MAIN_DB_PREFIX.'inventaireplus_count_session WHERE entity = '.((int) getEntity('inventory')).' AND fk_inventory = '.((int) $inventoryId).' FOR UPDATE');
	$session = ($resql ? $db->fetch_object($resql) : null);
	if (!$session || (int) $session->status !== 0) {
		$db->rollback();
		return array('ok' => false, 'error' => 'InventoryPlusCollaborativeCampaignClosed');
	}

	$totals = array();
	$sql = 'SELECT c.fk_inventorydet, SUM(c.qty) AS counted_qty FROM '.MAIN_DB_PREFIX.'inventaireplus_count_contribution AS c';
	$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'inventorydet AS id ON id.rowid = c.fk_inventorydet AND id.fk_inventory = c.fk_inventory';
	$sql .= ' WHERE c.fk_session = '.((int) $session->rowid).' AND c.active = 1 GROUP BY c.fk_inventorydet';
	$resql = $db->query($sql);
	if (!$resql) {
		$db->rollback();
		return array('ok' => false, 'error' => 'InventoryPlusCollaborativeDatabaseError');
	}
	while ($obj = $db->fetch_object($resql)) {
		$totals[(int) $obj->fk_inventorydet] = (float) $obj->counted_qty;
	}
	foreach ($totals as $lineId => $qty) {
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'inventorydet SET qty_view = '.((float) $qty).' WHERE rowid = '.((int) $lineId).' AND fk_inventory = '.((int) $inventoryId);
		$resql = $db->query($sql);
		if (!$resql) {
			$db->rollback();
			return array('ok' => false, 'error' => 'InventoryPlusCollaborativeDatabaseError');
		}
	}
	$sql = 'UPDATE '.MAIN_DB_PREFIX.'inventaireplus_count_session SET status = 1, date_close = \''.$db->idate(dol_now()).'\', fk_user_close = '.((int) $user->id).' WHERE rowid = '.((int) $session->rowid).' AND status = 0';
	if (!$db->query($sql)) {
		$db->rollback();
		return array('ok' => false, 'error' => 'InventoryPlusCollaborativeDatabaseError');
	}
	$db->commit();
	return array('ok' => true, 'lines' => count($totals));
}

/**
 * Fetch totals by native line for display.
 *
 * @param DoliDB $db Database handler
 * @param int $sessionId Session id
 * @return array<int,object>
 */
function inventaireplusFetchCollaborativeTotals($db, $sessionId)
{
	$rows = array();
	$sql = 'SELECT c.fk_inventorydet, c.fk_product, c.batch, p.ref, p.label, id.qty_stock, SUM(c.qty) AS counted_qty, COUNT(c.rowid) AS contribution_count';
	$sql .= ' FROM '.MAIN_DB_PREFIX.'inventaireplus_count_contribution AS c';
	$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product AS p ON p.rowid = c.fk_product';
	$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'inventorydet AS id ON id.rowid = c.fk_inventorydet';
	$sql .= ' WHERE c.fk_session = '.((int) $sessionId).' AND c.active = 1';
	$sql .= ' GROUP BY c.fk_inventorydet, c.fk_product, c.batch, p.ref, p.label, id.qty_stock ORDER BY p.ref ASC, c.batch ASC';
	$resql = $db->query($sql);
	if ($resql) while ($obj = $db->fetch_object($resql)) $rows[] = $obj;
	return $rows;
}

/**
 * Fetch the latest contribution entries for audit and correction.
 *
 * @param DoliDB $db Database handler
 * @param int $sessionId Session id
 * @param int $limit Maximum rows
 * @return array<int,object>
 */
function inventaireplusFetchRecentContributions($db, $sessionId, $limit = 50)
{
	$rows = array();
	$sql = 'SELECT c.rowid, c.fk_user_author, c.batch, c.zone, c.qty, c.active, c.datec, p.ref, p.label, u.login';
	$sql .= ' FROM '.MAIN_DB_PREFIX.'inventaireplus_count_contribution AS c';
	$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product AS p ON p.rowid = c.fk_product';
	$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'user AS u ON u.rowid = c.fk_user_author';
	$sql .= ' WHERE c.fk_session = '.((int) $sessionId).' ORDER BY c.rowid DESC';
	$sql .= $db->plimit(max(1, min(200, (int) $limit)), 0);
	$resql = $db->query($sql);
	if ($resql) while ($obj = $db->fetch_object($resql)) $rows[] = $obj;
	return $rows;
}
