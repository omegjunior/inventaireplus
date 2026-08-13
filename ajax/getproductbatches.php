<?php
/* Copyright (C) 2026      Frédéric H Omega Junior <omegajunior.apps@gmail.com>
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('CSRFCHECK_WITH_TOKEN')) {
	define('CSRFCHECK_WITH_TOKEN', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

require '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

if (!headers_sent()) {
	header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
	header('Pragma: no-cache');
	header('X-Content-Type-Options: nosniff');
	header('X-Frame-Options: SAMEORIGIN');
	header('Referrer-Policy: same-origin');
	header('Content-Type: application/json; charset=UTF-8');
}

/**
 * Emit JSON response and stop.
 *
 * @param int   $httpCode HTTP code
 * @param array $payload  Payload
 * @return never
 */
function inventaireplus_render_json_response($httpCode, array $payload)
{
	http_response_code((int) $httpCode);
	print json_encode($payload);
	exit;
}

$langs->loadLangs(array('main', 'products', 'stocks', 'productbatch', 'inventaireplus@inventaireplus'));

if (!in_array(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), array('GET', 'POST'), true)) {
	inventaireplus_render_json_response(405, array('result' => false, 'error' => 'Method not allowed'));
}

if ($user->socid) {
	$socid = $user->socid;
}
restrictedArea($user, 'produit|service');

$productId = GETPOSTINT('fk_product');
$warehouseId = GETPOSTINT('fk_entrepot');

if ($productId <= 0 || $warehouseId <= 0) {
	inventaireplus_render_json_response(400, array('result' => false, 'error' => 'Missing or invalid parameters'));
}

$product = new Product($db);
if ($product->fetch($productId) <= 0) {
	inventaireplus_render_json_response(404, array('result' => false, 'error' => 'Product not found'));
}

if (empty($product->status_batch)) {
	inventaireplus_render_json_response(200, array(
		'result' => true,
		'product_is_batch' => false,
		'batches' => array(),
	));
}

$sql = "SELECT pb.batch, pl.eatby, pl.sellby, SUM(pb.qty) AS qty_available";
$sql .= " FROM ".MAIN_DB_PREFIX."product_stock AS ps";
$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_batch AS pb ON pb.fk_product_stock = ps.rowid";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_lot AS pl ON pl.batch = pb.batch AND pl.fk_product = ps.fk_product";
$sql .= " WHERE ps.fk_entrepot = ".$warehouseId;
$sql .= " AND ps.fk_product = ".$productId;
$sql .= " AND COALESCE(pb.qty, 0) > 0";
$sql .= " GROUP BY pb.batch, pl.eatby, pl.sellby";
$sql .= " ORDER BY CASE WHEN pl.sellby IS NULL THEN 1 ELSE 0 END, pl.sellby ASC,";
$sql .= " CASE WHEN pl.eatby IS NULL THEN 1 ELSE 0 END, pl.eatby ASC, pb.batch ASC";

$resql = $db->query($sql);
if (!$resql) {
	inventaireplus_render_json_response(500, array('result' => false, 'error' => $db->lasterror()));
}

$batches = array();
while ($obj = $db->fetch_object($resql)) {
	$sellbyTs = (!empty($obj->sellby) ? $db->jdate($obj->sellby) : null);
	$eatbyTs = (!empty($obj->eatby) ? $db->jdate($obj->eatby) : null);
	$expiryLabel = '';
	if (!empty($sellbyTs)) {
		$expiryLabel = dol_print_date($sellbyTs, 'day', false, $langs, true);
	} elseif (!empty($eatbyTs)) {
		$expiryLabel = dol_print_date($eatbyTs, 'day', false, $langs, true);
	}

	$qtyAvailable = price2num($obj->qty_available, 'MS');
	$label = (string) $obj->batch;
	if ($expiryLabel !== '') {
		$label .= ' - '.$langs->transnoentities("SellByDate").': '.$expiryLabel;
	}
	$label .= ' - '.$langs->transnoentities("Qty").': '.$qtyAvailable;

	$batches[] = array(
		'batch' => (string) $obj->batch,
		'eatby' => (!empty($obj->eatby) ? $obj->eatby : null),
		'sellby' => (!empty($obj->sellby) ? $obj->sellby : null),
		'eatby_ts' => $eatbyTs,
		'sellby_ts' => $sellbyTs,
		'qty' => (float) $qtyAvailable,
		'expiry_label' => $expiryLabel,
		'label' => $label,
	);
}

inventaireplus_render_json_response(200, array(
	'result' => true,
	'product_is_batch' => true,
	'batches' => $batches,
));

