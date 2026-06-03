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
