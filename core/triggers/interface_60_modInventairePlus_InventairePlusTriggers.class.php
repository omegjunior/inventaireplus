<?php
/* Copyright (C) 2026 Frédéric H Omega Junior <omegajunior.apps@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    core/triggers/interface_60_modInventairePlus_InventairePlusTriggers.class.php
 * \ingroup inventaireplus
 * \brief   Inventory document snapshot triggers.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/inventaireplus/lib/inventorydocs.lib.php';

class InterfaceInventairePlusTriggers extends DolibarrTriggers
{
	public function __construct($db)
	{
		parent::__construct($db);
		$this->family = 'stock';
		$this->description = 'InventairePlus triggers.';
		$this->version = self::VERSIONS['dev'];
		$this->picto = 'inventaireplus@inventaireplus';
	}

	protected function setTriggerError($object, $message, $code = -1)
	{
		$this->error = $message;
		$this->errors[] = $message;
		$object->error = $message;
		$object->errors[] = $message;
		return $code;
	}

	protected function fetchInventoryLineSnapshotSources($inventoryId)
	{
		$inventoryId = (int) $inventoryId;
		if ($inventoryId <= 0) return array();

		$lines = array();
		$categorySql = "COALESCE(".inventaireplusGetInventoryCategoryLabelSubquery('p').", 'Non classé')";
		$sql = "SELECT id.rowid, id.batch, p.ref AS product_ref, p.label AS product_label, ef.justification_text, ".$categorySql." AS category_label, pl.eatby, pl.sellby";
		$sql .= " FROM ".MAIN_DB_PREFIX."inventorydet AS id";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product AS p ON p.rowid = id.fk_product";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."inventorydet_extrafields AS ef ON ef.fk_object = id.rowid";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_stock AS ps ON ps.fk_product = id.fk_product AND ps.fk_entrepot = id.fk_warehouse";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_batch AS pb ON pb.fk_product_stock = ps.rowid AND pb.batch = id.batch";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_lot AS pl ON pl.batch = id.batch AND pl.fk_product = id.fk_product";
		$sql .= " WHERE id.fk_inventory = ".$inventoryId;
		$sql .= " ORDER BY id.rowid ASC";

		$resql = $this->db->query($sql);
		if (!$resql) return $lines;

		while ($obj = $this->db->fetch_object($resql)) {
			$lines[] = array(
				'rowid' => (int) $obj->rowid,
				'product_ref_snapshot' => (string) $obj->product_ref,
				'product_label_snapshot' => (string) $obj->product_label,
				'category_label_snapshot' => (!empty($obj->category_label) ? (string) $obj->category_label : 'Non classé'),
				'batch_snapshot' => (string) $obj->batch,
				'eatby_snapshot' => $obj->eatby,
				'sellby_snapshot' => $obj->sellby,
				'justification_text' => (string) $obj->justification_text,
			);
		}
		$this->db->free($resql);
		return $lines;
	}

	protected function upsertInventoryLineDocumentExtraFields($inventoryLineId, array $values)
	{
		$inventoryLineId = (int) $inventoryLineId;
		if ($inventoryLineId <= 0) return false;

		$productRefSnapshot = isset($values['product_ref_snapshot']) ? (string) $values['product_ref_snapshot'] : '';
		$productLabelSnapshot = isset($values['product_label_snapshot']) ? (string) $values['product_label_snapshot'] : '';
		$categoryLabelSnapshot = isset($values['category_label_snapshot']) ? (string) $values['category_label_snapshot'] : 'Non classé';
		$batchSnapshot = isset($values['batch_snapshot']) ? (string) $values['batch_snapshot'] : '';
		$eatbySnapshot = (!empty($values['eatby_snapshot']) ? "'".$this->db->escape($values['eatby_snapshot'])."'" : 'NULL');
		$sellbySnapshot = (!empty($values['sellby_snapshot']) ? "'".$this->db->escape($values['sellby_snapshot'])."'" : 'NULL');
		$justificationText = isset($values['justification_text']) ? (string) $values['justification_text'] : '';

		$sqlSet = " product_ref_snapshot = '".$this->db->escape($productRefSnapshot)."'";
		$sqlSet .= ", product_label_snapshot = '".$this->db->escape($productLabelSnapshot)."'";
		$sqlSet .= ", category_label_snapshot = '".$this->db->escape($categoryLabelSnapshot)."'";
		$sqlSet .= ", batch_snapshot = '".$this->db->escape($batchSnapshot)."'";
		$sqlSet .= ", eatby_snapshot = ".$eatbySnapshot;
		$sqlSet .= ", sellby_snapshot = ".$sellbySnapshot;
		$sqlSet .= ", justification_text = '".$this->db->escape($justificationText)."'";

		$resql = $this->db->query("SELECT fk_object FROM ".MAIN_DB_PREFIX."inventorydet_extrafields WHERE fk_object = ".$inventoryLineId." LIMIT 1");
		if (!$resql) return false;
		$exists = ($this->db->fetch_object($resql) ? true : false);
		$this->db->free($resql);

		if ($exists) {
			$sql = "UPDATE ".MAIN_DB_PREFIX."inventorydet_extrafields SET ".$sqlSet." WHERE fk_object = ".$inventoryLineId;
		} else {
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."inventorydet_extrafields (fk_object, product_ref_snapshot, product_label_snapshot, category_label_snapshot, batch_snapshot, eatby_snapshot, sellby_snapshot, justification_text)";
			$sql .= " VALUES (".$inventoryLineId.", '".$this->db->escape($productRefSnapshot)."', '".$this->db->escape($productLabelSnapshot)."', '".$this->db->escape($categoryLabelSnapshot)."', '".$this->db->escape($batchSnapshot)."', ".$eatbySnapshot.", ".$sellbySnapshot.", '".$this->db->escape($justificationText)."')";
		}
		return (bool) $this->db->query($sql);
	}

	public function inventoryValidated($action, $object, User $user, Translate $langs, Conf $conf)
	{
		$inventoryId = (int) $object->id;
		if ($inventoryId <= 0) return 0;

		$lines = $this->fetchInventoryLineSnapshotSources($inventoryId);
		if (empty($lines)) {
			dol_syslog(__METHOD__.' no inventory lines found to snapshot. inventory_id='.$inventoryId, LOG_DEBUG);
			return 0;
		}

		$this->db->begin();
		foreach ($lines as $line) {
			if (!$this->upsertInventoryLineDocumentExtraFields((int) $line['rowid'], $line)) {
				$this->db->rollback();
				return $this->setTriggerError($object, 'Failed to snapshot inventory line extrafields for inventory '.$inventoryId, -1);
			}
		}
		$this->db->commit();
		dol_syslog(__METHOD__.' inventory line snapshots created. inventory_id='.$inventoryId.', line_count='.count($lines), LOG_DEBUG);
		return 0;
	}

	protected function findOriginalStockMovementForReverse($object)
	{
		$inventoryCode = (!empty($object->inventorycode) ? trim((string) $object->inventorycode) : '');
		if ($inventoryCode === '' || strpos($inventoryCode, 'REVERT-') !== 0) return null;
		$originalInventoryCode = substr($inventoryCode, 7);
		$currentRowId = (int) $object->id;
		$productId = isset($object->product_id) ? (int) $object->product_id : 0;
		$warehouseId = isset($object->warehouse_id) ? (int) $object->warehouse_id : 0;
		$movementType = isset($object->type) ? (int) $object->type : -1;
		$batch = isset($object->batch) ? (string) $object->batch : '';
		$qty = isset($object->qty) ? (float) price2num($object->qty, 'MS') : 0.0;
		if ($currentRowId <= 0 || $productId <= 0 || $warehouseId <= 0 || $originalInventoryCode === '' || !in_array($movementType, array(0, 1), true)) return null;
		$expectedOriginalType = ($movementType === 0 ? 1 : 0);
		$sql = "SELECT sm.rowid FROM ".MAIN_DB_PREFIX."stock_mouvement AS sm WHERE sm.inventorycode = '".$this->db->escape($originalInventoryCode)."' AND sm.rowid < ".$currentRowId." AND sm.fk_product = ".$productId." AND sm.fk_entrepot = ".$warehouseId." AND sm.type_mouvement = ".$expectedOriginalType." AND ABS(sm.value) = ".price2num(abs($qty), 'MS');
		$sql .= ($batch !== '') ? " AND sm.batch = '".$this->db->escape($batch)."'" : " AND (sm.batch IS NULL OR sm.batch = '')";
		$sql .= " ORDER BY sm.rowid DESC LIMIT 1";
		$resql = $this->db->query($sql);
		if (!$resql) return null;
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		return $obj ? array('rowid' => (int) $obj->rowid) : null;
	}

	protected function fetchStockMovementTransferExtraFields($movementId)
	{
		$movementId = (int) $movementId;
		if ($movementId <= 0) return null;
		$sql = "SELECT transfer_source, transfer_target, transfer_category_id, transfer_category_label, transfer_category_rank, transfer_origin_type, transfer_origin_id FROM ".MAIN_DB_PREFIX."stock_mouvement_extrafields WHERE fk_object = ".$movementId." LIMIT 1";
		$resql = $this->db->query($sql);
		if (!$resql) return null;
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) return null;
		return array('transfer_source' => (int) $obj->transfer_source, 'transfer_target' => (int) $obj->transfer_target, 'transfer_category_id' => (int) $obj->transfer_category_id, 'transfer_category_label' => (string) $obj->transfer_category_label, 'transfer_category_rank' => (int) $obj->transfer_category_rank, 'transfer_origin_type' => (string) $obj->transfer_origin_type, 'transfer_origin_id' => (int) $obj->transfer_origin_id);
	}

	protected function upsertStockMovementTransferExtraFields($movementId, array $values)
	{
		$movementId = (int) $movementId;
		if ($movementId <= 0) return false;
		$resql = $this->db->query("SELECT fk_object FROM ".MAIN_DB_PREFIX."stock_mouvement_extrafields WHERE fk_object = ".$movementId." LIMIT 1");
		if (!$resql) return false;
		$exists = ($this->db->fetch_object($resql) ? true : false);
		$this->db->free($resql);
		$set = " transfer_source = ".((int) ($values['transfer_source'] ?? 0));
		$set .= ", transfer_target = ".((int) ($values['transfer_target'] ?? 0));
		$set .= ", transfer_category_id = ".((int) ($values['transfer_category_id'] ?? 0));
		$set .= ", transfer_category_label = '".$this->db->escape((string) ($values['transfer_category_label'] ?? ''))."'";
		$set .= ", transfer_category_rank = ".((int) ($values['transfer_category_rank'] ?? 0));
		$set .= ", transfer_origin_type = '".$this->db->escape((string) ($values['transfer_origin_type'] ?? ''))."'";
		$set .= ", transfer_origin_id = ".((int) ($values['transfer_origin_id'] ?? 0));
		$set .= ", transfer_pdf_file = '', transfer_pdf_generated_at = NULL";
		if ($exists) $sql = "UPDATE ".MAIN_DB_PREFIX."stock_mouvement_extrafields SET ".$set." WHERE fk_object = ".$movementId;
		else $sql = "INSERT INTO ".MAIN_DB_PREFIX."stock_mouvement_extrafields (fk_object, transfer_source, transfer_target, transfer_category_id, transfer_category_label, transfer_category_rank, transfer_origin_type, transfer_origin_id, transfer_pdf_file, transfer_pdf_generated_at) VALUES (".$movementId.", ".((int) ($values['transfer_source'] ?? 0)).", ".((int) ($values['transfer_target'] ?? 0)).", ".((int) ($values['transfer_category_id'] ?? 0)).", '".$this->db->escape((string) ($values['transfer_category_label'] ?? ''))."', ".((int) ($values['transfer_category_rank'] ?? 0)).", '".$this->db->escape((string) ($values['transfer_origin_type'] ?? ''))."', ".((int) ($values['transfer_origin_id'] ?? 0)).", '', NULL)";
		return (bool) $this->db->query($sql);
	}

	public function stockMovement($action, $object, User $user, Translate $langs, Conf $conf)
	{
		$inventoryCode = (!empty($object->inventorycode) ? trim((string) $object->inventorycode) : '');
		$movementType = isset($object->type) ? (int) $object->type : -1;
		if ($inventoryCode === '' || strpos($inventoryCode, 'REVERT-') !== 0 || !in_array($movementType, array(0, 1), true)) return 0;
		$originalMovement = $this->findOriginalStockMovementForReverse($object);
		if (empty($originalMovement)) return 0;
		$originalExtraFields = $this->fetchStockMovementTransferExtraFields((int) $originalMovement['rowid']);
		if (empty($originalExtraFields)) return 0;
		$reverseExtraFields = array('transfer_source' => (int) $originalExtraFields['transfer_target'], 'transfer_target' => (int) $originalExtraFields['transfer_source'], 'transfer_category_id' => (int) $originalExtraFields['transfer_category_id'], 'transfer_category_label' => (string) $originalExtraFields['transfer_category_label'], 'transfer_category_rank' => (int) $originalExtraFields['transfer_category_rank'], 'transfer_origin_type' => (string) $originalExtraFields['transfer_origin_type'], 'transfer_origin_id' => (int) $originalExtraFields['transfer_origin_id']);
		if ((int) $reverseExtraFields['transfer_source'] <= 0 || (int) $reverseExtraFields['transfer_target'] <= 0) return 0;
		$this->upsertStockMovementTransferExtraFields((int) $object->id, $reverseExtraFields);
		return 0;
	}
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('inventaireplus')) return 0;
		$methodName = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', strtolower($action)))));
		$callback = array($this, $methodName);
		if (is_callable($callback)) {
			dol_syslog("Trigger '".$this->name."' for action '$action' launched by ".__FILE__.'. id='.$object->id);
			return call_user_func($callback, $action, $object, $user, $langs, $conf);
		}
		return 0;
	}
}

