<?php

namespace App\Services;

use Carbon\Carbon;

class ConflictCheckerService
{
    public function isSlotAvailable(
        array $slot,
        int $encadrantId,
        int $jury1Id,
        int $jury2Id,
        int $salleId,
        array $existingPlannings
    ): bool {
        $slotStart = Carbon::parse($slot['date'] . ' ' . $slot['heure_debut']);
        $slotEnd   = Carbon::parse($slot['date'] . ' ' . $slot['heure_fin']);

        $nouveauxProfs = [$encadrantId, $jury1Id, $jury2Id];

        foreach ($existingPlannings as $planning) {
            // 1. Même salle déjà occupée ?
            if ($planning['salle_id'] == $salleId && $planning['date'] == $slot['date']) {
                $existingStart = Carbon::parse($planning['date'] . ' ' . $planning['heure_debut']);
                $existingEnd   = Carbon::parse($planning['date'] . ' ' . $planning['heure_fin']);
                if ($slotStart < $existingEnd && $slotEnd > $existingStart) {
                    return false;
                }
            }

            // 2. Un des professeurs est déjà occupé ce jour-là ?
            $profsExistants = [
                $planning['encadrant_id'],
                $planning['jury1_id'],
                $planning['jury2_id']
            ];

            if (array_intersect($nouveauxProfs, $profsExistants) === []) {
                continue;
            }

            if ($planning['date'] != $slot['date']) {
                continue;
            }

            $existingStart = Carbon::parse($planning['date'] . ' ' . $planning['heure_debut']);
            $existingEnd   = Carbon::parse($planning['date'] . ' ' . $planning['heure_fin']);

            // Seulement interdire les chevauchements (pas de délai d'une heure)
            if ($slotStart < $existingEnd && $slotEnd > $existingStart) {
                return false;
            }
        }

        return true;
    }
}