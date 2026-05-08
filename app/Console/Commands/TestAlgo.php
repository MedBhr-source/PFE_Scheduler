<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\SchedulerService;
use App\Services\ValidationService;
use App\Models\Etudiant;
use App\Models\Professeur;
use App\Models\Salle;

class TestAlgo extends Command
{
    protected $signature = 'test:algo';
    protected $description = 'Planification + Validation + Dashboard';

    public function handle()
    {
        $etudiants = Etudiant::all();
        $professeurs = Professeur::all();
        $salles = Salle::all();

        if ($etudiants->isEmpty() || $professeurs->isEmpty() || $salles->isEmpty()) {
            $this->error("Données insuffisantes.");
            return;
        }

        // Fonction de nettoyage : enlève la spécialité en début de prénom, puis affiche "Prénom NOM"
        $nettoyer = function ($prof) {
            $prenom = preg_replace('/^(Informatique|Mathématique|Gestion|Anglais)\s+/', '', $prof->prenom);
            return trim($prenom . ' ' . $prof->nom);
        };

        $this->info("=== GÉNÉRATION DU PLANNING ===");
        $scheduler = new SchedulerService();
        $planning = $scheduler->generate($etudiants, $professeurs, $salles, '2026-05-12', '2026-05-14');

        foreach ($planning as $p) {
            $etudiant = $etudiants->firstWhere('id', $p['etudiant_id']);
            $encadrant = $professeurs->firstWhere('id', $p['encadrant_id']);
            $jury1 = $professeurs->firstWhere('id', $p['jury1_id']);
            $jury2 = $professeurs->firstWhere('id', $p['jury2_id']);
            $salle = $salles->firstWhere('id', $p['salle_id']);

            $this->line("- {$p['date']} {$p['heure_debut']}-{$p['heure_fin']} | {$etudiant->prenom} {$etudiant->nom}");
            $this->line("  Encadrant : " . $nettoyer($encadrant));
            $this->line("  Jury : " . $nettoyer($jury1) . " & " . $nettoyer($jury2));
            $this->line("");
        }

        $this->info("Total soutenances : " . count($planning));

        $this->info("=== VALIDATION ===");
        $validator = new ValidationService();

        $anomaliesRepartition = $validator->verifierRepartitionEncadrants($planning, $professeurs, 0, 5);
        if (empty($anomaliesRepartition)) {
            $this->info("✅ Répartition des encadrants OK.");
        } else {
            foreach ($anomaliesRepartition as $a) {
                $this->warn($a);
            }
        }

        $anomaliesConflits = $validator->verifierConflitsPlanning($planning);
        if (empty($anomaliesConflits)) {
            $this->info("✅ Aucun conflit dans le planning.");
        } else {
            foreach ($anomaliesConflits as $a) {
                $this->error($a);
            }
        }

        $this->info("=== STATISTIQUES DASHBOARD ===");
        $stats = $validator->calculerStatistiques($planning, $etudiants, $professeurs);

        $this->info("Étudiants par professeur :");
        foreach ($stats['etudiantsParProf'] as $p) {
            if ($p['count'] > 0) {
                $this->line("  " . $nettoyer($p['prof']) . " : {$p['count']} étudiant(s)");
            }
        }

        $this->info("Soutenances par professeur :");
        foreach ($stats['soutenancesParProf'] as $p) {
            if ($p['count'] > 0) {
                $this->line("  " . $nettoyer($p['prof']) . " : {$p['count']} soutenance(s)");
            }
        }

        $this->info("Soutenances par filière :");
        foreach ($stats['soutenancesParFiliere'] as $filiere => $count) {
            $this->line("  {$filiere} : {$count} soutenance(s)");
        }
    }
}