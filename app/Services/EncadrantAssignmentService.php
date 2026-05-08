<?php

namespace App\Services;

use Illuminate\Support\Collection;

class EncadrantAssignmentService
{
    /**
     * Retourne l'encadrant déjà attribué à chaque étudiant.
     * Si un étudiant n'a pas d'encadrant, on lui assigne le professeur le moins chargé.
     */
    public function assign(Collection $etudiants, Collection $professeurs, int $maxParProf = 5): array
    {
        $assignment = [];
        $counts = $professeurs->pluck('id')->mapWithKeys(fn($id) => [$id => 0])->toArray();

        // D'abord, utiliser les encadrants déjà fixés en base
        foreach ($etudiants as $etudiant) {
            if ($etudiant->professeur_ID) {
                $assignment[$etudiant->id] = $etudiant->professeur_ID;
                $counts[$etudiant->professeur_ID]++;
            }
        }

        // Ensuite, attribuer un encadrant à ceux qui n'en ont pas (si nécessaire)
        foreach ($etudiants as $etudiant) {
            if (isset($assignment[$etudiant->id])) continue;

            $candidats = $professeurs->filter(function ($prof) use ($etudiant, $counts, $maxParProf) {
                return $counts[$prof->id] < $maxParProf
                    && $prof->specialite === $this->mapFiliereToSpecialite($etudiant->filiere);
            });

            if ($candidats->isEmpty()) {
                $candidats = $professeurs->filter(fn($prof) => $counts[$prof->id] < $maxParProf);
            }

            if ($candidats->isEmpty()) {
                throw new \RuntimeException("Aucun professeur disponible pour l'étudiant {$etudiant->id}");
            }

            $chosen = $candidats->sortBy(fn($prof) => $counts[$prof->id])->first();
            $assignment[$etudiant->id] = $chosen->id;
            $counts[$chosen->id]++;
        }

        return $assignment;
    }

    private function mapFiliereToSpecialite(string $filiere): string
    {
        return match ($filiere) {
            'TDIA', 'ID' => 'Informatique',
            default => 'Informatique'
        };
    }
}