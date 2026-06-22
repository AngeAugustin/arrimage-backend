<?php
/**
 * @file    ConsolidationController.php
 * @package App\Controller\Api
 * @desc    Endpoints de consolidation et export XLSX (UC06).
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Controller\Api;

use App\Entity\Utilisateur;
use App\Exception\ConsolidationException;
use App\Service\ConsolidationService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/consolidation')]
#[IsGranted('ROLE_ADMIN')]
final class ConsolidationController extends AbstractApiController
{
  public function __construct(
    private readonly ConsolidationService $consolidationService,
  ) {
  }

  /**
   * Aperçu des enregistrements éligibles à la consolidation.
   */
  #[Route('/preview', name: 'api_consolidation_preview', methods: ['GET'])]
  public function preview(): Response
  {
    return $this->successResponse($this->consolidationService->preview());
  }

  /**
   * Lance la consolidation atomique et retourne le fichier XLSX.
   */
  #[Route('/export', name: 'api_consolidation_export', methods: ['POST'])]
  public function export(): Response
  {
    /** @var Utilisateur $admin */
    $admin = $this->getUser();

    try {
      $result = $this->consolidationService->consolidate($admin);
    } catch (ConsolidationException $e) {
      return $this->errorResponse('NO_DATA', $e->getMessage(), 422);
    }

    return $this->fileResponse($result['filePath'], $result['filename'], $result['count']);
  }

  /**
   * Re-télécharge le fichier XLSX d'un export déjà généré.
   */
  #[Route('/export/{auditLogId}', name: 'api_consolidation_download', methods: ['GET'], requirements: ['auditLogId' => '\d+'])]
  public function download(int $auditLogId): Response
  {
    try {
      $result = $this->consolidationService->regenerateExportForAuditLog($auditLogId);
    } catch (ConsolidationException $e) {
      return $this->errorResponse('NOT_FOUND', $e->getMessage(), 404);
    }

    return $this->fileResponse($result['filePath'], $result['filename'], $result['count']);
  }

  private function fileResponse(string $filePath, string $filename, int $count): BinaryFileResponse
  {
    $response = new BinaryFileResponse($filePath);
    $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
    $response->headers->set('X-Export-Count', (string) $count);
    $response->deleteFileAfterSend(true);

    return $response;
  }
}
