<?php

namespace App\Services;

use App\Models\Etudiant;
use App\Models\Professeur;
use App\Models\Salle;
use App\Utils\DateTimeHelper;
use Illuminate\Support\Collection;

class SchedulerService
{
    private DateTimeHelper $dateTimeHelper;
    private ConflictCheckerService $conflictChecker;
    private EncadrantAssignmentService $encadrantAssigner;
    private JuryAssignmentService $juryAssigner;

    public function __construct()
    {
        $this->dateTimeHelper = new DateTimeHelper();
        $this->conflictChecker = new ConflictCheckerService();
        $this->encadrantAssigner = new EncadrantAssignmentService();
        $this->juryAssigner = new JuryAssignmentService();
    }

    public function generate(
        Collection $etudiants,
        Collection $professeurs,
        Collection $salles,
        string $dateDebut = '2026-05-12',
        string $dateFin = '2026-05-15'
    ): array {
        $encadrants = $this->encadrantAssigner->assign($etudiants, $professeurs);
        $creneaux = $this->dateTimeHelper->generate($dateDebut, $dateFin);

        $orders = $this->generateStudentOrders($etudiants);

        foreach ($orders as $order) {
            try {
                $planning = [];
                $juryAttributions = [];

                foreach ($order as $etudiant) {
                    $encadrantId = $encadrants[$etudiant->id];
                    $placed = false;

                    foreach ($creneaux as $creneau) {
                        $salleLibre = $this->trouverSalleLibre($creneau, $planning, $salles);
                        if ($salleLibre === null) continue;

                        try {
                            $jury = $this->juryAssigner->assign(
                                $etudiant,
                                $encadrantId,
                                $professeurs,
                                $juryAttributions,
                                $planning
                            );
                        } catch (\RuntimeException $e) {
                            continue;
                        }

                        $ok = $this->conflictChecker->isSlotAvailable(
                            $creneau,
                            $encadrantId,
                            $jury[0],
                            $jury[1],
                            $salleLibre->id,
                            $planning
                        );

                        if ($ok) {
                            $planning[] = [
                                'etudiant_id'  => $etudiant->id,
                                'encadrant_id' => $encadrantId,
                                'jury1_id'     => $jury[0],
                                'jury2_id'     => $jury[1],
                                'salle_id'     => $salleLibre->id,
                                'date'         => $creneau['date'],
                                'heure_debut'  => $creneau['heure_debut'],
                                'heure_fin'    => $creneau['heure_fin'],
                            ];
                            $juryAttributions[$etudiant->id] = $jury;
                            $placed = true;
                            break;
                        }
                    }

                    if (!$placed) {
                        throw new \RuntimeException("Impossible de placer l'étudiant {$etudiant->id}");
                    }
                }

                return $planning;

            } catch (\RuntimeException $e) {
                continue;
            }
        }

        throw new \RuntimeException("Impossible de générer un planning complet.");
    }

private function generateStudentOrders(Collection $etudiants): array
{
    // Récupérer l'assignation des encadrants (déjà faite avec EncadrantAssignmentService)
    $professeurs = \App\Models\Professeur::all();
    $encadrants = $this->encadrantAssigner->assign($etudiants, $professeurs);
    
    // Grouper les étudiants par encadrant
    $groupes = [];
    foreach ($etudiants as $etudiant) {
        $profId = $encadrants[$etudiant->id] ?? 0;
        $groupes[$profId][] = $etudiant;
    }
    
    // Créer plusieurs ordres en intercalant les groupes
    $orders = [];
    for ($i = 0; $i < 50; $i++) {
        $order = [];
        $groupesCopy = $groupes;
        // Mélanger l'ordre des groupes
        $keys = array_keys($groupesCopy);
        shuffle($keys);
        // Tant qu'il reste des étudiants dans les groupes
        while (true) {
            $added = false;
            foreach ($keys as $key) {
                if (!empty($groupesCopy[$key])) {
                    $order[] = array_shift($groupesCopy[$key]);
                    $added = true;
                }
            }
            if (!$added) break;
        }
        $orders[] = $order;
    }
    
    return $orders;
}

    private function trouverSalleLibre(array $creneau, array $planning, Collection $salles): ?Salle
    {
        foreach ($salles as $salle) {
            $occupee = false;
            foreach ($planning as $p) {
                if (
                    $p['salle_id'] == $salle->id &&
                    $p['date'] == $creneau['date'] &&
                    !(
                        $creneau['heure_fin'] <= $p['heure_debut'] ||
                        $creneau['heure_debut'] >= $p['heure_fin']
                    )
                ) {
                    $occupee = true;
                    break;
                }
            }
            if (!$occupee) {
                return $salle;
            }
        }
        return null;
    }
}