<?php
/* Copyright (C) 2026 Omega Junior
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file       inventaireplus/core/modules/stock/doc/pdf_valorisationstock.modules.php
 * \ingroup    stock
 * \brief      PDF model for warehouse stock statement
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/facture/modules_facture.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/inventaireplus/lib/stockvaluation.lib.php';

/**
 * Class to manage warehouse stock statement PDF
 */
class pdf_valorisationstock extends ModelePDFFactures
{
	/** @var DoliDB */
	public $db;
	/** @var string */
	public $name;
	/** @var string */
	public $description;
	/** @var int */
	public $page_largeur;
	/** @var int */
	public $page_hauteur;
	/** @var array<int,int> */
	public $format;
	/** @var int */
	public $marge_gauche;
	/** @var int */
	public $marge_droite;
	/** @var int */
	public $marge_haute;
	/** @var int */
	public $marge_basse;
	/** @var array<int,array<string,mixed>> */
	public $cols = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs, $mysoc;

		$langs->loadLangs(array('main', 'products', 'stocks', 'productbatch', 'inventaireplus@inventaireplus'));
		$this->db = $db;
		$this->name = 'valorisationstock';
		$this->description = 'Etat du stock par entrepôt';
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
			array('key' => 'num', 'label' => 'N°', 'width' => 8, 'align' => 'C'),
			array('key' => 'ref', 'label' => 'REF PRODUIT', 'width' => 25, 'align' => 'L'),
			array('key' => 'designation', 'label' => 'DESIGNATION', 'width' => 38, 'align' => 'L'),
		);
		if (isModEnabled('productbatch')) {
			$this->cols[] = array('key' => 'lot', 'label' => 'LOT', 'width' => 21, 'align' => 'L');
			$this->cols[] = array('key' => 'expiry', 'label' => 'DATE DE PEREMPTION', 'width' => 24, 'align' => 'C');
		} else {
			$this->cols[2]['width'] = 83;
		}
		$this->cols[] = array('key' => 'qty', 'label' => 'QUANTITE', 'width' => 15, 'align' => 'R');
		$this->cols[] = array('key' => 'purchase', 'label' => 'VALORISATION A L\'ACHAT', 'width' => 28, 'align' => 'R');
		$this->cols[] = array('key' => 'sell', 'label' => 'VALORISATION A LA VENTE', 'width' => 31, 'align' => 'R');
	}

	/**
	 * Build pdf onto disk.
	 *
	 * @param array     $parameters Custom parameters
	 * @param Translate $outputlangs Lang output object
	 * @return int
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
		$outputlangs->loadLangs(array('main', 'products', 'stocks', 'productbatch', 'inventaireplus@inventaireplus'));

		$warehouseId = (!empty($parameters['warehouseid']) ? (int) $parameters['warehouseid'] : 0);
		$dir = (!empty($parameters['diroutputmassaction']) ? $parameters['diroutputmassaction'] : '');
		if ($warehouseId <= 0 || empty($dir)) {
			$this->error = 'Paramètres incomplets pour générer l\'état du stock.';
			return 0;
		}
		if (!file_exists($dir) && dol_mkdir($dir) < 0) {
			$this->error = $langs->transnoentities('ErrorCanNotCreateDir', $dir);
			return 0;
		}

		$warehouse = new Entrepot($this->db);
		if ($warehouse->fetch($warehouseId) <= 0) {
			$this->error = 'Entrepôt introuvable pour générer l\'état du stock.';
			return 0;
		}

		$dataset = inventaireplusBuildWarehouseValuationDataset($this->db, $conf, $warehouseId);
		if (empty($dataset['rows'])) {
			$this->error = 'Aucune ligne de stock valorisée trouvée pour cet entrepôt.';
			return 0;
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
		$pdf->SetTitle($outputlangs->convToOutputCharset('ETAT DU STOCK '.$warehouse->label));
		$pdf->SetSubject($outputlangs->convToOutputCharset('Etat du stock'));
		$pdf->SetCreator('InventairePlus '.DOL_VERSION);
		$pdf->SetAuthor($mysoc->name.($user->id > 0 ? ' - '.$outputlangs->convToOutputCharset($user->getFullName($outputlangs)) : ''));
		if (getDolGlobalString('MAIN_DISABLE_PDF_COMPRESSION')) {
			$pdf->SetCompression(false);
		}

		$generationDate = dol_now();
		$metadata = array(
			'warehouse_label' => (!empty($warehouse->label) ? $warehouse->label : $warehouse->ref),
			'generation_date' => $generationDate,
		);

		$pdf->Open();
		$pdf->AddPage('P', $this->format);
		$y = $this->_pagehead($pdf, $metadata, $outputlangs);
		$y = $this->renderTableHeader($pdf, $y, $defaultFontSize);

		$lineHeight = 6;
		$categoryHeight = 7;
		$subtotalHeight = 6;
		$bottomLimit = $this->page_hauteur - $this->marge_basse - 18;
		$lineNumber = 1;

		foreach ($dataset['categories'] as $category) {
			if (($y + $categoryHeight + $lineHeight) > $bottomLimit) {
				$pdf->AddPage('P', $this->format);
				$y = $this->renderTableHeader($pdf, $this->marge_haute, $defaultFontSize);
			}

			$y = $this->renderCategoryRow($pdf, $y, $defaultFontSize, $category['label'], (int) ($category['level'] ?? 0));

			foreach ($category['rows'] as $row) {
				if (($y + $lineHeight) > $bottomLimit) {
					$pdf->AddPage('P', $this->format);
					$y = $this->renderTableHeader($pdf, $this->marge_haute, $defaultFontSize);
					$y = $this->renderCategoryRow($pdf, $y, $defaultFontSize, $category['label'], (int) ($category['level'] ?? 0));
				}

				$expiryDate = '';
				if (!empty($row['sellby'])) {
					$expiryDate = dol_print_date($this->db->jdate($row['sellby']), 'day');
				} elseif (!empty($row['eatby'])) {
					$expiryDate = dol_print_date($this->db->jdate($row['eatby']), 'day');
				}

				$rowData = array(
					'num' => $lineNumber,
					'ref' => $row['product_ref'],
					'designation' => dol_trunc($row['product_label'], 30),
					'lot' => (!empty($row['batch']) ? $row['batch'] : ''),
					'expiry' => $expiryDate,
					'qty' => price($row['qty'], 0, $outputlangs, 0, -1, -1),
					'purchase' => price(price2num($row['purchase_total_value'], 'MT'), 0, $outputlangs, 0, -1, -1),
					'sell' => price(price2num($row['sell_total_value'], 'MT'), 0, $outputlangs, 0, -1, -1),
				);
				$y = $this->renderDataRow($pdf, $y, $defaultFontSize, $rowData, $lineHeight);
				$lineNumber++;
			}

			if (($y + $subtotalHeight) > $bottomLimit) {
				$pdf->AddPage('P', $this->format);
				$y = $this->renderTableHeader($pdf, $this->marge_haute, $defaultFontSize);
			}
			$y = $this->renderSubtotalRow($pdf, $y, $defaultFontSize, 'TOTAL '.str_repeat('   ', (int) ($category['level'] ?? 0)).$category['label'], $category['total_purchase'], $category['total_sell']);
		}

		if (($y + $subtotalHeight) > $bottomLimit) {
			$pdf->AddPage('P', $this->format);
			$y = $this->renderTableHeader($pdf, $this->marge_haute, $defaultFontSize);
		}
		$y = $this->renderSubtotalRow($pdf, $y, $defaultFontSize, 'TOTAL GLOBAL', $dataset['total_purchase'], $dataset['total_sell']);
		$this->_pagefoot($pdf, $outputlangs);

		if (method_exists($pdf, 'AliasNbPages')) {
			$pdf->AliasNbPages();
		}
		$pdf->Close();

		$filename = 'etat_valorisation_stock_'.$warehouseId.'_'.dol_print_date($generationDate, '%Y%m%d%H%M%S');
		$filename = strtolower(dol_sanitizeFileName($filename));
		$file = $dir.'/'.$filename.'.pdf';
		$pdf->Output($file, 'F');
		$this->result = array('fullpath' => $file);

		return 1;
	}

	/**
	 * @param TCPDF     $pdf
	 * @param array     $metadata
	 * @param Translate $outputlangs
	 * @return float
	 */
	protected function _pagehead(&$pdf, $metadata, $outputlangs)
	{
		global $conf;

		$defaultFontSize = pdf_getPDFFontSize($outputlangs);
		pdf_pagehead($pdf, $outputlangs, $this->page_hauteur);

		$logoTop = $this->marge_haute;
		if (!empty($this->emetteur->logo) && !getDolGlobalInt('PDF_DISABLE_MYCOMPANY_LOGO')) {
			$logodir = (!empty($conf->mycompany->multidir_output[$conf->entity]) ? $conf->mycompany->multidir_output[$conf->entity] : $conf->mycompany->dir_output);
			$logo = (!getDolGlobalInt('MAIN_PDF_USE_LARGE_LOGO') ? $logodir.'/logos/thumbs/'.$this->emetteur->logo_small : $logodir.'/logos/'.$this->emetteur->logo);
			if (is_readable($logo)) {
				$height = min(20, pdf_getHeightForLogo($logo));
				$pdf->Image($logo, $this->marge_gauche, $logoTop, 0, $height);
			}
		}

		$titleWidth = 100;
		$titleX = $this->page_largeur - $this->marge_droite - $titleWidth;
		$pdf->SetTextColor(0, 0, 60);
		$pdf->SetFont('', 'B', $defaultFontSize + 3);
		$pdf->SetXY($titleX, $this->marge_haute);
		$pdf->MultiCell($titleWidth, 4, 'ETAT DU STOCK', 0, 'R');

		$boxTop = 42;
		$boxHeight = 24;
		$boxWidth = $this->page_largeur - $this->marge_gauche - $this->marge_droite;
		$pdf->SetTextColor(0, 0, 0);
		$pdf->Rect($this->marge_gauche, $boxTop, $boxWidth, $boxHeight);
		$pdf->SetFont('', '', $defaultFontSize - 1);
		$pdf->SetXY($this->marge_gauche + 2, $boxTop + 4);
		$pdf->MultiCell($boxWidth - 4, 4, $outputlangs->convToOutputCharset('ENTREPOT : '.$metadata['warehouse_label']), 0, 'L');
		$pdf->SetXY($this->marge_gauche + 2, $boxTop + 10);
		$pdf->MultiCell($boxWidth - 4, 4, $outputlangs->convToOutputCharset('DATE : '.dol_print_date($metadata['generation_date'], 'day').'    HEURE : '.dol_print_date($metadata['generation_date'], 'hour')), 0, 'L');

		return ($boxTop + $boxHeight + 6);
	}

	/**
	 * @param TCPDF     $pdf
	 * @param Translate $outputlangs
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
	 * @param TCPDF $pdf
	 * @param float $y
	 * @param int   $defaultFontSize
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
	 * @param TCPDF $pdf
	 * @param float $y
	 * @param int   $defaultFontSize
	 * @param string $categoryLabel
	 * @param int    $categoryLevel
	 * @return float
	 */
	protected function renderCategoryRow(&$pdf, $y, $defaultFontSize, $categoryLabel, $categoryLevel = 0)
	{
		$pdf->SetFont('', 'B', $defaultFontSize - 1);
		$pdf->SetFillColor(240, 240, 240);
		$pdf->SetXY($this->marge_gauche, $y);
		$label = str_repeat('   ', (int) $categoryLevel).strtoupper($categoryLabel);
		$pdf->MultiCell($this->getTableWidth(), 7, $label, 1, ((int) $categoryLevel > 0 ? 'L' : 'C'), 1, 1, '', '', true, 0, false, true, 7, 'M', true);
		return ($y + 7);
	}

	/**
	 * @param TCPDF $pdf
	 * @param float $y
	 * @param int   $defaultFontSize
	 * @param array $rowData
	 * @param float $lineHeight
	 * @return float
	 */
	protected function renderDataRow(&$pdf, $y, $defaultFontSize, $rowData, $lineHeight)
	{
		$pdf->SetFont('', '', $defaultFontSize - 2);
		$rowHeight = $lineHeight;
		foreach ($this->cols as $col) {
			$text = (string) $rowData[$col['key']];
			$numLines = 1;
			if (method_exists($pdf, 'getNumLines')) {
				$numLines = max(1, (int) $pdf->getNumLines($text, $col['width']));
			}
			$rowHeight = max($rowHeight, $numLines * $lineHeight);
		}

		$x = $this->marge_gauche;
		foreach ($this->cols as $col) {
			$pdf->SetXY($x, $y);
			$pdf->MultiCell($col['width'], $rowHeight, (string) $rowData[$col['key']], 1, $col['align'], 0, 0, '', '', true, 0, false, true, $rowHeight, 'M', true);
			$x += $col['width'];
		}
		$pdf->Ln();
		return ($y + $rowHeight);
	}

	/**
	 * @param TCPDF $pdf
	 * @param float $y
	 * @param int   $defaultFontSize
	 * @param string $label
	 * @param float $purchaseTotal
	 * @param float $sellTotal
	 * @return float
	 */
	protected function renderSubtotalRow(&$pdf, $y, $defaultFontSize, $label, $purchaseTotal, $sellTotal)
	{
		global $langs;

		$labelWidth = 0;
		foreach ($this->cols as $col) {
			if (in_array($col['key'], array('purchase', 'sell'), true)) {
				continue;
			}
			$labelWidth += $col['width'];
		}

		$pdf->SetFont('', 'B', $defaultFontSize - 1);
		$pdf->SetFillColor(240, 240, 240);
		$pdf->SetXY($this->marge_gauche, $y);
		$pdf->MultiCell($labelWidth, 6, $langs->convToOutputCharset($label), 1, 'R', 1, 0, '', '', true, 0, false, true, 6, 'M', true);

		$purchaseWidth = 0;
		$sellWidth = 0;
		foreach ($this->cols as $col) {
			if ($col['key'] === 'purchase') $purchaseWidth = $col['width'];
			if ($col['key'] === 'sell') $sellWidth = $col['width'];
		}
		$x = $this->marge_gauche + $labelWidth;
		$pdf->SetXY($x, $y);
		$pdf->MultiCell($purchaseWidth, 6, price(price2num($purchaseTotal, 'MT'), 0, $langs, 0, -1, -1), 1, 'R', 1, 0, '', '', true, 0, false, true, 6, 'M', true);
		$x += $purchaseWidth;
		$pdf->SetXY($x, $y);
		$pdf->MultiCell($sellWidth, 6, price(price2num($sellTotal, 'MT'), 0, $langs, 0, -1, -1), 1, 'R', 1, 0, '', '', true, 0, false, true, 6, 'M', true);
		$pdf->Ln();

		return ($y + 6);
	}

	/**
	 * @return float
	 */
	protected function getTableWidth()
	{
		$width = 0;
		foreach ($this->cols as $col) {
			$width += $col['width'];
		}

		return $width;
	}
}


