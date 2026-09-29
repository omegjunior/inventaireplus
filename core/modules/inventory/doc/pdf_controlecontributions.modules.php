<?php
/* Copyright (C) 2026 Omega Junior
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * PDF model for the physical review of collaborative count contributions.
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/facture/modules_facture.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';

class pdf_controlecontributions extends ModelePDFFactures
{
	/** @var DoliDB */
	public $db;
	/** @var string */
	public $name = 'controlecontributions';
	/** @var string */
	public $description = 'Collaborative inventory contribution control sheet';
	/** @var array<int,array<string,mixed>> */
	public $cols = array();
	/** @var float */
	public $page_largeur;
	/** @var float */
	public $page_hauteur;
	/** @var array<int,float> */
	public $format;
	/** @var float */
	public $marge_gauche;
	/** @var float */
	public $marge_droite;
	/** @var float */
	public $marge_haute;
	/** @var float */
	public $marge_basse;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $mysoc;

		$this->db = $db;
		$this->type = 'pdf';
		$format = pdf_getFormat();
		$this->page_largeur = max($format['width'], $format['height']);
		$this->page_hauteur = min($format['width'], $format['height']);
		$this->format = array($this->page_hauteur, $this->page_largeur);
		$this->marge_gauche = getDolGlobalInt('MAIN_PDF_MARGIN_LEFT', 10);
		$this->marge_droite = getDolGlobalInt('MAIN_PDF_MARGIN_RIGHT', 10);
		$this->marge_haute = getDolGlobalInt('MAIN_PDF_MARGIN_TOP', 10);
		$this->marge_basse = getDolGlobalInt('MAIN_PDF_MARGIN_BOTTOM', 10);
		$this->emetteur = $mysoc;

		$withBatch = isModEnabled('productbatch');
		$this->cols = array(
			array('key' => 'num', 'label' => 'No.', 'width' => ($withBatch ? 8 : 10), 'align' => 'C'),
			array('key' => 'date', 'label' => 'Date', 'width' => ($withBatch ? 22 : 25), 'align' => 'C'),
			array('key' => 'agent', 'label' => 'User', 'width' => ($withBatch ? 25 : 30), 'align' => 'L'),
			array('key' => 'ref', 'label' => 'Ref', 'width' => ($withBatch ? 25 : 28), 'align' => 'L'),
			array('key' => 'label', 'label' => 'Label', 'width' => ($withBatch ? 49 : 60), 'align' => 'L'),
		);
		if ($withBatch) {
			$this->cols[] = array('key' => 'batch', 'label' => 'Batch', 'width' => 24, 'align' => 'L');
		}
		$this->cols[] = array('key' => 'qty', 'label' => 'Qty', 'width' => ($withBatch ? 20 : 22), 'align' => 'R');
		$this->cols[] = array('key' => 'verified', 'label' => 'InventoryPlusCollaborativeVerifiedQty', 'width' => ($withBatch ? 23 : 25), 'align' => 'C');
		$this->cols[] = array('key' => 'difference', 'label' => 'InventoryPlusCollaborativeControlDifference', 'width' => ($withBatch ? 18 : 20), 'align' => 'C');
		$this->cols[] = array('key' => 'observation', 'label' => 'InventoryPlusCollaborativeObservation', 'width' => ($withBatch ? 63 : 57), 'align' => 'L');

		$this->scaleColumnsToPage();
	}

	/**
	 * @param array<string,mixed> $parameters Parameters
	 * @param Translate $outputlangs Output language
	 * @param string $srctemplatepath Unused
	 * @param int $hidedetails Unused
	 * @param int $hidedesc Unused
	 * @param int $hideref Unused
	 * @return int
	 */
	public function write_file($parameters, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		global $conf, $langs, $mysoc, $user;

		if (!is_object($outputlangs)) $outputlangs = $langs;
		$outputlangs->loadLangs(array('main', 'stocks', 'products', 'productbatch', 'inventaireplus@inventaireplus'));
		$dataset = (!empty($parameters['dataset']) && is_array($parameters['dataset']) ? $parameters['dataset'] : array());
		$sequence = (!empty($parameters['sequence']) ? (int) $parameters['sequence'] : 0);
		$dir = (!empty($parameters['diroutput']) ? $parameters['diroutput'] : '');
		if (empty($dataset['lines']) || empty($dataset['context']) || $sequence <= 0 || $dir === '') {
			$this->error = 'Invalid control sheet parameters';
			return 0;
		}
		if (!file_exists($dir) && dol_mkdir($dir) < 0) {
			$this->error = $outputlangs->transnoentities('ErrorCanNotCreateDir', $dir);
			return 0;
		}

		$inventoryRefSafe = ((int) $dataset['context']['inventory_id']).'_'.dol_sanitizeFileName(dol_trunc($dataset['context']['inventory_ref'], 64, 'right', 'UTF-8', 1));
		$filename = 'controle_contributions_'.$inventoryRefSafe.'_'.$sequence.'_'.substr($dataset['content_hash'], 0, 12).'.pdf';
		$file = $dir.'/'.$filename;
		$pdf = pdf_getInstance($this->format);
		if (class_exists('TCPDF')) {
			$pdf->setPrintHeader(false);
			$pdf->setPrintFooter(false);
		}
		$pdf->SetAutoPageBreak(false, 0);
		$pdf->SetMargins($this->marge_gauche, $this->marge_haute, $this->marge_droite);
		$pdf->SetFont(pdf_getPDFFont($outputlangs));
		$pdf->SetDrawColor(80, 80, 80);
		$pdf->SetTextColor(0, 0, 0);
		$pdf->SetTitle($outputlangs->convToOutputCharset($outputlangs->transnoentities('InventoryPlusCollaborativeControlSheet').' '.$dataset['context']['inventory_ref']));
		$pdf->SetCreator('InventairePlus '.DOL_VERSION);
		$pdf->SetAuthor($mysoc->name.($user->id > 0 ? ' - '.$user->getFullName($outputlangs) : ''));
		if (method_exists($pdf, 'AliasNbPages')) {
			$pdf->AliasNbPages();
		}
		$pdf->Open();
		$dataset['generated_at'] = dol_now();

		$y = $this->addPage($pdf, $dataset, $sequence, $outputlangs);
		$lineNumber = 1;
		$bottomLimit = $this->page_hauteur - $this->marge_basse - 13;
		foreach ($dataset['zones'] as $zone) {
			if ($y + 13 > $bottomLimit) $y = $this->addPage($pdf, $dataset, $sequence, $outputlangs);
			$y = $this->renderZoneHeader($pdf, $y, $zone['label'], $outputlangs);

			foreach ($zone['lines'] as $line) {
				$rowHeight = $this->rowHeight($pdf, $line, $outputlangs);
				if ($y + $rowHeight > $bottomLimit) {
					$y = $this->addPage($pdf, $dataset, $sequence, $outputlangs);
					$y = $this->renderZoneHeader($pdf, $y, $zone['label'], $outputlangs);
				}
				$cells = array(
					'num' => (string) $lineNumber,
					'date' => dol_print_date($this->db->jdate($line['datec']), 'dayhour'),
					'agent' => ($line['user_name'] !== '' ? $line['user_name'] : $line['user_login']),
					'ref' => $line['product_ref'],
					'label' => $line['product_label'],
					'batch' => $line['batch'],
					'qty' => price($line['qty']),
					'verified' => '',
					'difference' => '',
					'observation' => '',
				);
				$this->renderRow($pdf, $y, $rowHeight, $cells, $outputlangs);
				$y += $rowHeight;
				$lineNumber++;
			}

			if ($y + 6 > $bottomLimit) $y = $this->addPage($pdf, $dataset, $sequence, $outputlangs);
			$pdf->SetFont('', 'B', 7);
			$pdf->SetXY($this->marge_gauche, $y);
			$pdf->MultiCell($this->tableWidth(), 6, $outputlangs->convToOutputCharset($outputlangs->transnoentities('Total').' '.$zone['label'].' : '.price($zone['total'])), 1, 'R', false, 0, '', '', true, 0, false, true, 6, 'M');
			$y += 8;
		}

		if ($y + 27 > $bottomLimit) $y = $this->addPage($pdf, $dataset, $sequence, $outputlangs, false);
		$this->renderSignatures($pdf, $y + 3, $outputlangs);
		$this->renderFooter($pdf, $outputlangs);
		$pdf->Close();
		$pdf->Output($file, 'F');

		$this->result = array(
			'fullpath' => $file,
			'relativefile' => 'inventaireplus/control/'.$inventoryRefSafe.'/'.$filename,
		);
		return 1;
	}

	/** @return float */
	protected function addPage(&$pdf, $dataset, $sequence, $outputlangs, $withTableHeader = true)
	{
		global $conf;

		if ($pdf->PageNo() > 0) $this->renderFooter($pdf, $outputlangs);
		$pdf->AddPage('L');
		pdf_pagehead($pdf, $outputlangs, $this->page_hauteur);

		$defaultFontSize = pdf_getPDFFontSize($outputlangs);
		$headerTop = $this->marge_haute;
		$logoHeight = 0;
		$pdf->SetXY($this->marge_gauche, $headerTop);
		if (!empty($this->emetteur->logo) && !getDolGlobalInt('PDF_DISABLE_MYCOMPANY_LOGO')) {
			$logoDir = (!empty($conf->mycompany->multidir_output[$conf->entity]) ? $conf->mycompany->multidir_output[$conf->entity] : $conf->mycompany->dir_output);
			$logo = (!getDolGlobalInt('MAIN_PDF_USE_LARGE_LOGO') ? $logoDir.'/logos/thumbs/'.$this->emetteur->logo_small : $logoDir.'/logos/'.$this->emetteur->logo);
			if (is_readable($logo)) {
				$logoHeight = min(20, pdf_getHeightForLogo($logo));
				$pdf->Image($logo, $this->marge_gauche, $headerTop, 0, $logoHeight);
			}
		}
		if ($logoHeight <= 0) {
			$pdf->SetTextColor(0, 0, 60);
			$pdf->SetFont('', 'B', $defaultFontSize + 1);
			$pdf->MultiCell(115, 5, $outputlangs->convToOutputCharset($this->emetteur->name), 0, 'L');
		}

		$titleWidth = 150;
		$titleX = $this->page_largeur - $this->marge_droite - $titleWidth;
		$pdf->SetTextColor(0, 0, 60);
		$pdf->SetFont('', 'B', $defaultFontSize + 3);
		$pdf->SetXY($titleX, $headerTop);
		$pdf->MultiCell($titleWidth, 6, $outputlangs->convToOutputCharset($outputlangs->transnoentities('InventoryPlusCollaborativeControlSheet')), 0, 'R');
		$pdf->SetFont('', 'B', $defaultFontSize);
		$pdf->SetXY($titleX, $headerTop + 8);
		$pdf->MultiCell($titleWidth, 4, $outputlangs->convToOutputCharset($outputlangs->transnoentities('Ref').': '.$dataset['context']['inventory_ref']), 0, 'R');

		$boxTop = max($headerTop + 24, $headerTop + $logoHeight + 3);
		$boxHeight = 22;
		$boxWidth = $this->tableWidth();
		$halfWidth = $boxWidth / 2;
		$pdf->SetDrawColor(120, 120, 120);
		$pdf->SetTextColor(0, 0, 0);
		$pdf->Rect($this->marge_gauche, $boxTop, $boxWidth, $boxHeight);
		$pdf->Line($this->marge_gauche + $halfWidth, $boxTop, $this->marge_gauche + $halfWidth, $boxTop + 14);
		$pdf->Line($this->marge_gauche, $boxTop + 14, $this->marge_gauche + $boxWidth, $boxTop + 14);
		$pdf->SetFont('', '', $defaultFontSize - 1);

		$pdf->SetXY($this->marge_gauche + 2, $boxTop + 2);
		$pdf->MultiCell($halfWidth - 4, 4, $outputlangs->convToOutputCharset($outputlangs->transnoentities('Label').': '.dol_trunc($dataset['context']['inventory_title'], 100)), 0, 'L');
		$pdf->SetXY($this->marge_gauche + 2, $boxTop + 8);
		$pdf->MultiCell($halfWidth - 4, 4, $outputlangs->convToOutputCharset($outputlangs->transnoentities('Date').': '.dol_print_date($dataset['generated_at'], 'dayhour')), 0, 'L');

		$rightX = $this->marge_gauche + $halfWidth + 2;
		$pdf->SetXY($rightX, $boxTop + 2);
		$pdf->MultiCell($halfWidth - 4, 4, $outputlangs->convToOutputCharset($outputlangs->transnoentities('Warehouse').': '.$dataset['context']['warehouse_ref']), 0, 'L');
		$pdf->SetXY($rightX, $boxTop + 8);
		$pdf->MultiCell($halfWidth - 4, 4, $outputlangs->convToOutputCharset($outputlangs->transnoentities('InventoryPlusCollaborativeControlSequence', $sequence)), 0, 'L');

		$pdf->SetFont('', '', $defaultFontSize - 3);
		$pdf->SetXY($this->marge_gauche + 2, $boxTop + 16);
		$pdf->MultiCell($boxWidth - 4, 3, $outputlangs->convToOutputCharset($outputlangs->transnoentities('InventoryPlusCollaborativeControlHash').': '.$dataset['content_hash']), 0, 'L');
		$y = $boxTop + $boxHeight + 4;
		return ($withTableHeader ? $this->renderTableHeader($pdf, $y, $outputlangs) : $y);
	}

	/** @return float */
	protected function renderTableHeader(&$pdf, $y, $outputlangs)
	{
		$pdf->SetFillColor(220, 220, 220);
		$pdf->SetFont('', 'B', 6.5);
		$x = $this->marge_gauche;
		foreach ($this->cols as $col) {
			$pdf->SetXY($x, $y);
			$pdf->MultiCell($col['width'], 9, $outputlangs->convToOutputCharset($outputlangs->transnoentities($col['label'])), 1, 'C', true, 0, '', '', true, 0, false, true, 9, 'M');
			$x += $col['width'];
		}
		return $y + 9;
	}

	/** @return float */
	protected function renderZoneHeader(&$pdf, $y, $zoneLabel, $outputlangs)
	{
		$pdf->SetFillColor(235, 235, 235);
		$pdf->SetFont('', 'B', 8);
		$pdf->SetXY($this->marge_gauche, $y);
		$zoneTitle = $outputlangs->transnoentities('InventoryPlusCollaborativeZone').': '.$zoneLabel;
		$pdf->MultiCell($this->tableWidth(), 7, $outputlangs->convToOutputCharset($zoneTitle), 1, 'L', true, 0, '', '', true, 0, false, true, 7, 'M');
		return $y + 7;
	}

	/** @return void */
	protected function renderRow(&$pdf, $y, $height, $cells, $outputlangs)
	{
		$pdf->SetFont('', '', 7);
		$x = $this->marge_gauche;
		foreach ($this->cols as $col) {
			$pdf->SetXY($x, $y);
			$pdf->MultiCell($col['width'], $height, $outputlangs->convToOutputCharset($cells[$col['key']]), 1, $col['align'], false, 0, '', '', true, 0, false, true, $height, 'M');
			$x += $col['width'];
		}
	}

	/** @return float */
	protected function rowHeight(&$pdf, $line, $outputlangs)
	{
		$pdf->SetFont('', '', 7);
		$values = array('agent' => ($line['user_name'] !== '' ? $line['user_name'] : $line['user_login']), 'ref' => $line['product_ref'], 'label' => $line['product_label'], 'batch' => $line['batch']);
		$maxLines = 1;
		foreach ($this->cols as $col) {
			if (!isset($values[$col['key']]) || !method_exists($pdf, 'getNumLines')) continue;
			$maxLines = max($maxLines, (int) $pdf->getNumLines($outputlangs->convToOutputCharset($values[$col['key']]), $col['width'] - 1));
		}
		return max(7, $maxLines * 3.5 + 1);
	}

	/** @return void */
	protected function renderSignatures(&$pdf, $y, $outputlangs)
	{
		$half = ($this->tableWidth() - 6) / 2;
		$pdf->SetFont('', 'B', 8);
		$pdf->SetXY($this->marge_gauche, $y);
		$pdf->MultiCell($half, 22, $outputlangs->convToOutputCharset($outputlangs->transnoentities('InventoryPlusCollaborativeCountAgents').' :'), 1, 'L');
		$pdf->SetXY($this->marge_gauche + $half + 6, $y);
		$pdf->MultiCell($half, 22, $outputlangs->convToOutputCharset($outputlangs->transnoentities('InventoryPlusCollaborativeController').' :'), 1, 'L');
	}

	/** @return void */
	protected function renderFooter(&$pdf, $outputlangs)
	{
		$pdf->SetDrawColor(180, 180, 180);
		$y = $this->page_hauteur - $this->marge_basse - 5;
		$pdf->Line($this->marge_gauche, $y - 2, $this->page_largeur - $this->marge_droite, $y - 2);
		$pdf->SetFont('', '', 7);
		$pdf->SetXY($this->marge_gauche, $y);
		$pdf->MultiCell($this->tableWidth() - 30, 3, $outputlangs->convToOutputCharset($this->emetteur->name), 0, 'L');
		$pdf->SetXY($this->page_largeur - $this->marge_droite - 30, $y);
		$totalPages = (method_exists($pdf, 'getAliasNbPages') ? $pdf->getAliasNbPages() : '');
		$pdf->MultiCell(30, 3, $pdf->PageNo().($totalPages !== '' ? ' / '.$totalPages : ''), 0, 'R');
	}

	/** @return float */
	protected function tableWidth()
	{
		return $this->page_largeur - $this->marge_gauche - $this->marge_droite;
	}

	/** @return void */
	protected function scaleColumnsToPage()
	{
		$total = 0;
		foreach ($this->cols as $col) $total += $col['width'];
		$ratio = $this->tableWidth() / $total;
		$used = 0;
		$last = count($this->cols) - 1;
		foreach ($this->cols as $index => $col) {
			$this->cols[$index]['width'] = ($index === $last ? $this->tableWidth() - $used : round($col['width'] * $ratio, 2));
			$used += $this->cols[$index]['width'];
		}
	}
}
