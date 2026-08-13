<?php
/* Copyright (C) 2001-2002  Rodolphe Quiedeville <rodolphe@quiedeville.org>
 * Copyright (C) 2004-2020  Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2005-2010  Regis Houssin        <regis.houssin@capnetworks.com>
 * Copyright (C) 2012       Vinícius Nogueira    <viniciusvgn@gmail.com>
 * Copyright (C) 2014       Florian Henry        <florian.henry@open-cooncept.pro>
 * Copyright (C) 2015       Jean-François Ferry  <jfefe@aternatik.fr>
 * Copyright (C) 2016       Juanjo Menent        <jmenent@2byte.es>
 * Copyright (C) 2017       Alexandre Spangaro   <aspangaro@open-dsi.fr>
 * Copyright (C) 2018       Andreu Bisquerra	 <jove@bisquerra.com>
 * Copyright (C) 2024	   Omega Junior        <omegajunior.apps@gmail.com>
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
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

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/html.formproduct.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

// Load translation files required by the page
$langs->loadLangs(array("inventaireplus@inventaireplus", "stocks"));
//get the folder to show
$diroutputgenerate = $conf->inventaireplus->dir_output.'/stock_cashier_sheet';
//collect of post variable
$action = GETPOST('action', 'aZ09');
$userId = GETPOSTINT('userid');
$warehouseId = GETPOSTINT('warehouse_id');
$show_files = GETPOSTINT('show_files');
$param = '';
// Récupérer les paramètres d'URL
$dateStartDay = GETPOSTINT('date_startday');
$dateStartMonth = GETPOSTINT('date_startmonth');
$dateStartYear = GETPOSTINT('date_startyear');
$dateStartHour = GETPOSTINT('date_starthour');
$dateStartMin = GETPOSTINT('date_startmin');

$dateEndDay = GETPOSTINT('date_endday');
$dateEndMonth = GETPOSTINT('date_endmonth');
$dateEndYear = GETPOSTINT('date_endyear');
$dateEndHour = GETPOSTINT('date_endhour');
$dateEndMin = GETPOSTINT('date_endmin');


$startDate = dol_mktime($dateStartHour, $dateStartMin, 0, $dateStartMonth, $dateStartDay, $dateStartYear,'tzuserrel');
$endDate = dol_mktime($dateEndHour, $dateEndMin, 59, $dateEndMonth, $dateEndDay, $dateEndYear,'tzuserrel');
if (empty($startDate)) {
	$startDate = dol_get_first_hour(dol_now(), 'tzuserrel');
}
if (empty($endDate)) {
	$endDate = dol_get_last_hour(dol_now(), 'tzuserrel');
}
if (empty($warehouseId)) {
	$warehouseId = getDolGlobalInt('INVENTAIREPLUS_STOCK_SHEET_WAREHOUSE_ID');
}


// Security check - Protection if external user
$socid = GETPOST('socid', 'int');
if (isset($user->socid) && $user->socid > 0) {
	accessforbidden();
}
if (!$user->hasRight('inventaireplus', 'stock_cashier_sheet', 'read')) {
	accessforbidden();
}



/*
 * Actions
 */
if((getDolGlobalInt('INVENTAIREPLUS_STOCK_SHEET_ENABLED') == 1)){
	if ($action == 'generate') {
		require_once DOL_DOCUMENT_ROOT.'/custom/inventaireplus/core/modules/stock/doc/pdf_stockcashiersheet.modules.php';
		$pdfmodelrecap = new pdf_stockcashiersheet($db);
		// Define output language (Here it is not used because we do only merging existing PDF)
		$outputlangs = $langs;
		$newlang = '';
		if (getDolGlobalInt('MAIN_MULTILANGS') && empty($newlang) && GETPOST('lang_id', 'aZ09')) {
			$newlang = GETPOST('lang_id', 'aZ09');
		}
		if (!empty($newlang)) {
			$outputlangs = new Translate("", $conf);
			$outputlangs->setDefaultLang($newlang);
		}
		//passer les éléments nécessaires à la fonction write de l'instance du modèle
		$parameters = array();
		// Utiliser glob pour obtenir une liste de fichiers PDF
		$files = glob($diroutputgenerate . '/fiche_stock_caissier*.pdf');
		// Utiliser count pour obtenir le nombre de fichiers PDF
		$nombreDeFichiersPDF = count($files);
		$parameters['diroutputgenerate'] = $diroutputgenerate;
		$parameters['caissier'] = $userId ;
		$parameters['warehouse_id'] = $warehouseId;
		$parameters['startdate'] = $startDate;
		$parameters['enddate'] = $endDate;
		$parameters['reference'] = $nombreDeFichiersPDF + 1;
		//pour récupérer le nom complet de l'agent de caisse stock
		require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
		$caissier = new User($db);
		$caissier->fetch($userId);
		$parameters['caissierlastname'] = $caissier->lastname;
		$parameters['caissierfirstname'] = $caissier->firstname;
		$res = $pdfmodelrecap->write_file($parameters, $outputlangs);
		if ($res <= 0) {
			setEventMessages($pdfmodelrecap->error, $pdfmodelrecap->errors, 'errors');
		}
	}
}

/*
 * View
 */

$formfile = new FormFile($db);
$formproduct = new FormProduct($db);

llxHeader("", $langs->trans("StockCashierSheetAtDate"), '', '', 0, 0, '', '', '', 'mod-stock page-stockcashiersheet');

print load_fiche_titre($langs->trans("StockCashierSheetAtDate"), '', 'bill');

print dol_get_fiche_head(array(), '', '');

print '<center>';
print '<br>'.$langs->trans("DateRequeteShort").dol_print_date(dol_now(), 'dayhour');
print '<br>'.$langs->trans("Date de consultation").': '.dol_print_date(dol_now(), 'dayhour');
print '</center>';

print '<br><div style="text-align: center; width: 100%; margin: auto;">';

print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" id="formtocreateaction" value="generate">';
$ret = '<label for="date_start">Début Période : </label>';
$ret .= $form->selectDate($startDate ? $startDate : '', 'date_start', 1, 1, 0, '', 1, 0, 0, 'fulldaystart', '', '', '', 1, '', $langs->trans("from"), 'tzuserrel');
$ret .= '<br>';
$ret .= '<label for="date_end">Fin Période : </label>';
$ret .= $form->selectDate($endDate ? $endDate : dol_now(), 'date_end', 1, 1, 0, '', 1, 1, 0, 'fulldayend', '', '', '', 1, '', $langs->trans("to"), 'tzuserrel');
$ret .= '<br>';
$ret .= '<label for="userid" class="fieldrequired">Caissier : </label>';
$ret .= $form->select_dolusers(
	$selected = ($userId > 0 ? $userId : $user->id),
	$htmlname = 'userid',
	$show_empty = 1,
	$exclude = null,
	$disabled = 0,
	$include = '',
	$enableonly = '',
	$force_entity = (int) $conf->entity,
	$maxlength = 0,
	$showstatus = 0,
	$morefilter = '',
	$show_every = 0,
	$enableonlytext = '',
	$morecss = '',
	$notdisabled = 1,
	$outputmode = 0,
	$multiple = false,
	$forcecombo = 0 
);
$ret .= '<br>';
$ret .= '<label for="warehouse_id" class="fieldrequired">'.$langs->trans("Warehouse").' : </label>';
$ret .= $formproduct->selectWarehouses($warehouseId, 'warehouse_id', 'warehouseopen,warehouseinternal', 1, 0, 0, '', 0, 0, array(), 'minwidth200 maxwidth300');
print $ret;
print "</div><br>";
print '<br><div style="text-align: center; width: 100%; margin: auto;">';
print '<br><input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans("Generate")).'"></form>';
//dol_syslog('mon html '.$monthml);

print dol_get_fiche_end();

$hidegeneratedfilelistifempty = 1;
// supprimer fichier
if ($action == 'remove_file') {
	$hidegeneratedfilelistifempty = 0;
	$file = GETPOST('file', 'alphanohtml');
	$langs->load("other");
	$file = preg_replace('/(\.\.[\/\\\\])+/', '', $file);
	$filetodelete = $conf->inventaireplus->dir_output.'/'.$file;
	$baseDirRealPath = realpath($conf->inventaireplus->dir_output);
	$fileRealPath = realpath($filetodelete);
	$isAllowedPath = (!empty($baseDirRealPath) && !empty($fileRealPath) && strpos($fileRealPath, $baseDirRealPath.DIRECTORY_SEPARATOR) === 0);
	$ret = ($isAllowedPath ? dol_delete_file($fileRealPath) : 0);
	if ($ret) {
		setEventMessages($langs->trans("FileWasRemoved", basename($file)), null, 'mesgs');
	} else {
		setEventMessages($langs->trans("ErrorFailToDeleteFile", basename($file)), null, 'errors');
	}
	$action = '';		
}

// Show list of available documents
$urlsource = $_SERVER['PHP_SELF'];
$urlsource .= str_replace('&amp;', '&', $param);
$filedir = $diroutputgenerate;
$delallowed = $user->hasRight("inventaireplus", "stock_cashier_sheet", "read");
$modulepart = 'inventaireplus';
$title = $langs->trans("stockstockcaissierMassZone").' <a href="" id="togglemassfilesarea" ref="shown">('.$langs->trans("Hide").')</a>';
$title .= '<script nonce="'.getNonce().'">
	jQuery(document).ready(function() {
		jQuery(\'#togglemassfilesarea\').click(function() {
			if (jQuery(\'#togglemassfilesarea\').attr(\'ref\') == "shown")
			{
				jQuery(\'#'.$modulepart.'_table\').hide();
				jQuery(\'#togglemassfilesarea\').attr("ref", "hidden");
				jQuery(\'#togglemassfilesarea\').text("('.dol_escape_js($langs->trans("Show")).')");
			}
			else
			{
				jQuery(\'#'.$modulepart.'_table\').show();
				jQuery(\'#togglemassfilesarea\').attr("ref","shown");
				jQuery(\'#togglemassfilesarea\').text("('.dol_escape_js($langs->trans("Hide")).')");
			}
			return false;
		});
	});
	</script>';

print $formfile->showdocuments($modulepart, 'stock_cashier_sheet', $filedir, $urlsource, 0, $delallowed, '', 1, 1, 0, 48, 1, $param, $title, '', '', '', null, $hidegeneratedfilelistifempty);

// End of page
llxFooter();
$db->close();



