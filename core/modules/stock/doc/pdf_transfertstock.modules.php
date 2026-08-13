<?php
/* Copyright (C) 2026 Omega Junior
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 *  \file       inventaireplus/core/modules/stock/doc/pdf_transfertstock.modules.php
 *  \ingroup    stock
 *  \brief      PDF model for stock transfer note generated from stock movements
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/facture/modules_facture.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';
require_once DOL_DOCUMENT_ROOT.'/reception/class/reception.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/inventaireplus/lib/productcategory.lib.php';


/**
 * Class to manage stock transfer PDF generated from stock movements
 */
class pdf_transfertstock extends ModelePDFFactures
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string model name
	 */
	public $name;

	/**
	 * @var string description
	 */
	public $description;

	/**
	 * @var array<int, array<string, mixed>> Table columns
	 */
	public $cols = array();

	/**
	 * @var int Width
	 */
	public $page_largeur;

	/**
	 * @var int Height
	 */
	public $page_hauteur;

	/**
	 * @var array<int, int> Format
	 */
	public $format;

	/**
	 * @var int Left margin
	 */
	public $marge_gauche;

	/**
	 * @var int Right margin
	 */
	public $marge_droite;

	/**
	 * @var int Top margin
	 */
	public $marge_haute;

	/**
	 * @var int Bottom margin
	 */
	public $marge_basse;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs, $mysoc;

		$langs->loadLangs(array('main', 'products', 'stocks', 'inventaireplus@inventaireplus'));

		$this->db = $db;
		$this->name = 'transfertstock';
		$this->description = 'Bordereau de transfert de stock';
		$this->type = 'pdf';
		$formatarray = pdf_getFormat();
		$this->page_largeur = $formatarray['width'];
		$this->page_hauteur = $formatarray['height'];
		$this->format = array($this->page_largeur, $this->page_hauteur);
		$this->marge_gauche = getDolGlobalInt('MAIN_PDF_MARGIN_LEFT', 10);
		$this->marge_droite = getDolGlobalInt('MAIN_PDF_MARGIN_RIGHT', 10);
		$this->marge_haute = getDolGlobalInt('MAIN_PDF_MARGIN_TOP', 10);
		$this->marge_basse = getDolGlobalInt('MAIN_PDF_MARGIN_BOTTOM', 10);
		$this->emetteur = $mysoc;
		$this->cols = array(
			array('key' => 'num', 'label' => 'N°', 'width' => 9, 'align' => 'C'),
			array('key' => 'ref', 'label' => 'REF PRODUIT', 'width' => 20, 'align' => 'L'),
			array('key' => 'designation', 'label' => 'DESIGNATION', 'width' => 65, 'align' => 'L'),
			array('key' => 'qty', 'label' => 'QUANTITE', 'width' => 18, 'align' => 'R'),
		);
		if (isModEnabled('productbatch')) {
			$this->cols[] = array('key' => 'lot', 'label' => 'LOT', 'width' => 23, 'align' => 'L');
			$this->cols[] = array('key' => 'expiry', 'label' => 'DATE DE PEREMPTION', 'width' => 24, 'align' => 'C');
		} else {
			$this->cols[2]['width'] = 88;
		}
		$this->cols[] = array('key' => 'unitprice', 'label' => 'PRIX UNITAIRE (TTC)', 'width' => 22, 'align' => 'R');
		$this->cols[] = array('key' => 'amount', 'label' => 'MONTANT (TTC)', 'width' => 23, 'align' => 'R');
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
	public function write_file($parameters, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		global $conf, $langs, $mysoc, $user;

		if (!is_object($outputlangs)) {
			$outputlangs = $langs;
		}
		if (!empty($conf->global->MAIN_USE_FPDF)) {
			$outputlangs->charset_output = 'ISO-8859-1';
		}
		$outputlangs->loadLangs(array('main', 'products', 'stocks', 'inventaireplus@inventaireplus'));

		$movements = (!empty($parameters['movements']) && is_array($parameters['movements']) ? $parameters['movements'] : array());
		$inventoryCode = (!empty($parameters['inventorycode']) ? (string) $parameters['inventorycode'] : '');
		$sourceWarehouseId = (!empty($parameters['sourcewarehouseid']) ? (int) $parameters['sourcewarehouseid'] : 0);
		$targetWarehouseId = (!empty($parameters['targetwarehouseid']) ? (int) $parameters['targetwarehouseid'] : 0);
		$dir = (!empty($parameters['diroutputmassaction']) ? $parameters['diroutputmassaction'] : '');
		$transferOriginType = (!empty($parameters['transfer_origin_type']) ? (string) $parameters['transfer_origin_type'] : '');
		$transferOriginId = (!empty($parameters['transfer_origin_id']) ? (int) $parameters['transfer_origin_id'] : 0);

		if (empty($movements) || empty($inventoryCode) || $sourceWarehouseId <= 0 || $targetWarehouseId <= 0 || empty($dir)) {
			$this->error = 'Paramètres incomplets pour générer le bordereau de transfert.';
			return 0;
		}
		if (!file_exists($dir) && dol_mkdir($dir) < 0) {
			$this->error = $langs->transnoentities('ErrorCanNotCreateDir', $dir);
			return 0;
		}

		$categoryDataset = $this->buildCategoryDataset($movements);

		$sourceWarehouse = new Entrepot($this->db);
		$sourceWarehouse->fetch($sourceWarehouseId);
		$targetWarehouse = new Entrepot($this->db);
		$targetWarehouse->fetch($targetWarehouseId);

		$firstMovement = reset($movements);
		$transferDate = $this->db->jdate($firstMovement['datem']);
		$transferLabel = (!empty($firstMovement['label']) ? $firstMovement['label'] : '');
		$transferOriginLabel = '';
		if ($transferOriginType === 'reception' && $transferOriginId > 0) {
			$reception = new Reception($this->db);
			if ($reception->fetch($transferOriginId) > 0) {
				$transferOriginLabel = $outputlangs->transnoentities("ReceptionOrigin").' '.(!empty($reception->ref) ? $reception->ref : $transferOriginId);
			}
		}

		$pdf = pdf_getInstance($this->format);
		$defaultFontSize = pdf_getPDFFontSize($outputlangs);
		if (class_exists('TCPDF')) {
			$pdf->setPrintHeader(false);
			$pdf->setPrintFooter(false);
		}
		$pdf->SetAutoPageBreak(true, $this->marge_basse + 10);
		$pdf->SetMargins($this->marge_gauche, $this->marge_haute, $this->marge_droite);
		$pdf->SetFont(pdf_getPDFFont($outputlangs));
		$pdf->SetDrawColor(80, 80, 80);
		$pdf->SetTextColor(0, 0, 0);
		$pdf->SetTitle($outputlangs->convToOutputCharset('BORDEREAU DE TRANSFERT DE STOCK '.$inventoryCode));
		$pdf->SetSubject($outputlangs->convToOutputCharset('Bordereau de transfert de stock'));
		$pdf->SetCreator('InventairePlus '.DOL_VERSION);
		$pdf->SetAuthor($mysoc->name.($user->id > 0 ? ' - '.$outputlangs->convToOutputCharset($user->getFullName($outputlangs)) : ''));
		$pdf->SetKeyWords($outputlangs->convToOutputCharset('transfert stock '.$inventoryCode));
		if (getDolGlobalString('MAIN_DISABLE_PDF_COMPRESSION')) {
			$pdf->SetCompression(false);
		}

		$pdf->Open();
		$metadata = array(
			'inventorycode' => $inventoryCode,
			'transferlabel' => $transferLabel,
			'sourcewarehouse' => $sourceWarehouse->label,
			'targetwarehouse' => $targetWarehouse->label,
			'transferdate' => $transferDate,
			'transferorigintype' => $transferOriginType,
			'transferoriginid' => $transferOriginId,
			'transferoriginlabel' => $transferOriginLabel,
		);
		$pdf->AddPage('P', $this->format);
		$y = $this->_pagehead($pdf, $metadata, $outputlangs);
		$y = $this->renderTableHeader($pdf, $y, $defaultFontSize);

		$lineHeight = 6;
		$categoryHeight = 7;
		$subtotalHeight = 6;
		$bottomLimit = $this->page_hauteur - $this->marge_basse - 25;
		$globalTotal = 0;
		$lineNumber = 1;

		foreach ($categoryDataset['categories'] as $category) {
			if (($y + $categoryHeight + $lineHeight) > $bottomLimit) {
				$pdf->AddPage('P', $this->format);
				$y = $this->renderTableHeader($pdf, $this->marge_haute, $defaultFontSize);
			}

			$y = $this->renderCategoryRow($pdf, $y, $defaultFontSize, $category['label'], (int) ($category['level'] ?? 0));

			foreach ($category['movements'] as $movement) {
				if (($y + $lineHeight) > $bottomLimit) {
					$pdf->AddPage('P', $this->format);
					$y = $this->renderTableHeader($pdf, $this->marge_haute, $defaultFontSize);
					$y = $this->renderCategoryRow($pdf, $y, $defaultFontSize, $category['label'], (int) ($category['level'] ?? 0));
				}

				$rowData = array(
					'num' => $lineNumber,
					'ref' => (!empty($movement['product_ref']) ? $movement['product_ref'] : ''),
					'designation' => dol_trunc((!empty($movement['product_label']) ? $movement['product_label'] : ''), 34),
					'qty' => price($movement['qty'], 0, $outputlangs, 0, -1, -1),
					'lot' => (!empty($movement['batch']) ? $movement['batch'] : ''),
					'expiry' => $movement['expiry_date'],
					'unitprice' => price($movement['unit_price_ttc'], 0, $outputlangs, 0, -1, -1),
					'amount' => price($movement['line_amount_ttc'], 0, $outputlangs, 0, -1, -1),
				);
				$y = $this->renderDataRow($pdf, $y, $defaultFontSize, $rowData, $lineHeight);
				$lineNumber++;
			}

			if (($y + $subtotalHeight) > $bottomLimit) {
				$pdf->AddPage('P', $this->format);
				$y = $this->renderTableHeader($pdf, $this->marge_haute, $defaultFontSize);
			}
			$y = $this->renderSubtotalRow($pdf, $y, $defaultFontSize, 'Total '.str_repeat('   ', (int) ($category['level'] ?? 0)).$category['label'].' (TTC)', $category['total_ttc']);
			$globalTotal = $categoryDataset['total_ttc'];
		}

		$requiredSpaceForTotalsAndSignatures = 28;
		if (($y + $requiredSpaceForTotalsAndSignatures) > ($this->page_hauteur - $this->marge_basse - 8)) {
			$pdf->AddPage('P', $this->format);
			$y = $this->renderTableHeader($pdf, $this->marge_haute, $defaultFontSize);
		}
		$y = $this->renderSubtotalRow($pdf, $y, $defaultFontSize, 'TOTAL GENERAL (TTC)', $globalTotal);
		$this->renderSignatures($pdf, $y + 8, $defaultFontSize);
		$this->_pagefoot($pdf, $outputlangs);

		if (method_exists($pdf, 'AliasNbPages')) {
			$pdf->AliasNbPages();
		}
		$pdf->Close();

		$filename = 'bordereau_transfert_stock_'.$inventoryCode.'_wh_'.$targetWarehouseId;
		$filename = strtolower(dol_sanitizeFileName($filename));
		$filename = preg_replace('/\s+/', '_', $filename);
		$file = $dir.'/'.$filename.'.pdf';

		$pdf->Output($file, 'F');
		$this->result = array('fullpath' => $file);

		return 1;
	}

	/**
	 * Render the page header
	 *
	 * @param TCPDF     $pdf         Pdf object
	 * @param array     $metadata    Transfer metadata
	 * @param Translate $outputlangs Langs
	 * @return float
	 */
	protected function _pagehead(&$pdf, $metadata, $outputlangs)
	{
		global $conf;

		$defaultFontSize = pdf_getPDFFontSize($outputlangs);

		pdf_pagehead($pdf, $outputlangs, $this->page_hauteur);

		$logoTop = $this->marge_haute;
		if (!empty($this->emetteur->logo) && !getDolGlobalInt('PDF_DISABLE_MYCOMPANY_LOGO')) {
			$logodir = $conf->mycompany->dir_output;
			if (!empty($conf->mycompany->multidir_output[$conf->entity])) {
				$logodir = $conf->mycompany->multidir_output[$conf->entity];
			}
			$logo = (!getDolGlobalInt('MAIN_PDF_USE_LARGE_LOGO') ? $logodir.'/logos/thumbs/'.$this->emetteur->logo_small : $logodir.'/logos/'.$this->emetteur->logo);
			if (is_readable($logo)) {
				$height = min(20, pdf_getHeightForLogo($logo));
				$pdf->Image($logo, $this->marge_gauche, $logoTop, 0, $height);
			} else {
				$pdf->SetTextColor(200, 0, 0);
				$pdf->SetFont('', 'B', $defaultFontSize - 2);
				$pdf->SetXY($this->marge_gauche, $logoTop);
				$pdf->MultiCell(65, 4, $outputlangs->transnoentities('ErrorLogoFileNotFound', $logo), 0, 'L');
				$pdf->SetTextColor(0, 0, 0);
			}
		} else {
			$pdf->SetFont('', 'B', $defaultFontSize + 1);
			$pdf->SetXY($this->marge_gauche, $logoTop + 2);
			$pdf->MultiCell(65, 5, $outputlangs->convToOutputCharset((!empty($this->emetteur->name) ? $this->emetteur->name : '')), 0, 'L');
		}

		$titleWidth = 100;
		$titleX = $this->page_largeur - $this->marge_droite - $titleWidth;
		$pdf->SetTextColor(0, 0, 60);
		$pdf->SetFont('', 'B', $defaultFontSize + 3);
		$pdf->SetXY($titleX, $this->marge_haute);
		$pdf->MultiCell($titleWidth, 4, 'BORDEREAU DE TRANSFERT DE STOCK', 0, 'R');
		$pdf->SetFont('', 'B', $defaultFontSize);
		$pdf->SetXY($titleX, $this->marge_haute + 8);
		$pdf->MultiCell($titleWidth, 4, 'Réf. '.$metadata['inventorycode'], 0, 'R');

		$boxTop = 42;
		$boxHeight = 38;
		$leftX = $this->marge_gauche;
		$leftWidth = 88;
		$gap = 4;
		$rightX = $leftX + $leftWidth + $gap;
		$rightWidth = $this->page_largeur - $this->marge_droite - $rightX;

		$pdf->SetTextColor(0, 0, 0);
		$pdf->Rect($leftX, $boxTop, $leftWidth, $boxHeight);
		$pdf->Rect($rightX, $boxTop, $rightWidth, $boxHeight);

		$pdf->SetFont('', 'B', $defaultFontSize);
		$pdf->SetXY($leftX + 2, $boxTop + 2);
		$pdf->Cell($leftWidth - 4, 5, 'Source', 0, 0, 'L');
		$pdf->SetXY($rightX + 2, $boxTop + 2);
		$pdf->Cell($rightWidth - 4, 5, 'Destination', 0, 0, 'L');

		$pdf->SetFont('', '', $defaultFontSize - 1);
		$leftLines = array(
			'Entrepôt de départ : '.(!empty($metadata['sourcewarehouse']) ? $metadata['sourcewarehouse'] : ''),
			'Date : '.dol_print_date($metadata['transferdate'], 'day'),
			'Heure : '.dol_print_date($metadata['transferdate'], 'hour'),
		);
		$rightLines = array(
			'Entrepôt de réception : '.(!empty($metadata['targetwarehouse']) ? $metadata['targetwarehouse'] : ''),
			'Code mouvement : '.(!empty($metadata['inventorycode']) ? $metadata['inventorycode'] : ''),
			'Libellé : '.dol_trunc((!empty($metadata['transferlabel']) ? $metadata['transferlabel'] : ''), 52),
		);
		if (!empty($metadata['transferoriginlabel'])) {
			$rightLines[] = $outputlangs->transnoentities("TransferOrigin").' : '.$metadata['transferoriginlabel'];
		}

		$y = $boxTop + 9;
		foreach ($leftLines as $line) {
			$pdf->SetXY($leftX + 2, $y);
			$pdf->MultiCell($leftWidth - 4, 4, $outputlangs->convToOutputCharset($line), 0, 'L');
			$y += 4.5;
		}

		$y = $boxTop + 9;
		foreach ($rightLines as $line) {
			$pdf->SetXY($rightX + 2, $y);
			$pdf->MultiCell($rightWidth - 4, 4, $outputlangs->convToOutputCharset($line), 0, 'L');
			$y += 4.5;
		}

		return ($boxTop + $boxHeight + 6);
	}

	/**
	 * Render the page footer
	 *
	 * @param TCPDF     $pdf         Pdf object
	 * @param Translate $outputlangs Langs
	 * @return void
	 */
	protected function _pagefoot(&$pdf, $outputlangs)
	{
		$outputlangs->load('dict');

		$line1 = '';
		$line2 = '';
		if (!empty($this->emetteur->name)) {
			$line1 .= ($line1 ? ' - ' : '').$outputlangs->transnoentities('RegisteredOffice').': '.$this->emetteur->name;
		}
		if (!empty($this->emetteur->address)) {
			$line1 .= ($line1 ? ' - ' : '').str_replace("\n", ', ', dol_string_nohtmltag($this->emetteur->address));
		}
		if (!empty($this->emetteur->zip)) {
			$line1 .= ($line1 ? ' - ' : '').$this->emetteur->zip;
		}
		if (!empty($this->emetteur->town)) {
			$line1 .= ($line1 ? ' ' : '').$this->emetteur->town;
		}
		if (!empty($this->emetteur->country)) {
			$line1 .= ($line1 ? ', ' : '').$this->emetteur->country;
		}
		if (!empty($this->emetteur->phone)) {
			$line2 .= ($line2 ? ' - ' : '').$outputlangs->transnoentities('Phone').': '.$this->emetteur->phone;
		}
		if (!empty($this->emetteur->fax)) {
			$line2 .= ($line2 ? ' - ' : '').$outputlangs->transnoentities('Fax').': '.$this->emetteur->fax;
		}
		if (!empty($this->emetteur->url)) {
			$line2 .= ($line2 ? ' - ' : '').$this->emetteur->url;
		}
		if (!empty($this->emetteur->email)) {
			$line2 .= ($line2 ? ' - ' : '').$this->emetteur->email;
		}

		$pdf->SetAutoPageBreak(false, 0);
		$pdf->SetTextColor(60, 60, 60);
		$pdf->SetDrawColor(224, 224, 224);
		$dims = $pdf->getPageDimensions();
		$footerTopY = $this->page_hauteur - $this->marge_basse - 8;
		$pdf->Line($dims['lm'], $footerTopY - 1.5, $dims['wk'] - $dims['rm'], $footerTopY - 1.5);

		if (!empty($line1)) {
			$pdf->SetFont('', 'B', 7);
			$pdf->SetXY($dims['lm'], $footerTopY);
			$pdf->MultiCell($dims['wk'] - $dims['rm'] - $dims['lm'], 2, $outputlangs->convToOutputCharset(dol_trunc($line1, 140)), 0, 'C', false);
		}
		if (!empty($line2)) {
			$pdf->SetFont('', 'B', 7);
			$pdf->SetXY($dims['lm'], $footerTopY + 3);
			$pdf->MultiCell($dims['wk'] - $dims['rm'] - $dims['lm'], 2, $outputlangs->convToOutputCharset(dol_trunc($line2, 140)), 0, 'C', false);
		}

		$pdf->SetFont('', '', 7);
		$pdf->SetXY($dims['wk'] - $dims['rm'] - 18, $footerTopY + 6);
		$pdf->MultiCell(18, 2, $pdf->PageNo().' / '.$pdf->getAliasNbPages(), 0, 'R', false);
		$pdf->SetAutoPageBreak(true, $this->marge_basse + 10);
	}

	/**
	 * Render table header
	 *
	 * @param TCPDF $pdf             Pdf object
	 * @param float $y               Y position
	 * @param int   $defaultFontSize Font size
	 * @return float
	 */
	protected function renderTableHeader(&$pdf, $y, $defaultFontSize)
	{
		$pdf->SetDrawColor(80, 80, 80);
		$pdf->SetTextColor(0, 0, 0);
		$pdf->SetFont('', 'B', $defaultFontSize - 2);
		$x = $this->marge_gauche;
		foreach ($this->cols as $col) {
			$pdf->SetXY($x, $y);
			$pdf->MultiCell($col['width'], 8, $col['label'], 1, 'C', 0, 0);
			$x += $col['width'];
		}
		$pdf->Ln();

		return ($y + 8);
	}

	/**
	 * Build hierarchy-aware category aggregates for transfer movements.
	 *
	 * @param array<int,array<string,mixed>> $movements Stock movements
	 * @return array<string,mixed>
	 */
	protected function buildCategoryDataset(array $movements)
	{
		$dataset = array(
			'categories' => array(),
			'total_ttc' => 0.0,
		);

		foreach ($movements as $movement) {
			$categoryLabel = (!empty($movement['transfer_category_label']) ? (string) $movement['transfer_category_label'] : 'Non classé');
			$categoryNodes = inventaireplusGetCategoryPathNodes($this->db, (int) ($movement['transfer_category_id'] ?? 0), $categoryLabel);
			$leafKey = $categoryNodes[count($categoryNodes) - 1]['key'];

			$qty = abs((float) $movement['value']);
			$unitPriceHt = price2num((string) $movement['price']);
			$taxRate = isset($movement['product_tva_tx']) ? (float) price2num($movement['product_tva_tx'], 'MU') : 0.0;
			$unitPrice = price2num((string) ($unitPriceHt * (1 + ($taxRate / 100))), 'MU');
			$lineAmount = price2num((string) ($qty * $unitPrice), 'MT');
			$expiryDate = '';
			if (!empty($movement['sellby'])) {
				$expiryDate = dol_print_date($this->db->jdate($movement['sellby']), 'day');
			} elseif (!empty($movement['eatby'])) {
				$expiryDate = dol_print_date($this->db->jdate($movement['eatby']), 'day');
			}

			$movement['qty'] = $qty;
			$movement['unit_price_ttc'] = $unitPrice;
			$movement['line_amount_ttc'] = $lineAmount;
			$movement['expiry_date'] = $expiryDate;

			foreach ($categoryNodes as $node) {
				if (!isset($dataset['categories'][$node['key']])) {
					$dataset['categories'][$node['key']] = array(
						'label' => $node['label'],
						'level' => (int) $node['level'],
						'sort' => $node['sort'],
						'movements' => array(),
						'total_ttc' => 0.0,
					);
				}
				if ($node['key'] === $leafKey) {
					$dataset['categories'][$node['key']]['movements'][] = $movement;
				}
				$dataset['categories'][$node['key']]['total_ttc'] += $lineAmount;
			}
			$dataset['total_ttc'] += $lineAmount;
		}

		uasort($dataset['categories'], 'inventaireplusSortCategoryAggregates');
		foreach ($dataset['categories'] as &$category) {
			usort($category['movements'], function ($rowA, $rowB) {
				$refCompare = strcmp((string) ($rowA['product_ref'] ?? ''), (string) ($rowB['product_ref'] ?? ''));
				if ($refCompare !== 0) {
					return $refCompare;
				}

				return ((int) $rowA['rowid'] <=> (int) $rowB['rowid']);
			});
		}
		unset($category);

		return $dataset;
	}

	/**
	 * Render category separator row
	 *
	 * @param TCPDF $pdf             Pdf object
	 * @param float $y               Y position
	 * @param int   $defaultFontSize Font size
	 * @param string $categoryLabel  Category label
	 * @param int    $categoryLevel  Category hierarchy level
	 * @return float
	 */
	protected function renderCategoryRow(&$pdf, $y, $defaultFontSize, $categoryLabel, $categoryLevel = 0)
	{
		$pdf->SetFont('', 'B', $defaultFontSize - 1);
		$pdf->SetFillColor(240, 240, 240);
		$pdf->SetXY($this->marge_gauche, $y);
		$label = str_repeat('   ', (int) $categoryLevel).strtoupper($categoryLabel);
		$pdf->Cell($this->getTableWidth(), 7, $label, 1, 1, ((int) $categoryLevel > 0 ? 'L' : 'C'), 1);

		return ($y + 7);
	}

	/**
	 * Render a movement row
	 *
	 * @param TCPDF $pdf             Pdf object
	 * @param float $y               Y position
	 * @param int   $defaultFontSize Font size
	 * @param array $rowData         Line values
	 * @param float $lineHeight      Row height
	 * @return float
	 */
	protected function renderDataRow(&$pdf, $y, $defaultFontSize, $rowData, $lineHeight)
	{
		$pdf->SetFont('', '', $defaultFontSize - 2);
		$x = $this->marge_gauche;
		foreach ($this->cols as $col) {
			$value = (isset($rowData[$col['key']]) ? $rowData[$col['key']] : '');
			$pdf->SetXY($x, $y);
			$pdf->MultiCell($col['width'], $lineHeight, $value, 1, $col['align'], 0, 0);
			$x += $col['width'];
		}
		$pdf->Ln();

		return ($y + $lineHeight);
	}

	/**
	 * Render subtotal row
	 *
	 * @param TCPDF $pdf             Pdf object
	 * @param float $y               Y position
	 * @param int   $defaultFontSize Font size
	 * @param string $label          Label
	 * @param float  $amount         Amount
	 * @return float
	 */
	protected function renderSubtotalRow(&$pdf, $y, $defaultFontSize, $label, $amount)
	{
		global $langs;

		$pdf->SetFont('', 'B', $defaultFontSize - 1);
		$pdf->SetFillColor(240, 240, 240);
		$pdf->SetXY($this->marge_gauche, $y);
		$pdf->Cell($this->getTableWidth() - 30, 6, $label, 1, 0, 'R', 1);
		$pdf->Cell(30, 6, price($amount, 0, $langs, 0, -1, -1), 1, 1, 'R', 1);

		return ($y + 6);
	}

	/**
	 * Render signatures
	 *
	 * @param TCPDF $pdf             Pdf object
	 * @param float $y               Y position
	 * @param int   $defaultFontSize Font size
	 * @return void
	 */
	protected function renderSignatures(&$pdf, $y, $defaultFontSize)
	{
		$pdf->SetFont('', 'B', $defaultFontSize);
		$leftX = $this->marge_gauche + 10;
		$rightX = $this->page_largeur - $this->marge_droite - 60;
		$signatureY = $y + 8;
		$pdf->SetXY($leftX, $signatureY);
		$pdf->Cell(55, 6, 'RESPONSABLE MAGASIN', 0, 0, 'C');
		$pdf->SetXY($rightX, $signatureY);
		$pdf->Cell(55, 6, 'RESPONSABLE SERVICE', 0, 1, 'C');
	}

	/**
	 * Get current table width
	 *
	 * @return int
	 */
	protected function getTableWidth()
	{
		$width = 0;
		foreach ($this->cols as $col) {
			$width += $col['width'];
		}

		return $width;
	}

	/**
	 * Return list of active generation modules
	 *
	 * @param DoliDB $db Database handler
	 * @param int    $maxfilenamelength Max length
	 * @return array
	 */
	public static function liste_modeles($db, $maxfilenamelength = 0)
	{
		return parent::liste_modeles($db, $maxfilenamelength);
	}
}



