<?php
/**
 * @file    ExportService.php
 * @package App\Service
 * @desc    Génération de fichiers XLSX pour la consolidation (UC06).
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\Saisie;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as OpenSpoutXlsxWriter;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class ExportService
{
  /**
   * Écrit un export consolidation en streaming (adapté aux gros volumes).
   *
   * @param iterable<array{numCnss: string, ifu: string, raisonSociale: string}> $rows
   *
   * @return int Nombre de lignes exportées (hors en-tête)
   */
  public function writeConsolidationXlsxToFile(iterable $rows, string $filePath): int
  {
    $writer = new OpenSpoutXlsxWriter();
    $writer->openToFile($filePath);
    $writer->addRow(Row::fromValues(['num_cnss', 'ifu', 'raison_sociale']));

    $exportedCount = 0;
    foreach ($rows as $row) {
      $writer->addRow(Row::fromValues([
        $row['numCnss'],
        $row['ifu'],
        $row['raisonSociale'],
      ]));
      ++$exportedCount;
    }

    $writer->close();
    $this->assertValidXlsxFile($filePath);

    return $exportedCount;
  }

  /**
   * Génère un fichier XLSX en mémoire à partir des saisies éligibles.
   *
   * @param list<Saisie> $saisies
   */
  public function generateXlsx(array $saisies): string
  {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Consolidation IFU');

    $sheet->fromArray(['num_cnss', 'ifu', 'raison_sociale'], null, 'A1');

    $row = 2;
    foreach ($saisies as $saisie) {
      $sheet->setCellValueExplicit('A' . $row, $saisie->getNumCnss(), DataType::TYPE_STRING);
      $sheet->setCellValueExplicit('B' . $row, $saisie->getIfuAgent1(), DataType::TYPE_STRING);
      $sheet->setCellValue('C' . $row, $saisie->getEmployeur()?->getRaisonSociale() ?? '');
      ++$row;
    }

    if ($row > 2) {
      $sheet->getStyle('A2:B' . ($row - 1))
        ->getNumberFormat()
        ->setFormatCode(NumberFormat::FORMAT_TEXT);
    }

    foreach (range('A', 'C') as $col) {
      $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    return $this->saveSpreadsheetToString($spreadsheet);
  }

  /**
   * Génère un fichier XLSX du journal d'audit.
   *
   * @param list<AuditLog> $logs
   */
  public function generateAuditXlsx(array $logs): string
  {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Journal audit');

    $sheet->fromArray([
      'ID',
      'Date & Heure',
      'Utilisateur',
      'Nom',
      'Prénom',
      'Action',
      'Entité cible',
      'Adresse IP',
      'Valeur avant',
      'Valeur après',
    ], null, 'A1');

    $row = 2;
    foreach ($logs as $log) {
      $user = $log->getUser();
      $sheet->fromArray([
        $log->getId(),
        $log->getTimestamp()->format('Y-m-d H:i:s'),
        $user?->getUsername() ?? '',
        $user?->getNom() ?? '',
        $user?->getPrenom() ?? '',
        $log->getAction(),
        $this->shortEntiteCible($log->getEntiteCible()),
        $log->getIpAddress() ?? '',
        $log->getValeurAvant() ?? '',
        $log->getValeurApres() ?? '',
      ], null, 'A' . $row);
      ++$row;
    }

    foreach (range('A', 'J') as $col) {
      $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    return $this->saveSpreadsheetToString($spreadsheet);
  }

  public function createTempXlsxPath(): string
  {
    $path = tempnam(sys_get_temp_dir(), 'arrimage_ifu_');
    if ($path === false) {
      throw new \RuntimeException('Impossible de créer un fichier temporaire pour l\'export.');
    }

    return $path . '.xlsx';
  }

  private function saveSpreadsheetToString(Spreadsheet $spreadsheet): string
  {
    $tempPath = $this->createTempXlsxPath();

    try {
      $writer = new Xlsx($spreadsheet);
      $writer->save($tempPath);
      $spreadsheet->disconnectWorksheets();
      $this->assertValidXlsxFile($tempPath);

      $content = file_get_contents($tempPath);
      if ($content === false || $content === '') {
        throw new \RuntimeException('Le fichier XLSX généré est vide.');
      }

      return $content;
    } finally {
      if (is_file($tempPath)) {
        unlink($tempPath);
      }
    }
  }

  private function assertValidXlsxFile(string $filePath): void
  {
    $handle = fopen($filePath, 'rb');
    if ($handle === false) {
      throw new \RuntimeException('Impossible de lire le fichier XLSX généré.');
    }

    $magic = fread($handle, 2);
    fclose($handle);

    if ($magic !== 'PK') {
      throw new \RuntimeException('Le fichier XLSX généré est invalide.');
    }
  }

  private function shortEntiteCible(?string $entiteCible): string
  {
    if ($entiteCible === null || $entiteCible === '') {
      return '';
    }

    if (str_contains($entiteCible, '\\')) {
      $parts = explode('\\', $entiteCible);

      return end($parts) ?: $entiteCible;
    }

    return $entiteCible;
  }
}
