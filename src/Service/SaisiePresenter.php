<?php
/**
 * @file    SaisiePresenter.php
 * @package App\Service
 * @desc    Formate les entités Saisie en tableaux JSON selon le contexte (RG-11).
 *
 * @author  CNSS–DSI
 * @since   2026-06
 */

namespace App\Service;

use App\Entity\Saisie;
use App\Entity\Utilisateur;

final class SaisiePresenter
{
  /**
   * Présentation complète pour Agent 1 et contrôleur.
   *
   * @return array<string, mixed>
   */
  public function presentFull(Saisie $saisie): array
  {
    return [
      'id' => $saisie->getId(),
      'numCnss' => $saisie->getNumCnss(),
      'raisonSociale' => $saisie->getEmployeur()?->getRaisonSociale() ?? '',
      'ifuAgent1' => $saisie->getIfuAgent1(),
      'agent1' => $this->presentUser($saisie->getAgent1()),
      'dtSaisie1' => $saisie->getDtSaisie1()->format(\DateTimeInterface::ATOM),
      'ifuAgent2' => $saisie->getIfuAgent2(),
      'agent2' => $saisie->getAgent2() ? $this->presentUser($saisie->getAgent2()) : null,
      'dtSaisie2' => $saisie->getDtSaisie2()?->format(\DateTimeInterface::ATOM),
      'flagConsolide' => $saisie->isFlagConsolide(),
      'dtExport' => $saisie->getDtExport()?->format(\DateTimeInterface::ATOM),
      'status' => $saisie->getStatus(),
      'dtModif' => $saisie->getDtModif()?->format(\DateTimeInterface::ATOM),
    ];
  }

  /**
   * Présentation Agent 2 — sans ifu_agent1 (RG-11).
   *
   * @return array<string, mixed>
   */
  public function presentForAgent2(Saisie $saisie): array
  {
    return [
      'id' => $saisie->getId(),
      'numCnss' => $saisie->getNumCnss(),
      'raisonSociale' => $saisie->getEmployeur()?->getRaisonSociale() ?? '',
      'ifuAgent2' => $saisie->getIfuAgent2(),
      'agent2' => $saisie->getAgent2() ? $this->presentUser($saisie->getAgent2()) : null,
      'dtSaisie2' => $saisie->getDtSaisie2()?->format(\DateTimeInterface::ATOM),
      'status' => $saisie->getStatus(),
      'flagConsolide' => $saisie->isFlagConsolide(),
    ];
  }

  /**
   * Présentation pour le tableau des discordances (UC04).
   *
   * @return array<string, mixed>
   */
  public function presentDiscordance(Saisie $saisie): array
  {
    return $this->presentFull($saisie);
  }

  /**
   * @return array{id: int, nom: string, prenom: string}|null
   */
  private function presentUser(?Utilisateur $user): ?array
  {
    if ($user === null) {
      return null;
    }

    return [
      'id' => $user->getId(),
      'nom' => $user->getNom(),
      'prenom' => $user->getPrenom(),
    ];
  }
}
