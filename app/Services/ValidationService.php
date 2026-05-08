<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;

class ValidationService
{
    public function verifierRepartitionEncadrants(array $planning, Collection $professeurs, int $minEtudiants = 3, int $maxEtudiants = 4): array
    {
        $anomalies = [];
        $counts = [];

        foreach ($professeurs as $prof) {
            $counts[$prof->id] = 0;
        }

        foreach ($planning as $p) {
            $counts[$p['encadrant_id']]++;
        }

        foreach ($counts as $profId => $nb) {
            $prof = $professeurs->firstWhere('id', $profId);
            if ($nb < $minEtudiants) {
                $anomalies[] = "{$prof->prenom} {$prof->nom} encadre seulement {$nb} étudiant(s) (minimum {$minEtudiants})";
            } elseif ($nb > $maxEtudiants) {
                $anomalies[] = "{$prof->prenom} {$prof->nom} encadre {$nb} étudiants (maximum {$maxEtudiants})";
            }
        }

        return $anomalies;
    }

    public function verifierConflitsPlanning(array $planning): array
    {
        $anomalies = [];
        $n = count($planning);

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $planning[$i];
                $b = $planning[$j];

                if ($a['date'] != $b['date']) continue;

                $startA = Carbon::parse($a['date'] . ' ' . $a['heure_debut']);
                $endA   = Carbon::parse($a['date'] . ' ' . $a['heure_fin']);
                $startB = Carbon::parse($b['date'] . ' ' . $b['heure_debut']);
                $endB   = Carbon::parse($b['date'] . ' ' . $b['heure_fin']);

                if ($startA < $endB && $endA > $startB) {
                    if ($a['salle_id'] == $b['salle_id']) {
                        $anomalies[] = "Chevauchement de salle entre etudiants {$a['etudiant_id']} et {$b['etudiant_id']} le {$a['date']}";
                    }

                    $profsA = [$a['encadrant_id'], $a['jury1_id'], $a['jury2_id']];
                    $profsB = [$b['encadrant_id'], $b['jury1_id'], $b['jury2_id']];
                    if (array_intersect($profsA, $profsB)) {
                        $anomalies[] = "Professeur en double entre etudiants {$a['etudiant_id']} et {$b['etudiant_id']} le {$a['date']}";
                    }
                }

                $diff = $startA >= $endB ? $startA->diffInMinutes($endB) : $startB->diffInMinutes($endA);
                if ($diff < 60 && $diff > 0) {
                    $profsA = [$a['encadrant_id'], $a['jury1_id'], $a['jury2_id']];
                    $profsB = [$b['encadrant_id'], $b['jury1_id'], $b['jury2_id']];
                    if (array_intersect($profsA, $profsB)) {
                        $anomalies[] = "Délai < 1h pour un professeur entre étudiants {$a['etudiant_id']} et {$b['etudiant_id']} le {$a['date']}";
                    }
                }
            }
        }

        return $anomalies;
    }

    public function calculerStatistiques(array $planning, Collection $etudiants, Collection $professeurs): array
    {
        $etudiantsParProf = [];
        foreach ($professeurs as $prof) {
            $etudiantsParProf[$prof->id] = ['prof' => $prof, 'count' => 0];
        }
        foreach ($planning as $p) {
            $etudiantsParProf[$p['encadrant_id']]['count']++;
        }

        $soutenancesParProf = [];
        foreach ($professeurs as $prof) {
            $soutenancesParProf[$prof->id] = ['prof' => $prof, 'count' => 0];
        }
        foreach ($planning as $p) {
            $soutenancesParProf[$p['encadrant_id']]['count']++;
            $soutenancesParProf[$p['jury1_id']]['count']++;
            $soutenancesParProf[$p['jury2_id']]['count']++;
        }

        $soutenancesParFiliere = [];
        foreach ($etudiants as $etudiant) {
            $soutenancesParFiliere[$etudiant->filiere] = 0;
        }
        foreach ($planning as $p) {
            $etudiant = $etudiants->firstWhere('id', $p['etudiant_id']);
            if ($etudiant) {
                $soutenancesParFiliere[$etudiant->filiere]++;
            }
        }

        return [
            'etudiantsParProf' => $etudiantsParProf,
            'soutenancesParProf' => $soutenancesParProf,
            'soutenancesParFiliere' => $soutenancesParFiliere,
        ];
    }
}
