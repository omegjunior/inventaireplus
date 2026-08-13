<?php
/* Copyright (C) 2026 Omega Junior
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file        inventaireplus/lib/productcategory.lib.php
 * \brief       Helpers to resolve product category labels for documents
 */

/**
 * Resolve category labels for products.
 * If several categories exist, the first label in alphabetical order is used.
 * If no category exists, the product is mapped to "Non classé".
 *
 * @param DoliDB $db         Database handler
 * @param array  $productIds Product ids
 * @return array<int,string> product id => category label
 */
function inventaireplusFetchProductCategoryLabelsByProductIds($db, array $productIds)
{
	$result = array();
	$productIds = array_unique(array_map('intval', $productIds));
	$productIds = array_filter($productIds, function ($value) {
		return ($value > 0);
	});

	if (empty($productIds)) {
		return $result;
	}

	$sql = "SELECT p.rowid AS product_id,";
	$sql .= " COALESCE((";
	$sql .= "SELECT c.label";
	$sql .= " FROM ".MAIN_DB_PREFIX."categorie_product AS cp";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."categorie AS c ON c.rowid = cp.fk_categorie";
	$sql .= " WHERE cp.fk_product = p.rowid";
	$sql .= " AND c.type = 0";
	$sql .= " AND c.entity IN (".getEntity('category').")";
	$sql .= " ORDER BY c.label ASC, c.rowid ASC";
	$sql .= " LIMIT 1";
	$sql .= "), 'Non classé') AS category_label";
	$sql .= " FROM ".MAIN_DB_PREFIX."product AS p";
	$sql .= " WHERE p.rowid IN (".implode(',', $productIds).")";

	$resql = $db->query($sql);
	if (!$resql) {
		return $result;
	}

	while ($obj = $db->fetch_object($resql)) {
		$result[(int) $obj->product_id] = (string) $obj->category_label;
	}

	return $result;
}

/**
 * Return a SQL subquery that resolves the retained product category id.
 * If several categories exist, the first label in alphabetical order is used.
 *
 * @param string $productAlias SQL alias of product table
 * @return string
 */
function inventaireplusGetProductCategoryIdSubquery($productAlias = 'p')
{
	return "(SELECT c.rowid"
		." FROM ".MAIN_DB_PREFIX."categorie_product AS cp"
		." INNER JOIN ".MAIN_DB_PREFIX."categorie AS c ON c.rowid = cp.fk_categorie"
		." WHERE cp.fk_product = ".$productAlias.".rowid"
		." AND c.type = 0"
		." AND c.entity IN (".getEntity('category').")"
		." ORDER BY c.label ASC, c.rowid ASC"
		." LIMIT 1)";
}

/**
 * Return a SQL subquery that resolves the retained product category label.
 * If several categories exist, the first label in alphabetical order is used.
 *
 * @param string $productAlias SQL alias of product table
 * @return string
 */
function inventaireplusGetProductCategoryLabelSubquery($productAlias = 'p')
{
	return "(SELECT c.label"
		." FROM ".MAIN_DB_PREFIX."categorie_product AS cp"
		." INNER JOIN ".MAIN_DB_PREFIX."categorie AS c ON c.rowid = cp.fk_categorie"
		." WHERE cp.fk_product = ".$productAlias.".rowid"
		." AND c.type = 0"
		." AND c.entity IN (".getEntity('category').")"
		." ORDER BY c.label ASC, c.rowid ASC"
		." LIMIT 1)";
}

/**
 * Return category path nodes from root to selected category.
 *
 * @param DoliDB $db Database handler
 * @param int    $categoryId Category id
 * @param string $fallbackLabel Fallback label
 * @return array<int,array{key:string,label:string,level:int,sort:string}>
 */
function inventaireplusGetCategoryPathNodes($db, $categoryId, $fallbackLabel = 'Non classé')
{
	static $cache = array();
	$categoryId = (int) $categoryId;
	if ($categoryId <= 0) {
		return array(array('key' => 'none', 'label' => $fallbackLabel, 'level' => 0, 'sort' => $fallbackLabel));
	}
	if (isset($cache[$categoryId])) {
		return $cache[$categoryId];
	}

	$path = array();
	$current = $categoryId;
	$guard = 0;
	while ($current > 0 && $guard < 50) {
		$sql = "SELECT rowid, label, fk_parent";
		$sql .= " FROM ".MAIN_DB_PREFIX."categorie";
		$sql .= " WHERE rowid = ".((int) $current);
		$sql .= " AND type = 0";
		$sql .= " AND entity IN (".getEntity('category').")";
		$resql = $db->query($sql);
		if (!$resql) {
			break;
		}
		$obj = $db->fetch_object($resql);
		$db->free($resql);
		if (!$obj) {
			break;
		}
		array_unshift($path, array('id' => (int) $obj->rowid, 'label' => trim((string) $obj->label)));
		$current = (int) $obj->fk_parent;
		$guard++;
	}

	$nodes = array();
	$sortLabels = array();
	$idPath = array();
	foreach ($path as $index => $category) {
		$label = ($category['label'] !== '' ? $category['label'] : $fallbackLabel);
		$sortLabels[] = $label;
		$idPath[] = $category['id'];
		$nodes[] = array(
			'key' => 'cat:'.implode('/', $idPath),
			'label' => $label,
			'level' => $index,
			'sort' => implode(' > ', $sortLabels),
		);
	}
	if (empty($nodes)) {
		$nodes[] = array('key' => 'cat:'.$categoryId, 'label' => $fallbackLabel, 'level' => 0, 'sort' => $fallbackLabel);
	}
	$cache[$categoryId] = $nodes;

	return $nodes;
}

/**
 * Sort category aggregate rows by hierarchy-aware display order.
 *
 * @param array $categoryA First category
 * @param array $categoryB Second category
 * @return int
 */
function inventaireplusSortCategoryAggregates($categoryA, $categoryB)
{
	$sortA = !empty($categoryA['sort']) ? (string) $categoryA['sort'] : (string) $categoryA['label'];
	$sortB = !empty($categoryB['sort']) ? (string) $categoryB['sort'] : (string) $categoryB['label'];
	$compare = strcasecmp($sortA, $sortB);
	if ($compare !== 0) {
		return $compare;
	}

	return ((int) ($categoryA['level'] ?? 0) <=> (int) ($categoryB['level'] ?? 0));
}

