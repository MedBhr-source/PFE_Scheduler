<?php

namespace App\Services;

use App\Models\Etudiant;
use Illuminate\Support\Collection;

class JuryAssignmentService
{
    public function assign(
        Etudiant $etudiant,
        int $encadrantId,
        Collection $professeurs,
        array $existingJurys = [],
        array $planning = []
    ): array {
        // Tous les professeurs sauf l'encadrant peuvent être jury (aucune restriction de discipline)
        $candidats = $professeurs->filter(fn($prof) => $prof->id !== $encadrantId);

        if ($candidats->count() < 2) {
            throw new \RuntimeException("Pas assez de professeurs pour constituer un jury pour l'étudiant {$etudiant->id}");
        }

        // Calculer la charge actuelle (nombre de fois déjà jury)
        $charge = [];
        foreach ($professeurs as $prof) {
            $charge[$prof->id] = 0;
        }
        foreach ($existingJurys as $jury) {
            if (isset($jury[0])) $charge[$jury[0]]++;
            if (isset($jury[1])) $charge[$jury[1]]++;
        }

        // Choisir les deux professeurs les moins chargés
        $sorted = $candidats->sortBy(fn($prof) => $charge[$prof->id] ?? 0)->values();

        return [$sorted[0]->id, $sorted[1]->id];
    }
}