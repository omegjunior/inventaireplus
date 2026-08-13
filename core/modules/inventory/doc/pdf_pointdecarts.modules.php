<?php
/* Copyright (C) 2026 Omega Junior
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 *  \file       inventaireplus/core/modules/inventory/doc/pdf_pointdecarts.modules.php
 *  \ingroup    inventory
 *  \brief      PDF model for inventory discrepancy report
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/facture/modules_facture.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/inventaireplus/lib/inventorydocs.lib.php';

class pdf_pointdecarts extends ModelePDFFactures
{
	public $db;
	public $name;
	public $description;
	public $cols = array();
	public $page_largeur;
	public $page_hauteur;
	public $format;
	public $marge_gauche;
	public $marge_droite;
	public $marge_haute;
	public $marge_basse;

	public function __construct($db)
	{
		global $langs, $mysoc;

		$langs->loadLangs(array('main', 'stocks', 'products', 'inventaireplus@inventaireplus'));

		$this->db = $db;
		$this->name = 'pointdecarts';
		$this->description = 'Point des écarts d’inventaire';
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
		$useBatch = isModEnabled('productbatch');
		$designationWidth = ($useBatch ? 43 : 83);
		$this->cols = array(
			array('key' => 'num', 'label' => 'N°', 'width' => 8, 'align' => 'C'),
			array('key' => 'ref', 'label' => 'REF PRODUIT', 'width' => 20, 'align' => 'L'),
			array('key' => 'designation', 'label' => 'DESIGNATION', 'width' => $designationWidth, 'align' => 'L'),
		);
		if ($useBatch) {
			$this->cols[] = array('key' => 'lot', 'label' => 'LOT', 'width' => 18, 'align' => 'L');
			$this->cols[] = array('key' => 'expiry', 'label' => 'DATE DE PEREMPTION', 'width' => 22, 'align' => 'C');
		}
		$this->cols[] = array('key' => 'qty_theoretical', 'label' => 'QTÉ THEORIQUE', 'width' => 18, 'align' => 'R');
		$this->cols[] = array('key' => 'qty_physical', 'label' => 'QTÉ PHYSIQUE', 'width' => 18, 'align' => 'R');
		$this->cols[] = array('key' => 'qty_delta', 'label' => 'ECART', 'width' => 13, 'align' => 'R');
		$this->cols[] = array('key' => 'justification', 'label' => 'JUSTIFICATION', 'width' => 30, 'align' => 'L');
	}

	public function write_file($parameters, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		global $conf, $langs, $mysoc, $user;

		if (!is_object($outputlangs)) {
			$outputlangs = $langs;
		}
		if (!empty($conf->global->MAIN_USE_FPDF)) {
			$outputlangs->charset_output = 'ISO-8859-1';
		}
		$outputlangs->loadLangs(array('main', 'stocks', 'products', 'inventaireplus@inventaireplus'));

		$inventoryId = (!empty($parameters['inventoryid']) ? (int) $parameters['inventoryid'] : 0);
		$dir = (!empty($parameters['diroutput']) ? $parameters['diroutput'] : '');
		if ($inventoryId <= 0 || empty($dir)) {
			$this->error = 'Paramètres incomplets pour générer le point des écarts.';
			return 0;
		}

		$dataset = inventaireplusBuildInventoryDocumentDataset($this->db, $inventoryId, true);
		if (empty($dataset['lines'])) {
			$this->error = 'Aucune ligne en écart n\'est disponible pour le point des écarts.';
			return 0;
		}

		if (!file_exists($dir) && dol_mkdir($dir) < 0) {
			$this->error = $outputlangs->transnoentities('ErrorCanNotCreateDir', $dir);
			return 0;
		}

		$inventoryRefSafe = dol_sanitizeFileName(!empty($dataset['context']['inventory_ref']) ? $dataset['context']['inventory_ref'] : 'inventory_'.$inventoryId);
		$filename = 'point_des_ecarts_'.$inventoryRefSafe.'.pdf';
		$file = $dir.'/'.$filename;

		$pdf = pdf_getInstance($this->format);
		$defaultFontSize = pdf_getPDFFontSize($outputlangs);
		if (class_exists('TCPDF')) {
			$pdf->setPrintHeader(false);
			$pdf->setPrintFooter(false);
		}
		$pdf->SetAutoPageBreak(true, $this->marge_basse + 16);
		$pdf->SetMargins($this->marge_gauche, $this->marge_haute, $this->marge_droite);
		$pdf->SetFont(pdf_getPDFFont($outputlangs));
		$pdf->SetDrawColor(80, 80, 80);
		$pdf->SetTextColor(0, 0, 0);
		$pdf->SetTitle($outputlangs->convToOutputCharset('POINT DES ECARTS '.(!empty($dataset['context']['inventory_ref']) ? $dataset['context']['inventory_ref'] : $inventoryId)));
		$pdf->SetSubject($outputlangs->convToOutputCharset('Point des écarts'));
		$pdf->SetCreator('InventairePlus '.DOL_VERSION);
		$pdf->SetAuthor($mysoc->name.($user->id > 0 ? ' - '.$outputlangs->convToOutputCharset($user->getFullName($outputlangs)) : ''));
		$pdf->Open();
		$pdf->AddPage('P');

		$y = $this->_pagehead($pdf, $dataset['context'], $outputlangs);
		$y = $this->renderTableHeader($pdf, $y, $defaultFontSize);
		$lineNumber = 1;
		$categoryRowHeight = 7;
		$signatureReserve = 28;

		foreach ($dataset['categories'] as $category) {
			if ($y + $categoryRowHeight + 10 > ($this->page_hauteur - $this->marge_basse - $signatureReserve)) {
				$this->_pagefoot($pdf, $outputlangs);
				$pdf->AddPage('P');
				$y = $this->renderTableHeader($pdf, $this->marge_haute, $defaultFontSize);
			}

			$pdf->SetFont('', 'B', $defaultFontSize - 1);
			$pdf->SetXY($this->marge_gauche, $y);
			$categoryLevel = (int) ($category['level'] ?? 0);
			$categoryLabel = str_repeat('   ', $categoryLevel).$category['label'];
			$pdf->MultiCell($this->getTableWidth(), $categoryRowHeight, $outputlangs->convToOutputCharset($categoryLabel), 1, ($categoryLevel > 0 ? 'L' : 'C'), false, 1, '', '', true, 0, false, true, 7, 'M', true);
			$y += $categoryRowHeight;

			foreach ($category['lines'] as $line) {
				$rowHeight = $this->getRowHeight($pdf, $line, $defaultFontSize);
				if ($y + $rowHeight > ($this->page_hauteur - $this->marge_basse - $signatureReserve)) {
					$this->_pagefoot($pdf, $outputlangs);
					$pdf->AddPage('P');
					$y = $this->renderTableHeader($pdf, $this->marge_haute, $defaultFontSize);
				}

				$x = $this->marge_gauche;
				$pdf->SetFont('', '', $defaultFontSize - 2);
				$cells = array(
					'num' => (string) $lineNumber,
					'ref' => (string) $line['product_ref'],
					'designation' => (string) $line['product_label'],
					'lot' => (string) $line['batch'],
					'expiry' => $this->formatExpiryDate($line),
					'qty_theoretical' => (string) $line['qty_theoretical'],
					'qty_physical' => (string) $line['qty_physical'],
					'qty_delta' => (string) $line['qty_delta'],
					'justification' => (string) $line['justification_text'],
				);

				foreach ($this->cols as $col) {
					$pdf->SetXY($x, $y);
					$pdf->MultiCell($col['width'], $rowHeight, $outputlangs->convToOutputCharset($cells[$col['key']]), 1, $col['align'], false, 0, '', '', true, 0, false, true, $rowHeight, 'M');
					$x += $col['width'];
				}
				$pdf->Ln();
				$y += $rowHeight;
				$lineNumber++;
			}

			$pdf->SetFont('', 'B', $defaultFontSize - 2);
			$x = $this->marge_gauche;
			$pdf->SetXY($x, $y);
			$pdf->MultiCell($this->getLeadingWidth(), 7, $outputlangs->convToOutputCharset('TOTAL '.str_repeat('   ', $categoryLevel).$category['label']), 1, 'R', false, 0, '', '', true, 0, false, true, 7, 'M');
			$x += $this->getLeadingWidth();
			$pdf->SetXY($x, $y);
			$pdf->MultiCell($this->getColumnWidth('qty_theoretical'), 7, (string) $category['total_theoretical'], 1, 'R', false, 0, '', '', true, 0, false, true, 7, 'M');
			$x += $this->getColumnWidth('qty_theoretical');
			$pdf->SetXY($x, $y);
			$pdf->MultiCell($this->getColumnWidth('qty_physical'), 7, (string) $category['total_physical'], 1, 'R', false, 0, '', '', true, 0, false, true, 7, 'M');
			$x += $this->getColumnWidth('qty_physical');
			$pdf->SetXY($x, $y);
			$pdf->MultiCell($this->getColumnWidth('qty_delta'), 7, (string) $category['total_delta'], 1, 'R', false, 0, '', '', true, 0, false, true, 7, 'M');
			$x += $this->getColumnWidth('qty_delta');
			$pdf->SetXY($x, $y);
			$pdf->MultiCell($this->getColumnWidth('justification'), 7, '', 1, 'L', false, 1, '', '', true, 0, false, true, 7, 'M');
			$y += 7;
		}

		$signatureTop = max($y + 8, $this->page_hauteur - $this->marge_basse - 28);
		if ($signatureTop > ($this->page_hauteur - $this->marge_basse - 18)) {
			$this->_pagefoot($pdf, $outputlangs);
			$pdf->AddPage('P');
			$y = $this->marge_haute;
			$signatureTop = max($y + 16, $this->page_hauteur - $this->marge_basse - 28);
		}
		$this->renderSignatures($pdf, $signatureTop, $outputlangs, $defaultFontSize);
		$this->_pagefoot($pdf, $outputlangs);

		$pdf->Close();
		$pdf->Output($file, 'F');

		$this->result = array(
			'fullpath' => $file,
			'relativefile' => $inventoryRefSafe.'/'.$filename,
		);

		return 1;
	}

	protected function _pagehead(&$pdf, $context, $outputlangs)
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

		$titleWidth = 110;
		$titleX = $this->page_largeur - $this->marge_droite - $titleWidth;
		$pdf->SetTextColor(0, 0, 60);
		$pdf->SetFont('', 'B', $defaultFontSize + 2);
		$pdf->SetXY($titleX, $this->marge_haute);
		$pdf->MultiCell($titleWidth, 4, 'POINT DES ECARTS', 0, 'R');

		$boxTop = 42;
		$boxHeight = 24;
		$boxWidth = $this->getTableWidth();
		$pdf->SetTextColor(0, 0, 0);
		$pdf->Rect($this->marge_gauche, $boxTop, $boxWidth, $boxHeight);
		$pdf->SetFont('', '', $defaultFontSize - 1);
		$inventoryRef = (!empty($context['inventory_ref']) ? $context['inventory_ref'] : 'INVENTORY-'.$context['inventory_id']);
		$warehouseLabel = (!empty($context['warehouse_label']) ? $context['warehouse_label'] : (!empty($context['warehouse_ref']) ? $context['warehouse_ref'] : ''));
		$documentDate = (!empty($context['document_date']) ? $context['document_date'] : null);
		$pdf->SetXY($this->marge_gauche + 2, $boxTop + 3);
		$pdf->MultiCell($boxWidth - 4, 4, $outputlangs->convToOutputCharset('REFERENCE INVENTAIRE : '.$inventoryRef), 0, 'L');
		$pdf->SetXY($this->marge_gauche + 2, $boxTop + 9);
		$pdf->MultiCell($boxWidth - 4, 4, $outputlangs->convToOutputCharset('ENTREPOT : '.$warehouseLabel), 0, 'L');
		$pdf->SetXY($this->marge_gauche + 2, $boxTop + 15);
		$pdf->MultiCell($boxWidth - 4, 4, $outputlangs->convToOutputCharset('DATE : '.($documentDate ? dol_print_date($this->db->jdate($documentDate), 'day') : '').'    HEURE : '.($documentDate ? dol_print_date($this->db->jdate($documentDate), 'hour') : '')), 0, 'L');

		return ($boxTop + $boxHeight + 6);
	}

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
		$pdf->SetAutoPageBreak(true, $this->marge_basse + 16);
	}

	protected function renderTableHeader(&$pdf, $y, $defaultFontSize)
	{
		$pdf->SetDrawColor(80, 80, 80);
		$pdf->SetTextColor(0, 0, 0);
		$pdf->SetFont('', 'B', $defaultFontSize - 3);
		$x = $this->marge_gauche;
		foreach ($this->cols as $col) {
			$pdf->SetXY($x, $y);
			$pdf->MultiCell($col['width'], 8, $col['label'], 1, 'C', 0, 0);
			$x += $col['width'];
		}
		$pdf->Ln();

		return ($y + 8);
	}

	protected function getRowHeight(&$pdf, $line, $defaultFontSize)
	{
		$pdf->SetFont('', '', $defaultFontSize - 2);
		$maxLines = 1;
		$texts = array(
			'ref' => (string) $line['product_ref'],
			'designation' => (string) $line['product_label'],
			'lot' => (string) $line['batch'],
			'expiry' => $this->formatExpiryDate($line),
			'justification' => (string) $line['justification_text'],
		);

		foreach ($texts as $key => $text) {
			$colWidth = $this->getColumnWidth($key);
			if ($colWidth <= 0) {
				continue;
			}
			if (method_exists($pdf, 'getNumLines')) {
				$lineCount = max(1, (int) $pdf->getNumLines($pdf->GetStringWidth($text) > 0 ? $text : ' ', $colWidth));
				$maxLines = max($maxLines, $lineCount);
			}
		}

		return max(7, $maxLines * 4.2);
	}

	protected function renderSignatures(&$pdf, $top, $outputlangs, $defaultFontSize)
	{
		$width = ($this->getTableWidth() - 20) / 2;
		$leftX = $this->marge_gauche + 5;
		$rightX = $leftX + $width + 10;

		$pdf->SetFont('', 'B', $defaultFontSize - 1);
		$pdf->SetXY($leftX, $top);
		$pdf->MultiCell($width, 4, $outputlangs->convToOutputCharset('COMPTAGE'), 0, 'C', false, 0);
		$pdf->SetXY($rightX, $top);
		$pdf->MultiCell($width, 4, $outputlangs->convToOutputCharset('SUPERVISION'), 0, 'C', false, 0);
		$pdf->SetDrawColor(120, 120, 120);
		$pdf->Line($leftX + 5, $top + 16, $leftX + $width - 5, $top + 16);
		$pdf->Line($rightX + 5, $top + 16, $rightX + $width - 5, $top + 16);
	}

	protected function formatExpiryDate($line)
	{
		$dateValue = (!empty($line['sellby']) ? $line['sellby'] : (!empty($line['eatby']) ? $line['eatby'] : null));
		if (empty($dateValue)) {
			return '';
		}

		return dol_print_date($this->db->jdate($dateValue), 'day');
	}

	protected function getTableWidth()
	{
		$width = 0;
		foreach ($this->cols as $col) {
			$width += $col['width'];
		}

		return $width;
	}

	protected function getLeadingWidth()
	{
		$width = 0;
		foreach ($this->cols as $col) {
			if ($col['key'] === 'qty_theoretical') {
				break;
			}
			$width += $col['width'];
		}

		return $width;
	}

	protected function getColumnWidth($key)
	{
		foreach ($this->cols as $col) {
			if ($col['key'] === $key) {
				return $col['width'];
			}
		}

		return 0;
	}
}

