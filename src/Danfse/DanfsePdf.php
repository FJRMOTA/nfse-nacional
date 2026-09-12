<?php

namespace Hadder\NfseNacional\Danfse;

use InvalidArgumentException;
use RuntimeException;
use TCPDF;

final class DanfsePdf
{
    public function renderFromXml(string $xml): string
    {
        return $this->render(DanfseXmlData::fromXml($xml));
    }

    public function renderFromFile(string $path): string
    {
        return $this->renderFromXml($this->readFile($path));
    }

    /** @param array<string, mixed> $data */
    public function render(array $data): string
    {
        if (!extension_loaded('gd') && !extension_loaded('imagick')) {
            throw new RuntimeException('A geração do DANFSe requer ext-gd ou ext-imagick para processar o logo PNG.');
        }
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Hadder NFS-e Nacional');
        $pdf->SetAuthor('');
        $pdf->SetTitle('DANFSe ' . ($data['key'] ?? ''));
        $pdf->SetSubject('Documento Auxiliar da NFS-e');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(1.5, 1.5, 1.5);
        $pdf->SetAutoPageBreak(false, 1.5);
        $pdf->AddPage();
        $pdf->SetFont('dejavusans', '', 7);
        $pdf->writeHTML(DanfseTemplate::html($data), true, false, true, false, '');

        if ($pdf->getNumPages() !== 1 || $pdf->GetY() > 295.5) {
            throw new RuntimeException('O conteúdo do DANFSe excedeu a página única exigida pela NT-008.');
        }

        $pdf->write2DBarcode(
            (string) $data['consultation_url'],
            'QRCODE,H',
            174.8,
            16.7,
            15.2,
            15.2,
            ['border' => 0, 'padding' => 0, 'fgcolor' => [0, 0, 0], 'bgcolor' => false],
            'N'
        );
        $pdf->SetXY(158, 33.6);
        $pdf->SetFont('dejavusans', '', 6);
        $pdf->MultiCell(47.2, 7, 'A autenticidade desta NFS-e pode ser verificada pela leitura deste código QR ou pela consulta da chave de acesso no portal nacional da NFS-e.', 0, 'C', false, 0);

        if (!empty($data['watermark'])) {
            $pdf->setPage(1);
            $pdf->SetTextColor(166, 166, 166);
            $pdf->SetFont('helvetica', '', 50);
            $pdf->StartTransform();
            $pdf->Rotate(35, 105, 148);
            $pdf->Text(38, 148, (string) $data['watermark']);
            $pdf->StopTransform();
            $pdf->SetTextColor(0, 0, 0);
        }

        $result = $pdf->Output('', 'S');
        if (!is_string($result) || !str_starts_with($result, '%PDF-')) {
            throw new RuntimeException('Falha ao gerar o PDF do DANFSe.');
        }
        return $result;
    }

    public function saveFromXml(string $xml, string $path): void
    {
        $this->writeFile($path, $this->renderFromXml($xml));
    }

    public function saveFromFile(string $xmlPath, string $pdfPath): void
    {
        $this->writeFile($pdfPath, $this->renderFromFile($xmlPath));
    }

    private function readFile(string $path): string
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new RuntimeException($message, $severity);
        });
        try {
            $contents = file_get_contents($path);
        } catch (RuntimeException $exception) {
            throw new InvalidArgumentException('Não foi possível ler o arquivo XML: ' . $path, 0, $exception);
        } finally {
            restore_error_handler();
        }
        if ($contents === false) {
            throw new InvalidArgumentException('Não foi possível ler o arquivo XML: ' . $path);
        }
        return $contents;
    }

    private function writeFile(string $path, string $contents): void
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new RuntimeException($message, $severity);
        });
        try {
            $bytes = file_put_contents($path, $contents, LOCK_EX);
        } catch (RuntimeException $exception) {
            throw new RuntimeException('Não foi possível salvar o DANFSe em: ' . $path, 0, $exception);
        } finally {
            restore_error_handler();
        }
        if ($bytes === false || $bytes !== strlen($contents)) {
            throw new RuntimeException('Não foi possível salvar integralmente o DANFSe em: ' . $path);
        }
    }
}
