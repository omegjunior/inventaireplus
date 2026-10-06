<?php
/* Copyright (C) 2026 Omega Junior
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Write the spreadsheet matching a first-count control PDF.
 *
 * @param array<string,mixed> $dataset Control dataset
 * @param int $sequence Control sequence
 * @param string $dirOutput Output directory
 * @param Translate $langs Output language
 * @param bool $blindCount Hide first-count quantities
 * @return array<string,mixed>
 */
function inventaireplusWriteControlSpreadsheet($dataset, $sequence, $dirOutput, $langs, $blindCount = false)
{
	global $db;

	$headers = array('No.', 'InventoryPlusCollaborativeZone', 'Date', 'User', 'Ref', 'Label');
	if (isModEnabled('productbatch')) $headers[] = 'Batch';
	$headers = array_merge($headers, array('InventoryPlusCollaborativeFirstCountQty', 'InventoryPlusCollaborativeVerifiedQty', 'InventoryPlusCollaborativeControlDifference', 'InventoryPlusCollaborativeObservation'));
	$rows = array();
	$number = 0;
	foreach ($dataset['zones'] as $zone) {
		foreach ($zone['lines'] as $line) {
			$number++;
			$row = array($number, $line['zone'], dol_print_date($db->jdate($line['datec']), 'dayhour'), ($line['user_name'] !== '' ? $line['user_name'] : $line['user_login']), $line['product_ref'], $line['product_label']);
			if (isModEnabled('productbatch')) $row[] = $line['batch'];
			$rows[] = array_merge($row, array($blindCount ? '' : (float) $line['qty'], '', '', ''));
		}
	}
	$inventoryRefSafe = ((int) $dataset['context']['inventory_id']).'_'.dol_sanitizeFileName(dol_trunc($dataset['context']['inventory_ref'], 64, 'right', 'UTF-8', 1));
	$filename = ($blindCount ? 'second_comptage_' : 'controle_contributions_').$inventoryRefSafe.'_'.((int) $sequence).'_'.substr($dataset['content_hash'], 0, 12).'.xlsx';
	$titleKey = ($blindCount ? 'InventoryPlusCollaborativeSecondCountBlindSheet' : 'InventoryPlusCollaborativeControlSheet');
	return inventaireplusWriteInventorySpreadsheet($dataset['context'], $headers, $rows, $dirOutput, $filename, $titleKey, $langs, 'inventaireplus/control/'.$inventoryRefSafe.'/'.$filename);
}

/**
 * Write the spreadsheet matching a verified second-count PDF.
 *
 * @param array<string,mixed> $dataset Verification dataset
 * @param int $sequence Report sequence
 * @param string $dirOutput Output directory
 * @param Translate $langs Output language
 * @return array<string,mixed>
 */
function inventaireplusWriteVerificationSpreadsheet($dataset, $sequence, $dirOutput, $langs)
{
	$headers = array('No.', 'InventoryPlusCollaborativeZone', 'Ref', 'Label');
	if (isModEnabled('productbatch')) $headers[] = 'Batch';
	$headers = array_merge($headers, array('InventoryPlusCollaborativeFirstCountQty', 'InventoryPlusCollaborativeVerifiedQty', 'InventoryPlusCollaborativeControlDifference', 'InventoryPlusCollaborativeVerifier'));
	$rows = array();
	$number = 0;
	foreach ($dataset['zones'] as $zone) {
		foreach ($zone['lines'] as $line) {
			$number++;
			$row = array($number, $line['zone'], $line['product_ref'], $line['product_label']);
			if (isModEnabled('productbatch')) $row[] = $line['batch'];
			$rows[] = array_merge($row, array((float) $line['qty_first'], (float) $line['qty_verified'], (float) $line['difference'], ($line['verifier_name'] !== '' ? $line['verifier_name'] : $line['verifier_login'])));
		}
	}
	$inventoryRefSafe = ((int) $dataset['context']['inventory_id']).'_'.dol_sanitizeFileName(dol_trunc($dataset['context']['inventory_ref'], 64, 'right', 'UTF-8', 1));
	$filename = 'comptage_verifie_'.$inventoryRefSafe.'_'.((int) $sequence).'_'.substr($dataset['content_hash'], 0, 12).'.xlsx';
	return inventaireplusWriteInventorySpreadsheet($dataset['context'], $headers, $rows, $dirOutput, $filename, 'InventoryPlusCollaborativeVerificationSheet', $langs, 'inventaireplus/verification/'.$inventoryRefSafe.'/'.$filename);
}

/**
 * Write a styled XLSX inventory control sheet.
 *
 * @param array<string,mixed> $context Document context
 * @param array<int,string> $headers Translation keys
 * @param array<int,array<int,mixed>> $rows Data rows
 * @param string $dirOutput Output directory
 * @param string $filename File name
 * @param string $titleKey Translation key
 * @param Translate $langs Output language
 * @param string $relativeFile Relative document path
 * @return array<string,mixed>
 */
function inventaireplusWriteInventorySpreadsheet($context, $headers, $rows, $dirOutput, $filename, $titleKey, $langs, $relativeFile)
{
	require_once DOL_DOCUMENT_ROOT.'/includes/phpoffice/phpspreadsheet/src/autoloader.php';
	require_once DOL_DOCUMENT_ROOT.'/includes/Psr/autoloader.php';
	require_once PHPEXCELNEW_PATH.'Spreadsheet.php';
	if (!class_exists(Spreadsheet::class) || !class_exists(Xlsx::class)) return array('ok' => false, 'error' => 'SpreadsheetLibraryUnavailable');
	if (!class_exists('ZipArchive')) return array('ok' => false, 'error' => 'ErrorPHPNeedModule');
	if (!file_exists($dirOutput) && dol_mkdir($dirOutput) < 0) return array('ok' => false, 'error' => 'ErrorCanNotCreateDir');
	$spreadsheet = new Spreadsheet();
	$spreadsheet->getProperties()->setCreator('InventairePlus '.DOL_VERSION)->setTitle($langs->transnoentities($titleKey));
	$sheet = $spreadsheet->getActiveSheet();
	$sheetTitle = preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $langs->transnoentities($titleKey));
	$sheetTitle = (function_exists('mb_substr') ? mb_substr($sheetTitle, 0, 31, 'UTF-8') : substr($sheetTitle, 0, 31));
	$sheet->setTitle($sheetTitle);
	$lastColumn = Coordinate::stringFromColumnIndex(count($headers));
	$sheet->setCellValueExplicit('A1', $langs->transnoentities($titleKey), DataType::TYPE_STRING);
	$sheet->mergeCells('A1:'.$lastColumn.'1');
	$sheet->setCellValueExplicit('A2', $langs->transnoentities('Ref').': '.$context['inventory_ref'], DataType::TYPE_STRING);
	$sheet->setCellValueExplicit('A3', $langs->transnoentities('Warehouse').': '.$context['warehouse_ref'], DataType::TYPE_STRING);
	foreach ($headers as $index => $labelKey) $sheet->setCellValueExplicitByColumnAndRow($index + 1, 5, $langs->transnoentities($labelKey), DataType::TYPE_STRING);
	$rowNumber = 6;
	foreach ($rows as $row) {
		foreach ($row as $column => $value) {
			if (is_string($value)) $sheet->setCellValueExplicitByColumnAndRow($column + 1, $rowNumber, $value, DataType::TYPE_STRING);
			else $sheet->setCellValueByColumnAndRow($column + 1, $rowNumber, $value);
		}
		$rowNumber++;
	}
	$lastRow = max(5, $rowNumber - 1);
	$sheet->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true)->setSize(14);
	$sheet->getStyle('A5:'.$lastColumn.'5')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
	$sheet->getStyle('A5:'.$lastColumn.'5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF2D4B73');
	$sheet->getStyle('A5:'.$lastColumn.$lastRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFB7B7B7');
	$sheet->getStyle('A5:'.$lastColumn.$lastRow)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
	$sheet->getStyle('A5:'.$lastColumn.'5')->getAlignment()->setWrapText(true);
	$sheet->freezePane('A6');
	$sheet->setAutoFilter('A5:'.$lastColumn.$lastRow);
	foreach (range(1, count($headers)) as $columnIndex) {
		$sheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->setAutoSize(true);
	}
	$file = $dirOutput.'/'.$filename;
	try {
		$writer = new Xlsx($spreadsheet);
		$writer->save($file);
	} catch (Throwable $e) {
		$spreadsheet->disconnectWorksheets();
		return array('ok' => false, 'error' => $e->getMessage());
	}
	$spreadsheet->disconnectWorksheets();
	return array('ok' => true, 'fullpath' => $file, 'relativefile' => $relativeFile);
}
