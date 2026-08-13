<?php
/* Copyright (C) 2023	   Omega Junior        <omegajunior.apps@gmail.com>
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 * 
 * Copyright (C) 2023	   Omega Junior        <omegajunior.apps@gmail.com>
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 * or see https://www.gnu.org/
 */

/**
 *  \file       inventaireplus/core/modules/stock/doc/pdf_stockcashiersheet.modules.php
 *  \ingroup    recapitulatif
 *  \brief      File of class to generate cashier stock sheet
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/facture/modules_facture.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';


/**
 *	Class to manage cashier stock sheet PDF
 */
class pdf_stockcashiersheet extends ModelePDFFactures
{
	/**
	 * @var DoliDb Database handler
	 */
	public $db;

	/**
	 * @var string model name
	 */
	public $name;

	/**
	 * @var string model description (short text)
	 */
	public $description;

	/**
	 * @var int     Save the name of generated file as the main doc when generating a doc with this template
	 */
	public $update_main_doc_field;

	/**
	 * @var string document type
	 */
	public $type;

	/**
	 * Dolibarr version of the loaded document
	 * @var string
	 */
	public $version = 'dolibarr';

	/**
	 * @var int page_largeur
	 */
	public $page_largeur;

	/**
	 * @var int page_hauteur
	 */
	public $page_hauteur;

	/**
	 * @var array format
	 */
	public $format;

	/**
	 * @var int marge_gauche
	 */
	public $marge_gauche;

	/**
	 * @var int marge_droite
	 */
	public $marge_droite;

	/**
	 * @var int marge_haute
	 */
	public $marge_haute;

	/**
	 * @var int marge_basse
	 */
	public $marge_basse;


	/**
	 * @var int heightforinfotot
	 */
	public $heightforinfotot;

	/**
	 * @var int heightforfreetext
	 */
	public $heightforfreetext;

	/**
	 * @var int heightforfooter
	 */
	public $heightforfooter;

	/**
	 * @var int tab_top
	 */
	public $tab_top;

	/**
	 * @var int tab_top_newpage
	 */
	public $tab_top_newpage;

	/**
	 * Issuer
	 * @var Societe Object that emits
	 */
	public $emetteur;

	/**
	 * @var bool Situation invoice type
	 */
	public $situationinvoice;


	/**
	 * @var array of document table columns
	 */
	public $cols;

	/**
	 * @var int Category of operation
	 */
	public $categoryOfOperation = -1; // unknown by default

	
	/**
	 * @var string document reference
	 */
	public $titreref;

	/**
	 * @var string Warehouse label printed on document
	 */
	public $warehouseLabel = '';


    public function __construct($db)
	{
		global $conf, $langs, $mysoc;

		// Translations
		$langs->loadLangs(array("main", "bills", "inventaireplus@inventaireplus"));

		$this->db = $db;
		$this->name = "stockcashiersheet";
		$this->description = $langs->trans('PDFStockCashierSheetDescription');
		$this->update_main_doc_field = 1; // Save the name of generated file as the main doc when generating a doc with this template

		// Dimension page
		$this->type = 'pdf';
		$formatarray = pdf_getFormat();
		$this->page_largeur = $formatarray['width'];
		$this->page_hauteur = $formatarray['height'];
		$this->format = array($this->page_largeur, $this->page_hauteur);
		$this->marge_gauche = getDolGlobalInt('MAIN_PDF_MARGIN_LEFT', 10);
		$this->marge_droite = getDolGlobalInt('MAIN_PDF_MARGIN_RIGHT', 10);
		$this->marge_haute = getDolGlobalInt('MAIN_PDF_MARGIN_TOP', 10);
		$this->marge_basse = getDolGlobalInt('MAIN_PDF_MARGIN_BOTTOM', 10);

		$this->option_logo = 1; // Display logo
		$this->option_tva = 1; // Manage the vat option FACTURE_TVAOPTION
		$this->option_modereg = 1; // Display payment mode
		$this->option_condreg = 1; // Display payment terms
		$this->option_multilang = 0; // Available in several languages
		$this->option_escompte = 0; // Displays if there has been a discount
		$this->option_credit_note = 0; // Support credit notes
		$this->option_freetext = 1; // Support add of a personalised text
		$this->option_draft_watermark = 0; // Support add of a watermark on drafts
		$this->watermark = '';

		// Get source company
		$this->emetteur = $mysoc;
		if (empty($this->emetteur->country_code)) {
			$this->emetteur->country_code = substr($langs->defaultlang, -2); // By default, if was not defined
		}

		// Define position of columns
		$this->posxdesc = $this->marge_gauche + 1; // used for notes ans other stuff


		$this->tabTitleHeight = 5; // default height

	}

	/**
	 *  Function to build pdf onto disk
	 *
	 *  @param		mixed		$object				Object to generate or custom parameters array
	 *  @param		Translate	$outputlangs		Lang output object
	 *  @param		string		$srctemplatepath	Full path of source filename for generator using a template file
	 *  @param		int			$hidedetails		Do not show line details
	 *  @param		int			$hidedesc			Do not show desc
	 *  @param		int			$hideref			Do not show ref
	 *  @return     int         	    			1=OK, 0=KO
	 */
	public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		global $user, $langs, $conf, $mysoc, $db, $hookmanager, $nblines;

		$parameters = (is_array($object) ? $object : array());

		dol_syslog("write_file outputlangs->defaultlang=".(is_object($outputlangs) ? $outputlangs->defaultlang : 'null'));

		if (!is_object($outputlangs)) {
			$outputlangs = $langs;
		}
		// For backward compatibility with FPDF, force output charset to ISO, because FPDF expect text to be encoded in ISO
		if (!empty($conf->global->MAIN_USE_FPDF)) {
			$outputlangs->charset_output = 'ISO-8859-1';
		}

		// Load translation files required by the page
		$outputlangs->loadLangs(array("main", "bills", "products", "stocks", "dict", "companies", "inventaireplus@inventaireplus"));

		global $outputlangsbis;
		$outputlangsbis = null;
		if (!empty($conf->global->PDF_USE_ALSO_LANGUAGE_CODE) && $outputlangs->defaultlang != $conf->global->PDF_USE_ALSO_LANGUAGE_CODE) {
			$outputlangsbis = new Translate('', $conf);
			$outputlangsbis->setDefaultLang($conf->global->PDF_USE_ALSO_LANGUAGE_CODE);
			$outputlangsbis->loadLangs(array("main", "bills", "products", "stocks", "dict", "companies", "inventaireplus@inventaireplus"));
		}
		$hidetop = 0;
		
		$startDate = (!empty($parameters['startdate']) ? $parameters['startdate'] : 0);
		$endDate = (!empty($parameters['enddate']) ? $parameters['enddate'] : 0);
		$caissierId = (isset($parameters['caissier']) ? (int) $parameters['caissier'] : -1);
		$entrepot = (!empty($parameters['warehouse_id']) ? (int) $parameters['warehouse_id'] : getDolGlobalInt('INVENTAIREPLUS_STOCK_SHEET_WAREHOUSE_ID'));
		$tmsfacture1 = $startDate;
		$tmsfacture2 = $endDate;

		if (empty($entrepot)) {
			$this->error = $langs->transnoentities('ErrorBadParameters');
			return 0;
		}
		$warehouse = new Entrepot($this->db);
		if ($warehouse->fetch($entrepot) > 0) {
			$this->warehouseLabel = $warehouse->ref;
		} else {
			$this->warehouseLabel = '';
		}

		//la référence qui est le titre à imprimer sur le doc généré
		$this->titreref = 'N° : '.(!empty($parameters['reference']) ? $parameters['reference'] : '');

		$tmsAafficher = array('1'=> $tmsfacture1, '2'=> $tmsfacture2 );
		// Create output dir if not exists
		$dir = (!empty($parameters['diroutputgenerate']) ? $parameters['diroutputgenerate'] : '');
		if (empty($dir)) {
			$this->error = $langs->transnoentities('ErrorBadParameters');
			return 0;
		}
		//dol_mkdir($parameters['diroutputmassaction']);
		if (!file_exists($dir)) {
			if (dol_mkdir($dir) < 0) {
				$this->error = $langs->transnoentities("ErrorCanNotCreateDir", $dir);
				return 0;
			} 
		}

		if (file_exists($dir)) {

			$entreesExpr = "COALESCE(SUM(CASE WHEN sm.value > 0 THEN sm.value ELSE 0 END), 0)";
			$sortiesExpr = "COALESCE(SUM(CASE WHEN sm.value < 0 THEN -sm.value ELSE 0 END), 0)";
			$stockInitialExpr = "(ps.reel - COALESCE(SUM(sm.value), 0))";

			$sql  = "SELECT p.ref, p.label, ps.reel AS stock_final, ";//concat (u.lastname, ' ', u.firstname) AS caissier, 
			$sql .= $entreesExpr." AS entrees, ";
			$sql .= $sortiesExpr." AS sorties, ";
			$sql .= $stockInitialExpr." AS stock_initial ";
			$sql .= "FROM ".MAIN_DB_PREFIX."product AS p ";
			$sql .= "JOIN ".MAIN_DB_PREFIX."product_stock AS ps ON p.rowid = ps.fk_product ";
			$sql .= "JOIN ".MAIN_DB_PREFIX."entrepot AS e ON e.rowid = ps.fk_entrepot ";
			$sql .= "LEFT JOIN ".MAIN_DB_PREFIX."stock_mouvement AS sm ON p.rowid = sm.fk_product ";
			//$sql .= "LEFT JOIN ".MAIN_DB_PREFIX."user AS u ON sm.fk_user_author = u.rowid ";
			$sql .= "AND ps.fk_entrepot = sm.fk_entrepot ";
			$sql .= "AND (sm.datem BETWEEN '". $db->idate($startDate) ."' AND '". $db->idate($endDate) ."') ";
			if ($caissierId!=-1){
				$sql .= "AND sm.fk_user_author = ". (int) $caissierId;
			}			
			$sql .= " WHERE ps.fk_entrepot = ". (int) $entrepot;
			$sql .= " AND p.entity = ".(int) $conf->entity;
			$sql .= " AND e.entity IN (".getEntity('stock').")";
			$sql .= " GROUP BY p.rowid, ps.reel";
			if (getDolGlobalInt('INVENTAIREPLUS_RESTRICT_STOCK_SHEET')) {
				$sql .= " HAVING ".$entreesExpr." <> 0";
				$sql .= " OR ".$sortiesExpr." <> 0";
				$sql .= " OR ".$stockInitialExpr." <> ps.reel";
			}
			$sql .= " ORDER BY p.label ASC, p.ref ASC";
			$resql = $this->db->query($sql);
			
			if ($resql) {
				//nombre de lignes
				$nblines = $this->db->num_rows($resql);
				//first itération sur l'objet requête pour pouvoir récupérer une ref de facture et avoir objet
				$objectsql = $db->fetch_object($resql);
				//inclusion du fichier pour créer une facture
				require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
				$object = new Facture($this->db);
				$ret = $object->fetch(null);

				// Create pdf instance
				$pdf = pdf_getInstance($this->format);
				$default_font_size = pdf_getPDFFontSize($outputlangs); // Must be after pdf_getInstance
				$pdf->SetAutoPageBreak(1, 0);

				$this->heightforinfotot = 18 + 3; // Height reserved to output the info and total part and payment part
				$this->heightforfreetext = (isset($conf->global->MAIN_PDF_FREETEXT_HEIGHT) ? $conf->global->MAIN_PDF_FREETEXT_HEIGHT : 5); // Height reserved to output the free text on last page
				$this->heightforfooter = $this->marge_basse + (empty($conf->global->MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS) ? 12 : 22); // Height reserved to output the footer (value include bottom margin)

				if (class_exists('TCPDF')) {
					$pdf->setPrintHeader(false);
					$pdf->setPrintFooter(false);
				}
				$pdf->SetFont(pdf_getPDFFont($outputlangs));
				
				$pdf->Open();
				$pagenb = 0;
				$pdf->SetDrawColor(128, 128, 128);

				$pdf->SetTitle($outputlangs->convToOutputCharset($this->titreref));
				$pdf->SetSubject($outputlangs->transnoentities("PdfStockCashierSheetTitle"));
				$pdf->SetCreator("InventairePlus ".DOL_VERSION);
				$pdf->SetAuthor($mysoc->name.($user->id > 0 ? ' - '.$outputlangs->convToOutputCharset($user->getFullName($outputlangs)) : ''));
				$thirdpartyNameForPdf = '';
				if (!empty($object->thirdparty) && is_object($object->thirdparty) && !empty($object->thirdparty->name)) {
					$thirdpartyNameForPdf = $object->thirdparty->name;
				}
				$pdf->SetKeyWords($outputlangs->convToOutputCharset($this->titreref)." ".$outputlangs->transnoentities("PdfStockCashierSheetTitle")." ".$outputlangs->convToOutputCharset($thirdpartyNameForPdf));
				if (getDolGlobalString('MAIN_DISABLE_PDF_COMPRESSION')) {
					$pdf->SetCompression(false);
				}

				// Set certificate
				$cert = empty($user->conf->CERTIFICATE_CRT) ? '' : $user->conf->CERTIFICATE_CRT;
				$certprivate = empty($user->conf->CERTIFICATE_CRT_PRIVATE) ? '' : $user->conf->CERTIFICATE_CRT_PRIVATE;
				// If user has no certificate, we try to take the company one
				if (!$cert) {
					$cert = empty($conf->global->CERTIFICATE_CRT) ? '' : $conf->global->CERTIFICATE_CRT;
				}
				if (!$certprivate) {
					$certprivate = empty($conf->global->CERTIFICATE_CRT_PRIVATE) ? '' : $conf->global->CERTIFICATE_CRT_PRIVATE;
				}
				// If a certificate is found
				if ($cert) {
					$info = array(
						'Name' => $this->emetteur->name,
						'Location' => getCountry($this->emetteur->country_code, 0),
						'Reason' => 'RECAP',
						'ContactInfo' => $this->emetteur->email
					);
					$pdf->setSignature($cert, $certprivate, $this->emetteur->name, '', 2, $info);
				}

				$pdf->SetMargins($this->marge_gauche, $this->marge_haute, $this->marge_droite); // Left, Top, Right
				
				// New page
				$pdf->AddPage();
				$pagenb++;

				// Output header (logo, ref and address blocks). This is first call for first page.
				$pagehead = $this->_pagehead($pdf, $object, $tmsAafficher, 1, $outputlangs, $outputlangsbis);
				$top_shift = $pagehead['top_shift'];
				$shipp_shift = $pagehead['shipp_shift'];
				$pdf->SetFont('', '', $default_font_size - 1);
				$pdf->MultiCell(0, 3, ''); // Set interline to 3
				$pdf->SetTextColor(0, 0, 0);
				$pdf->setPageOrientation('', 1, $this->heightforfooter);

				// $this->tab_top is y where we must continue content (90 = 42 + 48: 42 is height of logo and ref, 48 is address blocks)
				$this->tab_top = 90 + $top_shift + $shipp_shift;		// top_shift is an addition for linked objects or addons (0 in most cases)
				$this->tab_top_newpage = (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD') ? 42 + $top_shift : 10);
				
				// Define heigth of table for lines (for first page)
				$tab_height = $this->page_hauteur - $this->tab_top - $this->heightforfooter - $this->heightforfreetext;

				$nexY = $this->tab_top - 1;

				$pagenb = $pdf->getPage();

				$this->prepareArrayColumnField($object, $outputlangs, $hidedetails, $hidedesc, $hideref);

				$nexY = $this->tab_top + $this->tabTitleHeight;

				//$nblines = $this->db->num_rows($resql);
				// Loop on each lines
				$pageposbeforeprintlines = $pdf->getPage();
				$pagenb = $pageposbeforeprintlines;

				//totaux bas de page
				$totalcaisse = 0;

				$curY = $nexY;

				$currentY = 0;

				$hcell = 4;

				//tableau des longueurs des tableaux sur chaque pages
				$lgTableonPage = array();

				for ($i = 1; $i <= $nblines; $i++) {
					$pdf->SetFont('', '', $default_font_size - 1); // Into loop to work with multipage
					$pdf->SetTextColor(0, 0, 0);

					$hcell += 8;

					$pageposbefore = $pdf->getPage();

                    //$objectsql = $db->fetch_object($resql);

					$pdf->SetXY($this->getColumnContentXStart('num'), $curY);

					//colonne "num"
					$pdf->Cell($this->cols['num']['width'], 8, $i,'TRB',0,'C',0,'',1,false,'T','M');
					/* $nexY = max($pdf->GetY(), $nexY);*/
					$curY = $pdf->GetY(); 

					//colonne "code"
					$pdf->Cell($this->cols['ref']['width'], 8, $objectsql->ref,1,0,'C',0,'',1,false,'T','M');
					$curY = $pdf->GetY();

					//colonne "label"
					$pdf->Cell($this->cols['label']['width'], 8,$objectsql->label,1,0,'L',0,'',1,false,'T','M');
					
					//colonne "stock initial"
					$pdf->Cell($this->cols['stockinit']['width'], 8, $objectsql->stock_initial,1,0,'C',0,'',1,false,'T','M');
					$curY = $pdf->GetY();

					//colonne "Entrées"
					$pdf->Cell($this->cols['entrees']['width'], 8, $objectsql->entrees,1,0,'C',0,'',1,false,'T','M');
					$curY = $pdf->GetY();

					//colonne "Sorties"
					$pdf->Cell($this->cols['sorties']['width'], 8, $objectsql->sorties,1,0,'C',0,'',1,false,'T','M');
					$curY = $pdf->GetY();

					//colonne "stock final"
					$pdf->Cell($this->cols['stockfinal']['width'], 8, $objectsql->stock_final,'LTB',1,'C',0,'',1,false,'T','M');
					$totalcaisse += (isset($objectsql->total_parttotassure) ? price2num($objectsql->total_parttotassure, 'MT') : 0);
					$curY = $pdf->GetY();
					$pageposafter = $pdf->getPage();
					if ($pageposafter > $pageposbefore) {
						$lgTableonPage[$pageposbefore] = $hcell;
						$hcell = 0;
						$pageposbefore = $pageposafter;						
					}
					
					//récupérer y sur la page courante
					$currentY = $pdf->GetY();

					// Detect if some page were added automatically and output _tableau for past pages
					while ($pagenb < $pageposafter) {
						$pdf->setPage($pagenb);
						$pdf->setPageOrientation('', 1, 0);
						//$pdf->setPageOrientation('', 1, 10);
						if ($pagenb == $pageposbeforeprintlines) {
							$this->_tableau($pdf, $this->tab_top, $lgTableonPage[$pagenb], 0, $outputlangs, $hidetop, 1, $object->multicurrency_code, $outputlangsbis);
						} else {
							$this->_tableau($pdf, $this->tab_top_newpage, $lgTableonPage[$pagenb], 0, $outputlangs, 1, 1, $object->multicurrency_code, $outputlangsbis);
						}
						$this->_pagefoot($pdf, $object, $outputlangs, 1);
						//remettre à défault
						$pdf->setPageOrientation('', 1, $this->heightforfooter);
						$pagenb++;
						$pdf->setPage($pagenb);
						$pdf->setPageOrientation('', 1, $this->heightforfooter);
					}
					//repositionner y sur la page courante
					$pdf->SetY($currentY);

					//on va itérer sur l'objet requête tant on est pas au dernier
					if ($i != $nblines){
						$objectsql = $db->fetch_object($resql);
					}
				}
				if (!isset($lgTableonPage[$pagenb])) {
					$tableTopForCurrentPage = ($pagenb == $pageposbeforeprintlines ? $this->tab_top : $this->tab_top_newpage);
					$lgTableonPage[$pagenb] = max(0, $currentY - $tableTopForCurrentPage);
				}

				// Show square				
				if ($pagenb == $pageposbeforeprintlines) {
					$pdf->setPageOrientation('', 1, 0);
					$this->_tableau($pdf, $this->tab_top, $lgTableonPage[$pagenb], 0, $outputlangs, $hidetop, 1, $conf->currency, $outputlangsbis);
					$this->_pagefoot($pdf, $object, $outputlangs, 1);
					$bottomlasttab = $currentY + 12;
				} else {
					$pdf->setPageOrientation('', 1, 0);
					$this->_tableau($pdf, $this->tab_top_newpage, $lgTableonPage[$pagenb], 0, $outputlangs, 1, 1, $conf->currency, $outputlangsbis);
					$this->_pagefoot($pdf, $object, $outputlangs, 1);
					$bottomlasttab = $currentY + 12;
				}
								
				// Display total zone
				//$posy = $this->drawTotalTable($pdf, $object, $totalcaisse, $bottomlasttab, $outputlangs, $outputlangsbis); 

				$posy = $bottomlasttab;
                //total en toutes lettres
				if ($posy > $this->page_hauteur - 8 - $this->heightforfooter) {			
					$pdf->AddPage();
					$this->_pagefoot($pdf, $object, $outputlangs, 1);
					if (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD')) {
						$this->_pagehead($pdf, $object, 0, $outputlangs, $outputlangsbis);
						$pdf->setY($this->tab_top_newpage);
					} else {
						$pdf->setY($this->marge_haute);
					}
					$posy = $pdf->GetY();
				}
				
				/* 				$pdf->Text($this->marge_droite, $posy + 8, 'Arrêté le présent récapitulatif à la somme de  : ');
				$pdf->SetFont('','B',$default_font_size);
				$mposx =$this->marge_droite + ($pdf->GetStringWidth('Arrêté le présent récapitulatif à la somme de  : ')) + 2;
				$pdf->SetXY($mposx, $posy + 8);
				$pdf->MultiCell(0, 4, $langs->getLabelFromNumber($totalcaisse,1),'','L'); */
				//$posy+=4;

				//les signatures sont nécessaires dans tous les cas sauf en cas d'acompte
				//pour les info en bas pour les signature	
				$posy = $bottomlasttab; //($this->page_hauteur - $this->heightforfooter) - 20;			
				$pdf->SetFont('','B',$default_font_size);
				//$mposx=(200-$pdf->GetStringWidth('Chef Service Financier et Comptable'));
				$pdf->Text(130, $posy + 20, $parameters['caissierlastname'].' '.$parameters['caissierfirstname']);
				
				if (method_exists($pdf, 'AliasNbPages')) {
					$pdf->AliasNbPages();
				}

				$pdf->Close();

				// Defined name of created file file
				$filename = strtolower(dol_sanitizeFileName($langs->transnoentities("stockcashiersheet")));
				$filename = preg_replace('/\s/', '_', $filename);

				$now = dol_now();				
				$dateforfile = preg_replace('/[\s\/:]/', '_', dol_print_date($tmsfacture2, 'day', 'tzuserrel'));
				$hourforfilestart = preg_replace('/[\s\/:]/', '_', dol_print_date($tmsfacture1, 'hour', 'tzuserrel'));
				$hourforfileend = preg_replace('/[\s\/:]/', '_', dol_print_date($tmsfacture2, 'hour', 'tzuserrel'));
				$warehouseforfile = (!empty($this->warehouseLabel) ? dol_sanitizeFileName(preg_replace('/[\s\/:]/', '_', $this->warehouseLabel)) : 'entrepot_'.$entrepot);
				if ($caissierId!=-1){
					$nameforfile = dol_sanitizeFileName(preg_replace('/[\s\/:]/', '_', ($parameters['caissierlastname'].$parameters['caissierfirstname'])));
					$basenameforfile = dol_sanitizeFileName($filename.'_'.$warehouseforfile.'_'.$nameforfile.'_'.$dateforfile.'_'.$hourforfilestart.'_A_'.$hourforfileend);
					$file = $dir.'/'.$basenameforfile.'.pdf';
				} else {
					$basenameforfile = dol_sanitizeFileName($filename.'_'.$warehouseforfile.'_'.$dateforfile.'_'.$hourforfilestart.'_A_'.$hourforfileend);
					$file = $dir.'/'.$basenameforfile.'.pdf';
				}				
				
				$pdf->Output($file, 'F');	

				/* 				if (!empty($conf->global->MAIN_UMASK)) {
					@chmod($file, octdec($conf->global->MAIN_UMASK));
				} */

				$this->result = array('fullpath'=>$file);
			}

			return 1;
		} else {
			return 0;
		}
    }

    /**
	 *  Show total to pay
	 *
	 *  @param	TCPDF		$pdf            Object PDF
	 *	@param  Facture		$object         Object invoice
	 *	@param  int			$deja_regle     Amount already paid (in the currency of invoice)
	 *	@param	int			$posy			Position depart
	 *	@param	Translate	$outputlangs	Objet langs
	 *  @param  Translate	$outputlangsbis	Object lang for output bis
	 *	@return int							Position pour suite
	 */
	protected function drawTotalTable(&$pdf, $object, $totalmontant, $posy, $outputlangs, $outputlangsbis)
	{
		global $conf, $mysoc, $hookmanager;

		$default_font_size = pdf_getPDFFontSize($outputlangs);

		$tab2_top = $posy;
		$tab2_hl = 4;
		if (is_object($outputlangsbis)) {	// When we show 2 languages we need more room for text, so we use a smaller font.
			$pdf->SetFont('', 'B', $default_font_size + 2);
		} else {
			$pdf->SetFont('', 'B', $default_font_size + 2);
		}

		// Total table
		$col1x = 120;
		$col2x = 170;
		if ($this->page_largeur < 210) { // To work with US executive format
			$col1x -= 15;
			$col2x -= 10;
		}
		$largcol2 = ($this->page_largeur - $this->marge_droite - $col2x);

		$useborder = 0;
		$index = 0;
		if ($posy > $this->page_hauteur - 4 - $this->heightforfooter) {
			$this->_pagefoot($pdf, $object, $outputlangs, 1, $this->getHeightForQRInvoice($pdf->getPage(), $object, $outputlangs));
			$pdf->AddPage();
			if (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD')) {
				$this->_pagehead($pdf, $object, 0, $outputlangs, $outputlangsbis);
				$pdf->setY($this->tab_top_newpage);
			} else {
				$pdf->setY($this->marge_haute);
			}
			$posy = $pdf->GetY();
		}
		
		$posy += $tab2_hl;
		$index++;
		$pdf->SetTextColor(0, 0, 60);
		$pdf->SetFillColor(224, 224, 224);
		$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
		$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("TotalCaisses"), $useborder, 'L', 1);
		$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
		$pdf->MultiCell($largcol2, $tab2_hl, price($totalmontant), $useborder, 'R', 1);

		$pdf->SetFont('', '', $default_font_size - 1);
		$pdf->SetTextColor(0, 0, 0);

		$index++;
		return ($tab2_top + ($tab2_hl * $index));
	}

	/**
	 *  Show total to pay
	 *
	 *  @param	TCPDF		$pdf            Object PDF
	 *	@param  Facture		$object         Object invoice
	 *	@param  int			$deja_regle     Amount already paid (in the currency of invoice)
	 *	@param	int			$posy			Position depart
	 *	@param	Translate	$outputlangs	Objet langs
	 *  @param  Translate	$outputlangsbis	Object lang for output bis
	 *	@return int							Position pour suite
	 */


	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 *  Return list of active generation modules
	 *
	 *  @param	DoliDB	$db     			Database handler
	 *  @param  integer	$maxfilenamelength  Max length of value to show
	 *  @return	array						List of templates
	 */
	public static function liste_modeles($db, $maxfilenamelength = 0)
	{
		// phpcs:enable
		return parent::liste_modeles($db, $maxfilenamelength); // TODO: Change the autogenerated stub
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	/**
	 *   Show table for lines
	 *
	 *   @param		TCPDF		$pdf     		Object PDF
	 *   @param		string		$tab_top		Top position of table
	 *   @param		string		$tab_height		Height of table (rectangle)
	 *   @param		int			$nexY			Y (not used)
	 *   @param		Translate	$outputlangs	Langs object
	 *   @param		int			$hidetop		1=Hide top bar of array and title, 0=Hide nothing, -1=Hide only title
	 *   @param		int			$hidebottom		Hide bottom bar of array
	 *   @param		string		$currency		Currency code
	 *   @param		Translate	$outputlangsbis	Langs object bis
	 *   @return	void
	 */
	protected function _tableau(&$pdf, $tab_top, $tab_height, $nexY, $outputlangs, $hidetop = 0, $hidebottom = 0, $currency = '', $outputlangsbis = null)
	{
		global $conf;

		// Force to disable hidetop and hidebottom
		$hidebottom = 0;
		if ($hidetop) {
			$hidetop = -1;
		}

		$currency = !empty($currency) ? $currency : $conf->currency;
		$default_font_size = pdf_getPDFFontSize($outputlangs);

		// Amount in (at tab_top - 1)
		$pdf->SetTextColor(0, 0, 0);
		$pdf->SetFont('', '', $default_font_size - 2);

		if (empty($hidetop)) {

			$titre = $outputlangs->transnoentities("QtyInUnit");
			if (!empty($conf->global->PDF_USE_ALSO_LANGUAGE_CODE) && is_object($outputlangsbis)) {
				$titre .= ' - '.$outputlangsbis->transnoentities("QtyInUnit");
			}

			$pdf->SetXY($this->page_largeur - $this->marge_droite - ($pdf->GetStringWidth($titre) + 3), $tab_top - 4);
			$pdf->MultiCell(($pdf->GetStringWidth($titre) + 3), 2, $titre);

			//$conf->global->MAIN_PDF_TITLE_BACKGROUND_COLOR='230,230,230';
			if (!empty($conf->global->MAIN_PDF_TITLE_BACKGROUND_COLOR)) {
				$pdf->Rect($this->marge_gauche, $tab_top, $this->page_largeur - $this->marge_droite - $this->marge_gauche, $this->tabTitleHeight, 'F', null, explode(',', $conf->global->MAIN_PDF_TITLE_BACKGROUND_COLOR));
			}
		}

		$pdf->SetDrawColor(128, 128, 128);
		$pdf->SetFont('', '', $default_font_size - 1);

		// Output Rect
		$this->printRect($pdf, $this->marge_gauche, $tab_top, $this->page_largeur - $this->marge_gauche - $this->marge_droite, $tab_height, $hidetop, $hidebottom); // Rect takes a length in 3rd parameter and 4th parameter


		$this->pdfTabTitles($pdf, $tab_top, $tab_height, $outputlangs, $hidetop);

		if (empty($hidetop)) {
			$pdf->line($this->marge_gauche, $tab_top + $this->tabTitleHeight, $this->page_largeur - $this->marge_droite, $tab_top + $this->tabTitleHeight); // line takes a position y in 2nd parameter and 4th parameter
		}
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	/**
	 *  Show top header of page. This include the logo, ref and address blocs
	 *
	 *  @param	TCPDF		$pdf     		Object PDF
	 *  @param  Facture		$object     	Object to show
	 *  @param  int	    	$showaddress    0=no, 1=yes (usually set to 1 for first page, and 0 for next pages)
	 *  @param  Translate	$outputlangs	Object lang for output
	 *  @param  Translate	$outputlangsbis	Object lang for output bis
	 *  @return	array							top shift of linked object lines
	 */
	protected function _pagehead(&$pdf, $object, $tmsAafficher, $showaddress, $outputlangs, $outputlangsbis = null)
	{
		global $conf, $langs;

		$ltrdirection = 'L';
		if ($outputlangs->trans("DIRECTION") == 'rtl') $ltrdirection = 'R';

		// Load traductions files required by page
		$outputlangs->loadLangs(array("main", "bills", "stocks", "companies", "inventaireplus@inventaireplus"));

		$default_font_size = pdf_getPDFFontSize($outputlangs);

		pdf_pagehead($pdf, $outputlangs, $this->page_hauteur);

		$pdf->SetTextColor(0, 0, 60);
		$pdf->SetFont('', 'B', $default_font_size + 3);

		$w = 110;

		$posy = $this->marge_haute;
		$posx = $this->page_largeur - $this->marge_droite - $w;

		$pdf->SetXY($this->marge_gauche, $posy);

		// Logo
		if (!getDolGlobalInt('PDF_DISABLE_MYCOMPANY_LOGO')) {
			if ($this->emetteur->logo) {
				$logodir = $conf->mycompany->dir_output;
				if (!empty($conf->mycompany->multidir_output[$object->entity])) {
					$logodir = $conf->mycompany->multidir_output[$object->entity];
				}
				if (!getDolGlobalInt('MAIN_PDF_USE_LARGE_LOGO')) {
					$logo = $logodir.'/logos/thumbs/'.$this->emetteur->logo_small;
				} else {
					$logo = $logodir.'/logos/'.$this->emetteur->logo;
				}
				if (is_readable($logo)) {
					$height = pdf_getHeightForLogo($logo);
					$pdf->Image($logo, $this->marge_gauche, $posy, 0, $height); // width=0 (auto)
				} else {
					$pdf->SetTextColor(200, 0, 0);
					$pdf->SetFont('', 'B', $default_font_size - 2);
					$pdf->MultiCell($w, 3, $outputlangs->transnoentities("ErrorLogoFileNotFound", $logo), 0, 'L');
					$pdf->MultiCell($w, 3, $outputlangs->transnoentities("ErrorGoToGlobalSetup"), 0, 'L');
				}
			} else {
				$text = $this->emetteur->name;
				$pdf->MultiCell($w, 4, $outputlangs->convToOutputCharset($text), 0, $ltrdirection);
			}
		}

		$pdf->SetFont('', 'B', $default_font_size);
		$pdf->SetXY($posx, $posy);
		$pdf->SetTextColor(0, 0, 60);
		$title = $outputlangs->transnoentities("PdfStockCashierSheetTitle");

		$title .= ' '.$this->titreref;

		$pdf->MultiCell($w, 3, $title, '', 'R');

		$pdf->SetFont('', 'B', $default_font_size);

		$posy += 3;
		$pdf->SetFont('', '', $default_font_size - 2);

		$posy += 4;
		$pdf->SetXY($posx, $posy);
		$pdf->SetTextColor(0, 0, 60);

		$title = $outputlangs->transnoentities("DateBuildFiche");
		if (!empty($conf->global->PDF_USE_ALSO_LANGUAGE_CODE) && is_object($outputlangsbis)) {
			$title .= ' - '.$outputlangsbis->transnoentities("DateBuildFiche");
		}
		//intégration fichier fonctions utiles pour utiliser ici dol_now
		DOL_URL_ROOT.'core/lib/functions.lib.php';
		$pdf->MultiCell($w, 3, $title." : ".dol_print_date(dol_now(), "day", 'tzuserrel', $outputlangs, true), '', 'R');

		if (!empty($this->warehouseLabel)) {
			$posy += 4;
			$pdf->SetXY($posx, $posy);
			$pdf->MultiCell($w, 3, $outputlangs->transnoentities("Warehouse")." : ".$outputlangs->convToOutputCharset($this->warehouseLabel), '', 'R');
		}

		$posy += 1;

		$top_shift = 0;
		$shipp_shift = 0;
		// Show list of linked objects
		$current_y = $pdf->getY();
		//$posy = pdf_writeLinkedObjects($pdf, $object, $outputlangs, $posx, $posy, $w, 3, 'R', $default_font_size);
		if ($current_y < $pdf->getY()) {
			$top_shift = $pdf->getY() - $current_y;
		}

		if ($showaddress) {
			// Sender properties
			$carac_emetteur = '';

			$carac_emetteur .= pdf_build_address($outputlangs, $this->emetteur, '', '', 0, 'source', null);

			// Show sender
			$posy = !empty($conf->global->MAIN_PDF_USE_ISO_LOCATION) ? 40 : 42;
			$posy += $top_shift;
			$posx = $this->marge_gauche;
			if (!empty($conf->global->MAIN_INVERT_SENDER_RECIPIENT)) {
				$posx = $this->page_largeur - $this->marge_droite - 80;
			}

			$hautcadre = !empty($conf->global->MAIN_PDF_USE_ISO_LOCATION) ? 38 : 40;
			$widthrecbox = !empty($conf->global->MAIN_PDF_USE_ISO_LOCATION) ? 92 : 82;

			// Show sender frame
			if (empty($conf->global->MAIN_PDF_NO_SENDER_FRAME)) {
				$pdf->SetTextColor(0, 0, 0);
				$pdf->SetFont('', '', $default_font_size - 2);
				$pdf->SetXY($posx, $posy - 5);
				$pdf->MultiCell($widthrecbox, 5, $outputlangs->transnoentities("BillFrom"), 0, $ltrdirection);
				$pdf->SetXY($posx, $posy);
				$pdf->SetFillColor(230, 230, 230);
				$pdf->MultiCell($widthrecbox, $hautcadre, "", 0, 'R', 1);
				$pdf->SetTextColor(0, 0, 60);
			}

			// Show sender name
			if (empty($conf->global->MAIN_PDF_HIDE_SENDER_NAME)) {
				$pdf->SetXY($posx + 2, $posy + 3);
				$pdf->SetFont('', 'B', $default_font_size);
				$pdf->MultiCell($widthrecbox - 2, 4, $outputlangs->convToOutputCharset($this->emetteur->name), 0, $ltrdirection);
				$posy = $pdf->getY();
			}

			// Show sender information
			$pdf->SetXY($posx + 2, $posy);
			$pdf->SetFont('', '', $default_font_size - 1);
			$pdf->MultiCell($widthrecbox - 2, 4, $carac_emetteur, 0, $ltrdirection);


			// Recipient name

				//$thirdparty = $tmsAafficher[0];


			$carac_client_name = $tmsAafficher[1];

			
			$carac_client = $tmsAafficher[2];

			// Show recipient
			$widthrecbox = !empty($conf->global->MAIN_PDF_USE_ISO_LOCATION) ? 92 : 100;
			if ($this->page_largeur < 210) {
				$widthrecbox = 84; // To work with US executive format
			}
			$posy = !empty($conf->global->MAIN_PDF_USE_ISO_LOCATION) ? 40 : 42;
			$posy += $top_shift;
			$posx = $this->page_largeur - $this->marge_droite - $widthrecbox;
			if (!empty($conf->global->MAIN_INVERT_SENDER_RECIPIENT)) {
				$posx = $this->marge_gauche;
			}

			// Show recipient frame
			if (empty($conf->global->MAIN_PDF_NO_RECIPENT_FRAME)) {
				$pdf->SetTextColor(0, 0, 0);
				$pdf->SetFont('', '', $default_font_size - 2);
				$pdf->SetXY($posx + 2, $posy - 5);
				$pdf->MultiCell($widthrecbox - 2, 5, $outputlangs->transnoentities("RecapTo"), 0, $ltrdirection);
				$pdf->Rect($posx, $posy, $widthrecbox, $hautcadre);
			}

			// Show recipient name
			$pdf->SetXY($posx + 2, $posy + 3);
			$pdf->SetFont('', 'B', $default_font_size);
			$pdf->MultiCell($widthrecbox - 2, 2, 'Date Heure Début : '.dol_print_date($carac_client_name, 'dayhour', 'tzuserrel'), 0, $ltrdirection);

			$posy = $pdf->getY();

			// Show recipient information
			$pdf->SetXY($posx + 2, $posy);
			$pdf->MultiCell($widthrecbox - 2, 4, 'Date Heure Fin : '.dol_print_date($carac_client, 'dayhour', 'tzuserrel'), 0, $ltrdirection);

		}

		$pdf->SetTextColor(0, 0, 0);

		$pagehead = array('top_shift' => $top_shift, 'shipp_shift' => $shipp_shift);
		return $pagehead;
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	/**
	 *   	Show footer of page. Need this->emetteur object
	 *
	 *   	@param	TCPDF		$pdf     			PDF
	 * 		@param	Facture		$object				Object to show
	 *      @param	Translate	$outputlangs		Object lang for output
	 *      @param	int			$hidefreetext		1=Hide free text
	 *      @param	int			$heightforqrinvoice	Height for QR invoices
	 *      @return	int								Return height of bottom margin including footer text
	 */
	protected function _pagefoot(&$pdf, $object, $outputlangs, $hidefreetext = 0, $heightforqrinvoice = 0)
	{
		$showdetails = getDolGlobalInt('MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS', 0);
		return pdf_pagefoot($pdf, $outputlangs, 'INVOICE_FREE_TEXT', $this->emetteur, $heightforqrinvoice + $this->marge_basse, $this->marge_gauche, $this->page_hauteur, $object, $showdetails, $hidefreetext, $this->page_largeur, $this->watermark);
	}

	/**
	 *  Define Array Column Field
	 *
	 *  @param	Facture		   $object    		common object
	 *  @param	Translate	   $outputlangs     langs
	 *  @param	int			   $hidedetails		Do not show line details
	 *  @param	int			   $hidedesc		Do not show desc
	 *  @param	int			   $hideref			Do not show ref
	 *  @return	void
	 */
	public function defineColumnField($object, $outputlangs, $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		global $conf, $hookmanager;

		// Default field style for content
		$this->defaultContentsFieldsStyle = array(
			'align' => 'R', // R,C,L
			'padding' => array(1, 0.5, 1, 0.5), // Like css 0 => top , 1 => right, 2 => bottom, 3 => left
		);

		// Default field style for content
		$this->defaultTitlesFieldsStyle = array(
			'align' => 'C', // R,C,L
			'padding' => array(0.5, 0, 0.5, 0), // Like css 0 => top , 1 => right, 2 => bottom, 3 => left
		);


		$rank = 0; // do not use negative rank
		// col : numéro d'ordre	
		$this->cols['num'] = array(
			'rank' => $rank,
			'width' => 8, // in mm
			'status' => true,
			'title' => array(
				'textkey' => 'N°',
				'align' => 'C',
			),
			'border-left' => false, // add left line separator
			'content' => array(
				'align' => 'C',
			),
		);

		// col ref
		$rank = $rank + 10;
		$this->cols['ref'] = array(
			'rank' => $rank,
			'width' => 20, // in mm
			'status' => true,
			'title' => array(
				'textkey' => 'Code',
				'align' => 'C',
			),
			'border-left' => false, // add left line separator
			'content' => array(
				'align' => 'C',
			),
		);
		
		//col désignation
		$rank = $rank + 10;
		$this->cols['label'] = array(
			'rank' => $rank,
			'width' => false, // in mm
			'status' => true,
			'title' => array(
				'textkey' => "Désignation"
			),
			'border-left' => false, // add left line separator
			'content' => array(
				'align' => 'L',
			),
		);

		$rank = $rank + 10; // do not use negative rank					  
		$this->cols['stockinit'] = array(
			'rank' => $rank,
			'width' => 20, // only for desc
			'status' => true,
			'title' => array(
				'textkey' => 'Stock initial', // use lang key is usefull in somme case with module
				'align' => 'C',
			),
			'content' => array(
				'align' => 'L',
			),
			'border-left' => false, // add left line separator		  
		);

		$rank = $rank + 10; // do not use negative rank					  
		$this->cols['entrees'] = array(
			'rank' => $rank,
			'width' => 20, // only for desc
			'status' => true,
			'title' => array(
				'textkey' => 'Entrées', // use lang key is usefull in somme case with module
				'align' => 'C',
			),
			'content' => array(
				'align' => 'L',
			),
			'border-left' => false, // add left line separator		  
		);

		$rank = $rank + 10; // do not use negative rank					  
		$this->cols['sorties'] = array(
			'rank' => $rank,
			'width' => 20, // only for desc
			'status' => true,
			'title' => array(
				'textkey' => 'Sorties', // use lang key is usefull in somme case with module
				'align' => 'C',
			),
			'content' => array(
				'align' => 'L',
			),
			'border-left' => false, // add left line separator		  
		);

		$rank = $rank + 10; // do not use negative rank					  
		$this->cols['stockfinal'] = array(
			'rank' => $rank,
			'width' => 20, // only for desc
			'status' => true,
			'title' => array(
				'textkey' => 'Stock final', // use lang key is usefull in somme case with module
				'align' => 'C',
			),
			'content' => array(
				'align' => 'L',
			),
			'border-left' => false, // add left line separator		  
		);
	}
}



