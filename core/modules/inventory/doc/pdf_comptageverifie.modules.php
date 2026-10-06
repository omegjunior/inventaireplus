<?php
/* Copyright (C) 2026 Omega Junior
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

require_once __DIR__.'/pdf_controlecontributions.modules.php';

/**
 * PDF model for the completed second count.
 */
class pdf_comptageverifie extends pdf_controlecontributions
{
	/** @var string */
	public $name = 'comptageverifie';
	/** @var string */
	public $description = 'Verified collaborative inventory count';
	/** @var string */
	protected $documentTitleKey = 'InventoryPlusCollaborativeVerificationSheet';

	/** @param DoliDB $db Database handler */
	public function __construct($db)
	{
		parent::__construct($db);
		$withBatch = isModEnabled('productbatch');
		$this->cols = array(
			array('key' => 'num', 'label' => 'No.', 'width' => 10, 'align' => 'C'),
			array('key' => 'ref', 'label' => 'Ref', 'width' => 32, 'align' => 'L'),
			array('key' => 'label', 'label' => 'Label', 'width' => ($withBatch ? 72 : 94), 'align' => 'L'),
		);
		if ($withBatch) $this->cols[] = array('key' => 'batch', 'label' => 'Batch', 'width' => 28, 'align' => 'L');
		$this->cols[] = array('key' => 'first', 'label' => 'InventoryPlusCollaborativeFirstCountQty', 'width' => 29, 'align' => 'R');
		$this->cols[] = array('key' => 'verified', 'label' => 'InventoryPlusCollaborativeVerifiedQty', 'width' => 29, 'align' => 'R');
		$this->cols[] = array('key' => 'difference', 'label' => 'InventoryPlusCollaborativeControlDifference', 'width' => 25, 'align' => 'R');
		$this->cols[] = array('key' => 'verifier', 'label' => 'InventoryPlusCollaborativeVerifier', 'width' => 55, 'align' => 'L');
		$this->scaleColumnsToPage();
	}

	/** @return int */
	public function write_file($parameters, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		global $langs, $mysoc, $user;

		if (!is_object($outputlangs)) $outputlangs = $langs;
		$outputlangs->loadLangs(array('main', 'stocks', 'products', 'productbatch', 'inventaireplus@inventaireplus'));
		$dataset = (!empty($parameters['dataset']) && is_array($parameters['dataset']) ? $parameters['dataset'] : array());
		$sequence = (!empty($parameters['sequence']) ? (int) $parameters['sequence'] : 0);
		$dir = (!empty($parameters['diroutput']) ? $parameters['diroutput'] : '');
		if (empty($dataset['lines']) || empty($dataset['context']) || $sequence <= 0 || $dir === '') return 0;
		if (!file_exists($dir) && dol_mkdir($dir) < 0) return 0;

		$inventoryRefSafe = ((int) $dataset['context']['inventory_id']).'_'.dol_sanitizeFileName(dol_trunc($dataset['context']['inventory_ref'], 64, 'right', 'UTF-8', 1));
		$filename = 'comptage_verifie_'.$inventoryRefSafe.'_'.$sequence.'_'.substr($dataset['content_hash'], 0, 12).'.pdf';
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
		$pdf->SetTitle($outputlangs->convToOutputCharset($outputlangs->transnoentities($this->documentTitleKey).' '.$dataset['context']['inventory_ref']));
		$pdf->SetCreator('InventairePlus '.DOL_VERSION);
		$pdf->SetAuthor($mysoc->name.($user->id > 0 ? ' - '.$user->getFullName($outputlangs) : ''));
		if (method_exists($pdf, 'AliasNbPages')) $pdf->AliasNbPages();
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
					'ref' => $line['product_ref'],
					'label' => $line['product_label'],
					'batch' => $line['batch'],
					'first' => price($line['qty_first']),
					'verified' => price($line['qty_verified']),
					'difference' => price($line['difference']),
					'verifier' => ($line['verifier_name'] !== '' ? $line['verifier_name'] : $line['verifier_login']),
				);
				$this->renderRow($pdf, $y, $rowHeight, $cells, $outputlangs);
				$y += $rowHeight;
				$lineNumber++;
			}
			if ($y + 6 > $bottomLimit) $y = $this->addPage($pdf, $dataset, $sequence, $outputlangs);
			$pdf->SetFont('', 'B', 7);
			$pdf->SetXY($this->marge_gauche, $y);
			$totals = $outputlangs->transnoentities('Total').' '.$zone['label'].' : '.price($zone['total_first']).' / '.price($zone['total_verified']);
			$pdf->MultiCell($this->tableWidth(), 6, $outputlangs->convToOutputCharset($totals), 1, 'R', false, 0, '', '', true, 0, false, true, 6, 'M');
			$y += 8;
		}
		$this->renderFooter($pdf, $outputlangs);
		$pdf->Close();
		$pdf->Output($file, 'F');
		$this->result = array('fullpath' => $file, 'relativefile' => 'inventaireplus/verification/'.$inventoryRefSafe.'/'.$filename);
		return 1;
	}

	/** @return float */
	protected function rowHeight(&$pdf, $line, $outputlangs)
	{
		$pdf->SetFont('', '', 7);
		$values = array('ref' => $line['product_ref'], 'label' => $line['product_label'], 'batch' => $line['batch'], 'verifier' => ($line['verifier_name'] !== '' ? $line['verifier_name'] : $line['verifier_login']));
		$maxLines = 1;
		foreach ($this->cols as $col) {
			if (!isset($values[$col['key']]) || !method_exists($pdf, 'getNumLines')) continue;
			$maxLines = max($maxLines, (int) $pdf->getNumLines($outputlangs->convToOutputCharset($values[$col['key']]), $col['width'] - 1));
		}
		return max(7, $maxLines * 3.5 + 1);
	}
}
