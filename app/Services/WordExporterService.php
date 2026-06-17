<?php

namespace App\Services;

use App\Models\Planning;
use App\Models\Professeur;
use App\Utils\FiliereColorHelper;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Shared\Html;
use ZipArchive;

class WordExporterService
{
    private string $outputDir;
    private string $pvDir;

    const BLEU_ENSA = '#003580';
    const BLEU_LIGHT = '#EEF2FF';
    const BLANC = '#ffffff';
    const TURQUOISE_ID = '#A3D7FD';
    const ORANGE_TDIA = '#F8D983';
    const GREEN_GI = '#b6f68b';

    public function __construct()
    {
        $this->outputDir = storage_path('app/outputs');
        $this->pvDir     = $this->outputDir . '/PV';

        if (!is_dir($this->outputDir)) mkdir($this->outputDir, 0775, true);
        if (!is_dir($this->pvDir))     mkdir($this->pvDir,     0775, true);
    }

    //  AFFECTATION : HTML -> DOCX 
    private function buildAffectationHtml(): string
    {
        
        $plannings = Planning::with(['etudiant', 'encadrant'])->get();

        $professeurs = $plannings->groupBy('encadrant_id')->map(function ($items) {
            $prof = $items->first()->encadrant;
            if ($prof) {
                $prof->setRelation('etudiants', $items->map->etudiant);
            }
            return $prof;
        })->filter()->sortBy('nom')->values();

        $max = max($professeurs->max(fn($p) => $p->etudiants->count()), 3);

        // Couleurs dynamiques par filière (via FiliereColorHelper)
        $filiereColors = FiliereColorHelper::getColors();

        $colonnes = '<th style="background-color:' . self::BLEU_ENSA . '; color:white; padding:3px; border:1px solid #999; font-size:10px;">Encadrant</th>';
        for ($i = 1; $i <= $max; $i++) {
            $colonnes .= "<th style='background-color:" . self::BLEU_ENSA . "; color:white; padding:3px; border:1px solid #999; font-size:10px;'>Etudiant {$i}</th>";
        }

        // Légende dynamique
        $legendeItems = '';
        foreach ($filiereColors as $key => $color) {
            $filiere = strtoupper($key);
            $legendeItems .= "<span style='background-color:{$color}; color:white; padding:3px 8px; border-radius:3px;'>{$filiere}</span> ";
        }
        $legende = "
        <div style='margin-bottom:8px; font-size:10px;'>
            <strong>Légende :</strong>
            <span style='display:inline-block; margin-left:20px;'>
                {$legendeItems}
            </span>
        </div>";

        $lignes = '';
        foreach ($professeurs as $prof) {
            $etudiants = $prof->etudiants->values();
            $cells = "<td style='padding:2px; border:1px solid #ddd; font-size:9px; white-space:nowrap;'>{$prof->nom} {$prof->prenom}</td>";
            for ($i = 0; $i < $max; $i++) {
                if (isset($etudiants[$i])) {
                    $nom = strtoupper($etudiants[$i]->nom) . ' ' . strtoupper($etudiants[$i]->prenom);
                    $bg = FiliereColorHelper::getColor($etudiants[$i]->filiere, $filiereColors);
                } else {
                    $nom = '-';
                    $bg = self::BLANC;
                }
                $cells .= "<td style='background-color:{$bg}; padding:2px; border:1px solid #ddd; text-align:center; font-size:9px;'>{$nom}</td>";
            }
            $lignes .= "<tr>{$cells}</tr>";
        }


        $html = "
        <h2 style='text-align:center; margin:5px 0px;font-weight:bold;'>
            Ecole Nationale des Sciences Appliquées - Al Hoceima
        </h2>
        <h3 style='text-align:center; margin:3px 0px;'>
            Affectation des encadrants de Projet de Fin d'Etude
        </h3>
        <p style='text-align:center; margin:2px 0px; font-size:10px;'>Année Universitaire 2025/2026</p>
        {$legende}
        <table style='width:100%; border-collapse:collapse; border:1px solid #999;'>
            <thead><tr>{$colonnes}</tr></thead>
            <tbody>{$lignes}</tbody>
        </table>
        ";

        return $html;
    }

    public function generateAffectation(): string
    {
        $html = $this->buildAffectationHtml();
        return $this->htmlToDocx($html, 'affectation.docx', 'landscape');
    }

    public function getAffectationHtml(): string
    {
        return $this->buildAffectationHtml();
    }

    //  PLANNING : HTML -> DOCX
    private function buildPlanningHtml(): string
    {
        $plannings = Planning::with([
            'etudiant', 'encadrant', 'jury2', 'jury3', 'creneau', 'salle'
        ])
        ->join('creneaux', 'plannings.creneau_id', '=', 'creneaux.id')
        ->orderBy('creneaux.date_pfe')
        ->orderBy('creneaux.heure_debut')
        ->select('plannings.*')
        ->get();

        $availableProfColors = [
            '#FFB3BA', '#FFDFBA', '#FFFFBA', '#BAFFC9', '#BAE1FF',
            '#F4C2C2', '#FADADD', '#FDFD96', '#C1E1C1', '#AEC6CF',
            '#FFCBA4', '#FFD1DC', '#C8E6C9', '#D1C4E9', '#B3E5FC',
            '#FFCC80', '#F8BBD0', '#DCEDC8', '#B2DFDB', '#FFE082',
            '#E1BEE7', '#C5CAE9', '#BCAAA4', '#FFAB91', '#FFE0B2',
        ];
        $availableDayColors = ['#FFCDD2', '#C8E6C9', '#BBDEFB', '#FFE082', '#E1BEE7', '#B2DFDB'];

        $profColors = [];
        $dayColors = [];
        $profIdx = 0;
        $dayIdx = 0;

        // Attribuer les couleurs aux profs et dates 
        $getProfColor = function($name) use (&$profColors, &$availableProfColors, &$profIdx) {
            $name = trim($name);
            if (empty($name)) return self::BLANC;
            if (!isset($profColors[$name])) {
                $profColors[$name] = $availableProfColors[$profIdx++ % count($availableProfColors)];
            }
            return $profColors[$name];
        };

        $getDayColor = function($date) use (&$dayColors, &$availableDayColors, &$dayIdx) {
            if (empty($date)) return self::BLANC;
            if (!isset($dayColors[$date])) {
                $dayColors[$date] = $availableDayColors[$dayIdx++ % count($availableDayColors)];
            }
            return $dayColors[$date];
        };


        $lignes = '';
        $id = 1;
        foreach ($plannings as $p) {
            $heure = intval(substr($p->creneau->heure_debut, 0, 2)) . 'h';
            $date  = \Carbon\Carbon::parse($p->creneau->date_pfe)->format('d/m/Y');
            
            $encadrantStr = $p->encadrant->nom . ' ' . $p->encadrant->prenom;
            $jury1Str = $p->jury2->nom . ' ' . $p->jury2->prenom;
            $jury2Str = $p->jury3 ? ($p->jury3->nom . ' ' . $p->jury3->prenom) : '';

            $bgEnc = $getProfColor($encadrantStr);
            $bgJ1 = $getProfColor($jury1Str);
            $bgJ2 = $jury2Str ? $getProfColor($jury2Str) : self::BLANC;
            $bgDate = $getDayColor($date);
            $bgRow = ($id % 2 === 0) ? self::BLEU_LIGHT : self::BLANC;


            $lignes .= "
            <tr>
                <td style='background-color:{$bgRow}; padding:3px; border:1px solid #ccc; text-align:center; font-size:9px;'>{$id}</td>
                <td style='background-color:{$bgEnc}; padding:3px; border:1px solid #ccc; font-size:9px;'>{$encadrantStr}</td>
                <td style='background-color:{$bgJ1}; padding:3px; border:1px solid #ccc; font-size:9px;'>{$jury1Str}</td>
                <td style='background-color:{$bgJ2}; padding:3px; border:1px solid #ccc; font-size:9px;'>{$jury2Str}</td>
                <td style='background-color:{$bgDate}; padding:3px; border:1px solid #ccc; text-align:center; font-size:9px;'>{$date}</td>
                <td style='background-color:{$bgRow}; padding:3px; border:1px solid #ccc; text-align:center; font-size:9px;'>{$heure}</td>
                <td style='background-color:{$bgRow}; padding:3px; border:1px solid #ccc; text-align:center; font-size:9px;'>{$p->salle->nom}</td>
                <td style='background-color:{$bgRow}; padding:3px; border:1px solid #ccc; font-size:9px;'>" . strtoupper($p->etudiant->nom) . "</td>
                <td style='background-color:{$bgRow}; padding:3px; border:1px solid #ccc; font-size:9px;'>" . strtoupper($p->etudiant->prenom) . "</td>
                <td style='background-color:{$bgRow}; padding:3px; border:1px solid #ccc; font-size:9px;'>" . $p->etudiant->filiere . "</td>
            </tr>";
            $id++;
        }


        $html = "
        <h1 style='text-align:center; margin:5px 0px; font-weight:bold;'>
            Ecole Nationale des Sciences Appliquées - Al Hoceima
        </h1>
        <h2 style='text-align:center; margin:5px 0px;'>
            Departement Mathématiques et Informatique<br/>
        </h2>
        <h3 style='text-align:center; margin:3px 0px;'>
            Planning des soutenances des Projets de Fin d'Etude
        </h3>
        <p style='text-align:center; margin:2px 0px; font-size:10px;'>(Première Session) <br/> Année Universitaire 2025/2026 <br/></p>
        <table style='width:100%; border-collapse:collapse; border:1px solid #999;'>
            <thead>
                <tr >
                    <th style='background-color:black; color:white;padding:4px; border:1px solid #999; font-size:10px;'>ID</th>
                    <th style='background-color:black; color:white;padding:4px; border:1px solid #999; font-size:10px;'>Encadrant</th>
                    <th style='background-color:black; color:white;padding:4px; border:1px solid #999; font-size:10px;'>Jury 1</th>
                    <th style='background-color:black; color:white;padding:4px; border:1px solid #999; font-size:10px;'>Jury 2</th>
                    <th style='background-color:black; color:white;padding:4px; border:1px solid #999; font-size:10px;'>Date</th>
                    <th style='background-color:black; color:white;padding:4px; border:1px solid #999; font-size:10px;'>Heure</th>
                    <th style='background-color:black; color:white;padding:4px; border:1px solid #999; font-size:10px;'>Salle</th>
                    <th style='background-color:black; color:white;padding:4px; border:1px solid #999; font-size:10px;'>Nom</th>
                    <th style='background-color:black; color:white;padding:4px; border:1px solid #999; font-size:10px;'>Prénom</th>
                    <th style='background-color:black; color:white;padding:4px; border:1px solid #999; font-size:10px;'>Filière</th>
                </tr>
            </thead>
            <tbody>
                {$lignes}
            </tbody>
        </table>
        ";

        return $html;
    }

    public function generatePlanning(): string {
    $html = $this->buildPlanningHtml();
    return $this->htmlToDocx($html, 'planning.docx', 'landscape');
    }

    public function getPlanningHtml(): string {
        return $this->buildPlanningHtml();
    }

    //  PVs : Template Word -> ZIP
    public function generatePVsZip(): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $plannings = Planning::with([
            'etudiant', 'encadrant', 'jury2', 'jury3', 'creneau', 'salle'
        ])->get();

        // Vider le dossier PV
        $this->viderDossier($this->pvDir);

        foreach ($plannings->groupBy('encadrant_id') as $items) {
            $encadrant = $items->first()->encadrant;
            $encDir    = $this->pvDir . '/' . $this->slug($encadrant->nom . '_' . $encadrant->prenom);
            if (!is_dir($encDir)) mkdir($encDir, 0775, true);

            foreach ($items as $planning) {
                $this->generateSinglePV($planning, $encDir);
            }
        }

        $zipPath = $this->outputDir . '/PVs_Evaluations.zip';
        $this->zipper($this->pvDir, $zipPath);

        return response()->download($zipPath, 'PVs_Evaluations_' . now()->format('Ymd') . '.zip', [
            'Content-Type' => 'application/zip',
        ]);
    }

    //  PV INDIVIDUEL 
    private function generateSinglePV(Planning $planning, string $dir): void
    {
        // Charger le template 
        $templatePath = resource_path('templates/pv_template1.docx');

        if (file_exists($templatePath)) {
            $template = new \PhpOffice\PhpWord\TemplateProcessor($templatePath);

            $etudiant = $planning->etudiant;
            $date     = \Carbon\Carbon::parse($planning->creneau->date_pfe)->format('d/m/Y');
            $heure    = intval(substr($planning->creneau->heure_debut, 0, 2)) . 'h';

            $template->setValue('nom_etudiant',
                strtoupper($etudiant->nom) . ' ' . strtoupper($etudiant->prenom));
            $template->setValue('filiere',    $etudiant->filiere);
            $template->setValue('date',       $date);
            $template->setValue('heure',      $heure);
            $template->setValue('salle',      $planning->salle->nom);
            $template->setValue('encadrant',
                $planning->encadrant->nom . ' ' . $planning->encadrant->prenom);
            $template->setValue('jury2',
                $planning->jury2->nom . ' ' . $planning->jury2->prenom);
            $template->setValue('jury3',
                $planning->jury3->nom . ' ' . $planning->jury3->prenom);

            // Checkboxes 100% dynamiques générées dans une seule variable
            $allFilieres = \App\Models\Etudiant::select('filiere')->distinct()->pluck('filiere')->all();
            $checkboxesString = '';
            foreach ($allFilieres as $fil) {
                $filName = strtoupper(trim($fil));
                $isChecked = (strtoupper(trim($etudiant->filiere)) === $filName) ? '☑' : '☐';
                $checkboxesString .= "{$isChecked} {$filName}    ";
            }
            // On assigne cette longue chaîne à un seul placeholder dans le Word
            $template->setValue('filieres_checkboxes', trim($checkboxesString));

            $filename = $this->slug($etudiant->nom . '_' . $etudiant->prenom) . '.docx';
            $template->saveAs($dir . '/' . $filename);

        }
    }

    //  HELPER : Convertit HTML en DOCX et retourne le chemin
    private function htmlToDocx(
        string  $html,
        ?string $filename,
        string  $orientation = 'portrait',
        ?string $fullPath    = null
    ): string {
        $phpWord = new PhpWord();
        $phpWord->setDefaultFontName('Times New Roman');
        $phpWord->setDefaultFontSize(11);

        $section = $phpWord->addSection([
            'orientation'  => $orientation,
            'paperSize'    => 'A4',
            'marginTop'    => 600,
            'marginBottom' => 400,
            'marginLeft'   => 400,
            'marginRight'  => 400,
        ]);


        Html::addHtml($section, $html, false, false);

        $path = $fullPath ?? ($this->outputDir . '/' . $filename);
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return $path;
    }


    //  HELPERS ZIP / DOSSIER
    private function slug(string $name): string
    {
        $name = iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: $name;
        return preg_replace('/[^A-Za-z0-9_\-]/', '_', $name);
    }

    private function viderDossier(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item) : unlink($item);
        }
    }

    private function zipper(string $source, string $zipPath): void
    {
        if (file_exists($zipPath)) unlink($zipPath);
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($files as $file) {
            if (!$file->isFile()) continue;
            $zip->addFile(
                $file->getRealPath(),
                'PV/' . substr($file->getRealPath(), strlen($source) + 1)
            );
        }
        $zip->close();
    }

}

   