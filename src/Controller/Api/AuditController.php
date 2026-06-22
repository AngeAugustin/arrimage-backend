<?php
/**
 * @file    AuditController.php
 * @package App\Controller\Api
 * @desc    Consultation du journal d'audit en lecture seule (UC09, RG-13).
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Controller\Api;

use App\Repository\AuditLogRepository;
use App\Service\AuditLogPresenter;
use App\Service\ExportService;
use App\Util\Pagination;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/audit')]
#[IsGranted('ROLE_ADMIN')]
final class AuditController extends AbstractApiController
{
  public function __construct(
    private readonly AuditLogRepository $auditLogRepository,
    private readonly AuditLogPresenter $presenter,
    private readonly ExportService $exportService,
  ) {
  }

  /**
   * Journal d'audit paginé et filtrable (lecture seule).
   */
  #[Route('', name: 'api_audit_list', methods: ['GET'])]
  public function list(Request $request): JsonResponse
  {
    [$page, $limit] = Pagination::parse($request, 20);
    [$userId, $action, $dateFrom, $dateTo] = $this->parseFilters($request);

    $result = $this->auditLogRepository->findFiltered($page, $limit, $userId, $action, $dateFrom, $dateTo);

    return $this->successResponse([
      'items' => array_map(fn ($log) => $this->presenter->present($log), $result['items']),
      ...Pagination::meta($result['total'], $page, $limit),
    ]);
  }

  /**
   * Exporte le journal d'audit filtré au format XLSX.
   */
  #[Route('/export', name: 'api_audit_export', methods: ['GET'])]
  public function export(Request $request): Response
  {
    [$userId, $action, $dateFrom, $dateTo] = $this->parseFilters($request);

    $total = $this->auditLogRepository->countFiltered($userId, $action, $dateFrom, $dateTo);

    if ($total === 0) {
      return $this->errorResponse('NO_DATA', 'Aucune entrée à exporter pour les filtres sélectionnés.', 422);
    }

    if ($total > AuditLogRepository::MAX_EXPORT) {
      return $this->errorResponse(
        'EXPORT_LIMIT_EXCEEDED',
        sprintf(
          'Trop d\'entrées à exporter (%d). Affinez les filtres (maximum %d).',
          $total,
          AuditLogRepository::MAX_EXPORT,
        ),
        422,
      );
    }

    $logs = $this->auditLogRepository->findAllFiltered($userId, $action, $dateFrom, $dateTo);
    $filename = 'audit_log_' . date('Ymd_His') . '.xlsx';

    $response = new Response($this->exportService->generateAuditXlsx($logs));
    $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
    $response->headers->set('X-Export-Count', (string) $total);

    return $response;
  }

  /**
   * @return array{0: ?int, 1: ?string, 2: ?\DateTimeImmutable, 3: ?\DateTimeImmutable}
   */
  private function parseFilters(Request $request): array
  {
    $userId = $request->query->get('userId') ? (int) $request->query->get('userId') : null;
    $action = $request->query->get('action');
    $dateFrom = $request->query->get('dateFrom')
      ? new \DateTimeImmutable((string) $request->query->get('dateFrom'))
      : null;
    $dateTo = $request->query->get('dateTo')
      ? new \DateTimeImmutable((string) $request->query->get('dateTo') . ' 23:59:59')
      : null;

    return [$userId, $action, $dateFrom, $dateTo];
  }
}
