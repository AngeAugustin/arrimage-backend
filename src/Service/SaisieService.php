<?php
/**
 * @file    SaisieService.php
 * @package App\Service
 * @desc    Logique métier des saisies IFU (création, contre-saisie, correction).
 *
 * Règles métier couvertes :
 *   - RG-01 à RG-07, RG-11, RG-16
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Service;

use App\DTO\ContresaisieRequest;
use App\DTO\CorrectionRequest;
use App\DTO\SaisieRequest;
use App\Entity\Saisie;
use App\Entity\Utilisateur;
use App\Exception\CnssNotFoundException;
use App\Exception\DuplicateSaisieException;
use App\Exception\EntiteConsolideeException;
use App\Exception\IneligibleContresaisieException;
use App\Repository\EmployeurRepository;
use App\Repository\SaisieRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class SaisieService
{
  public function __construct(
    private readonly SaisieRepository $saisieRepository,
    private readonly EmployeurRepository $employeurRepository,
    private readonly EntityManagerInterface $entityManager,
    private readonly AuditService $auditService,
    private readonly AuditSnapshotFactory $auditSnapshotFactory,
    private readonly RequestStack $requestStack,
  ) {
  }

  /**
   * Enregistre la première saisie IFU d'un Agent 1 (UC02).
   *
   * @throws CnssNotFoundException
   * @throws DuplicateSaisieException
   */
  public function createSaisie(SaisieRequest $dto, Utilisateur $agent): Saisie
  {
    $employeur = $this->employeurRepository->find($dto->numCnss);
    if ($employeur === null) {
      $this->logRefusedAction($agent, 'SAISIE_REFUSEE', 'CNSS_NOT_FOUND', $dto->numCnss, $dto->ifu);
      throw new CnssNotFoundException();
    }

    $existing = $this->saisieRepository->findByNumCnss($dto->numCnss);
    if ($existing !== null) {
      if ($existing->isFlagConsolide()) {
        $this->logRefusedAction(
          $agent,
          'SAISIE_REFUSEE',
          'ENTITY_CONSOLIDATED',
          $dto->numCnss,
          $dto->ifu,
          $existing,
        );
        throw new EntiteConsolideeException($existing->getDtExport() ?? new \DateTimeImmutable());
      }
      $this->logRefusedAction(
        $agent,
        'SAISIE_REFUSEE',
        'DUPLICATE_CNSS',
        $dto->numCnss,
        $dto->ifu,
        $existing,
      );
      throw new DuplicateSaisieException();
    }

    $saisie = new Saisie();
    $saisie->setEmployeur($employeur)
      ->setIfuAgent1($dto->ifu)
      ->setAgent1($agent)
      ->setStatus('SAISIE');

    $this->entityManager->persist($saisie);
    $this->entityManager->flush();

    return $saisie;
  }

  /**
   * Vérifie l'éligibilité à la contre-saisie et retourne les infos sans IFU Agent 1 (RG-11).
   *
   * @return array{eligible: bool, numCnss: string, raisonSociale: string}
   *
   * @throws IneligibleContresaisieException
   */
  public function getAttenteContresaisie(string $numCnss, Utilisateur $agent): array
  {
    $saisie = $this->saisieRepository->findByNumCnss($numCnss);

    if ($saisie === null) {
      $this->logRefusedAction($agent, 'CONTRESAISIE_REFUSEE', 'NOT_ELIGIBLE', $numCnss);
      throw new IneligibleContresaisieException();
    }

    if ($saisie->getIfuAgent2() !== null) {
      $this->logRefusedAction(
        $agent,
        'CONTRESAISIE_REFUSEE',
        'ALREADY_COUNTERED',
        $numCnss,
        null,
        $saisie,
      );
      throw new IneligibleContresaisieException();
    }

    if ($saisie->isFlagConsolide()) {
      $this->logRefusedAction(
        $agent,
        'CONTRESAISIE_REFUSEE',
        'ENTITY_CONSOLIDATED',
        $numCnss,
        null,
        $saisie,
      );
      throw new IneligibleContresaisieException();
    }

    return [
      'eligible' => true,
      'numCnss' => $saisie->getNumCnss(),
      'raisonSociale' => $saisie->getEmployeur()?->getRaisonSociale() ?? '',
    ];
  }

  /**
   * Enregistre la contre-saisie IFU d'un Agent 2 (UC03).
   *
   * @throws IneligibleContresaisieException
   * @throws EntiteConsolideeException
   */
  public function contresaisie(string $numCnss, ContresaisieRequest $dto, Utilisateur $agent): Saisie
  {
    $saisie = $this->saisieRepository->findByNumCnss($numCnss);

    if ($saisie === null) {
      $this->logRefusedAction($agent, 'CONTRESAISIE_REFUSEE', 'NOT_ELIGIBLE', $numCnss, $dto->ifu);
      throw new IneligibleContresaisieException();
    }

    if ($saisie->getIfuAgent2() !== null) {
      $this->logRefusedAction(
        $agent,
        'CONTRESAISIE_REFUSEE',
        'ALREADY_COUNTERED',
        $numCnss,
        $dto->ifu,
        $saisie,
      );
      throw new IneligibleContresaisieException();
    }

    if ($saisie->isFlagConsolide()) {
      $this->logRefusedAction(
        $agent,
        'CONTRESAISIE_REFUSEE',
        'ENTITY_CONSOLIDATED',
        $numCnss,
        $dto->ifu,
        $saisie,
      );
      throw new EntiteConsolideeException($saisie->getDtExport() ?? new \DateTimeImmutable());
    }

    $valeurAvant = json_encode($this->auditSnapshotFactory->saisie($saisie));

    $saisie->setIfuAgent2($dto->ifu)
      ->setAgent2($agent)
      ->setDtSaisie2(new \DateTimeImmutable())
      ->setStatus('CONTRE_SAISIE');

    $this->entityManager->flush();

    $this->logSaisieAction($agent, 'CONTRESAISIE', $saisie, $valeurAvant);

    return $saisie;
  }

  /**
   * Corrige l'IFU de l'agent connecté après discordance (UC05).
   *
   * @throws CnssNotFoundException
   * @throws EntiteConsolideeException
   */
  public function correction(string $numCnss, CorrectionRequest $dto, Utilisateur $agent): Saisie
  {
    $saisie = $this->saisieRepository->findByNumCnss($numCnss);

    if ($saisie === null) {
      throw new CnssNotFoundException();
    }

    if ($saisie->isFlagConsolide()) {
      throw new EntiteConsolideeException($saisie->getDtExport() ?? new \DateTimeImmutable());
    }

    $now = new \DateTimeImmutable();
    $valeurAvant = json_encode($this->auditSnapshotFactory->saisie($saisie));

    if ($agent->getRole() === 'agent1') {
      $saisie->setIfuAgent1($dto->ifu);
    } elseif ($agent->getRole() === 'agent2') {
      if ($saisie->getIfuAgent2() === null) {
        throw new IneligibleContresaisieException();
      }
      $saisie->setIfuAgent2($dto->ifu);
    }

    $saisie->setDtModif($now);
    $this->entityManager->flush();

    $this->logSaisieAction($agent, 'CORRECTION', $saisie, $valeurAvant);

    return $saisie;
  }

  private function logSaisieAction(
    Utilisateur $agent,
    string $action,
    Saisie $saisie,
    ?string $valeurAvant = null,
  ): void {
    $this->auditService->log(
      user: $agent,
      action: $action,
      entiteCible: Saisie::class,
      valeurAvant: $valeurAvant,
      valeurApres: json_encode($this->auditSnapshotFactory->saisie($saisie)),
      ipAddress: $this->requestStack->getCurrentRequest()?->getClientIp(),
    );
  }

  private function logRefusedAction(
    Utilisateur $agent,
    string $action,
    string $reason,
    ?string $numCnss = null,
    ?string $ifuAttempted = null,
    ?Saisie $existing = null,
  ): void {
    $this->auditService->log(
      user: $agent,
      action: $action,
      entiteCible: Saisie::class,
      valeurApres: json_encode($this->auditSnapshotFactory->refusedAttempt(
        $reason,
        $numCnss,
        $ifuAttempted,
        $existing,
      )),
      ipAddress: $this->requestStack->getCurrentRequest()?->getClientIp(),
    );
  }

  /**
   * Retourne l'IFU actuel de l'agent pour un num_cnss donné (UC05).
   *
   * @return array{numCnss: string, raisonSociale: string, ifuActuel: string, flagConsolide: bool, dtExport: ?string}
   *
   * @throws CnssNotFoundException
   */
  public function getCorrectionContext(string $numCnss, Utilisateur $agent): array
  {
    $saisie = $this->saisieRepository->findByNumCnss($numCnss);

    if ($saisie === null) {
      throw new CnssNotFoundException();
    }

    $ifuActuel = $agent->getRole() === 'agent1'
      ? $saisie->getIfuAgent1()
      : ($saisie->getIfuAgent2() ?? '');

    if ($agent->getRole() === 'agent2' && $saisie->getIfuAgent2() === null) {
      throw new IneligibleContresaisieException();
    }

    return [
      'numCnss' => $saisie->getNumCnss(),
      'raisonSociale' => $saisie->getEmployeur()?->getRaisonSociale() ?? '',
      'ifuActuel' => $ifuActuel,
      'flagConsolide' => $saisie->isFlagConsolide(),
      'dtExport' => $saisie->getDtExport()?->format(\DateTimeInterface::ATOM),
    ];
  }

  /**
   * @return list<Saisie>
   */
  public function getMesSaisies(Utilisateur $agent): array
  {
    return $this->saisieRepository->findByAgent($agent);
  }

  /**
   * @return array{
   *   items: list<Saisie>,
   *   total: int,
   *   stats: array{today: int, yesterday: int, todayDelta: int, monthTotal: int, pending: int}
   * }
   */
  public function getMesSaisiesPaginated(
    Utilisateur $agent,
    int $page,
    int $limit,
    ?string $search = null,
    ?string $status = null,
    ?string $period = null,
  ): array {
    $result = $this->saisieRepository->findByAgentPaginated($agent, $page, $limit, $search, $status, $period);

    return [
      'items' => $result['items'],
      'total' => $result['total'],
      'stats' => $this->saisieRepository->getAgentDashboardStats($agent),
    ];
  }

  /**
   * @return list<Saisie>
   */
  public function getDiscordances(): array
  {
    return $this->saisieRepository->findDiscordances();
  }

  /**
   * @return array{
   *   items: list<Saisie>,
   *   total: int,
   *   recentDelta: int,
   *   averageDelayMinutes: ?int
   * }
   */
  public function getDiscordancesPaginated(int $page, int $limit): array
  {
    $result = $this->saisieRepository->findDiscordancesPaginated($page, $limit);

    return [
      'items' => $result['items'],
      'total' => $result['total'],
      'recentDelta' => $this->saisieRepository->countRecentDiscordances(),
      'averageDelayMinutes' => $this->saisieRepository->averageDiscordanceDelayMinutes(),
    ];
  }
}
