<?php

namespace App\Services;

use PhpOffice\PhpWord\IOFactory;
use Mpdf\Mpdf;

class PdfExporterService
{
    private string $outputDir;
    private WordExporterService $wordExporter;

    public function __construct()
    {
        $this->outputDir = storage_path('app/outputs');
        $this->wordExporter = new WordExporterService();
        
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0775, true);
        }
    }

    //  AFFECTATION : DOCX -> PDF
    public function generateAffectation(): string
    {
        $html = $this->wordExporter->getAffectationHtml(); 
        return $this->convertHtmlToPdf($html, 'affectation.pdf');
    }

    //  PLANNING : DOCX -> PDF
    public function generatePlanning(): string
    {
        $html = $this->wordExporter->getPlanningHtml(); 
        return $this->convertHtmlToPdf($html, 'planning.pdf');
    }

    //  HELPER : Convertir HTML en PDF
    private function convertHtmlToPdf(string $html, string $pdfFilename): string
    {
        try {
            // Créer le PDF directement depuis le HTML
            $mpdf = new Mpdf([
                'orientation' => 'L',
                'margin_left' => 8,
                'margin_right' => 8,
                'margin_top' => 8,
                'margin_bottom' => 8,
            ]);
            
            $mpdf->WriteHTML($html);
            
            // Chemin du fichier PDF
            $pdfPath = $this->outputDir . DIRECTORY_SEPARATOR . $pdfFilename;
            
            // Sauvegarde le PDF
            $mpdf->Output($pdfPath, 'F');
            
            return $pdfPath;
        } catch (\Exception $e) {
            throw new \Exception("Erreur lors de la génération PDF: " . $e->getMessage());
        }
    }
}
