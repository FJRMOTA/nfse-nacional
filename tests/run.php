<?php

declare(strict_types=1);

use Hadder\NfseNacional\Danfse\DanfsePdf;
use Hadder\NfseNacional\Danfse\DanfseTemplate;
use Hadder\NfseNacional\Danfse\DanfseXmlData;

$projectAutoload = getenv('PROJECT_AUTOLOAD');
if (is_string($projectAutoload) && $projectAutoload !== '') {
    require_once $projectAutoload;
}

$tcpdfPath = getenv('TCPDF_PATH');
if (is_string($tcpdfPath) && $tcpdfPath !== '') {
    require_once $tcpdfPath;
}
require_once __DIR__ . '/../src/Danfse/DanfseXmlData.php';
require_once __DIR__ . '/../src/Danfse/MunicipalityResolver.php';
require_once __DIR__ . '/../src/Danfse/DanfseTemplate.php';
require_once __DIR__ . '/../src/Danfse/DanfsePdf.php';

$passed = 0;
$failed = 0;
$test = static function (string $name, callable $callback) use (&$passed, &$failed): void {
    try { $callback(); echo "PASS {$name}\n"; $passed++; }
    catch (Throwable $error) { echo "FAIL {$name}: {$error->getMessage()}\n"; $failed++; }
};
$assert = static function (bool $condition, string $message = 'assertion failed'): void {
    if (!$condition) { throw new RuntimeException($message); }
};
$fixture = file_get_contents(__DIR__ . '/fixtures/nfse-production.xml');
if ($fixture === false) { throw new RuntimeException('Fixture ausente.'); }

if (class_exists('NFePHP\\Common\\DOMImproved')) {
    require __DIR__ . '/TransportTest.php';
    require __DIR__ . '/DpsIbsCbsTest.php';
    if (is_file(__DIR__ . '/CnpjSchemaTest.php')) {
        require __DIR__ . '/CnpjSchemaTest.php';
    }
} else {
    echo "SKIP DPS: nfephp-org/sped-common não disponível no ambiente de teste\n";
}

if (!extension_loaded('gd') && !extension_loaded('imagick')) {
    $test('DPS funciona sem extensão de imagem e DANFSe falha explicitamente', function () use ($fixture, $assert): void {
        $assert(class_exists('Hadder\\NfseNacional\\Dps'));
        try { (new DanfsePdf())->renderFromXml($fixture); }
        catch (RuntimeException $error) {
            $assert(str_contains($error->getMessage(), 'ext-gd ou ext-imagick'));
            return;
        }
        throw new RuntimeException('DANFSe deveria informar a extensão de imagem ausente.');
    });
}

$test('produção e extração oficial', function () use ($fixture, $assert): void {
    $data = DanfseXmlData::fromXml($fixture);
    $assert($data['key'] === '35503081234567800019900000000000000012345678901', 'chave');
    $assert($data['environment_label'] === 'Produção', 'ambiente');
    $assert($data['generator'] === 'Sistema Nacional da NFS-e', 'gerador');
    $assert($data['issuer'] === 'Prestador', 'emitente');
    $assert($data['status'] === 'NFS-e Gerada', 'situação');
    $assert($data['service']['national_code'] === '01.07.01', 'código');
    $assert($data['municipal_tax']['suspension'] === 'Exigibilidade suspensa por decisão judicial', 'suspensão');
    $assert($data['federal_tax']['pis'] === 'R$ 0,00', 'PIS retido deve zerar débito próprio');
    $assert($data['federal_tax']['cofins'] === 'R$ 0,00', 'COFINS retido deve zerar débito próprio');
    $assert($data['federal_tax']['social_contributions'] === 'R$ 102,50', 'retenções sociais');
    $assert($data['municipal_tax']['rate'] === '5,00%', 'percentual não deve ser multiplicado');
    $assert($data['consultation_url'] === 'https://www.nfse.gov.br/ConsultaPublica/?tpc=1&chave=' . $data['key'], 'URL do QR Code');
    $assert($data['ibs_cbs']['classification'] === '000 / 000001', 'classificação IBS/CBS');
    $assert($data['ibs_cbs']['base'] === 'R$ 850,00', 'base IBS/CBS');
    $assert($data['ibs_cbs']['state_amount'] === 'R$ 0,77', 'IBS estadual');
    $assert($data['ibs_cbs']['municipal_amount'] === 'R$ 0,85', 'IBS municipal');
    $assert($data['ibs_cbs']['ibs_amount'] === 'R$ 1,62', 'IBS total');
    $assert($data['ibs_cbs']['cbs_amount'] === 'R$ 8,31', 'CBS total');
    $assert($data['ibs_cbs']['total'] === 'R$ 9,93', 'total IBS/CBS');
    $assert($data['ibs_cbs']['net_with_taxes'] === 'R$ 898,31', 'vTotNF');
    $assert(str_contains(implode(' | ', $data['additional_information']), 'Cod. Obra: OBRA-1'), 'complementares');
});

$test('homologação', function () use ($fixture, $assert): void {
    $xml = str_replace('<tpAmb>1</tpAmb>', '<tpAmb>2</tpAmb>', $fixture);
    $data = DanfseXmlData::fromXml($xml);
    $assert($data['homologation'] && $data['environment_label'] === 'Homologação');
    $assert(str_contains(DanfseTemplate::html($data), 'NFS-e SEM VALIDADE JURÍDICA'));
});

$test('XML inválido', function () use ($assert): void {
    try { DanfseXmlData::fromXml('<NFSe>'); }
    catch (InvalidArgumentException $error) { $assert(str_contains($error->getMessage(), 'Não foi possível')); return; }
    throw new RuntimeException('XML inválido aceito');
});

$test('tomador e intermediário ausentes', function () use ($fixture, $assert): void {
    $xml = preg_replace('~<toma>.*?</toma>~s', '', $fixture) ?? '';
    $data = DanfseXmlData::fromXml($xml);
    $html = DanfseTemplate::html($data);
    $assert(!$data['customer_identified'] && !$data['intermediary_identified']);
    $assert(str_contains($html, 'TOMADOR/ADQUIRENTE DA OPERAÇÃO NÃO IDENTIFICADO'));
    $assert(str_contains($html, 'INTERMEDIÁRIO DA OPERAÇÃO NÃO IDENTIFICADO'));
});

$test('substituída', function () use ($fixture, $assert): void {
    $xml = str_replace('<prest>', '<subst><chSubstda>35503081234567800019900000000000000000000000001</chSubstda></subst><prest>', $fixture);
    $data = DanfseXmlData::fromXml(str_replace('<cStat>100</cStat>', '<cStat>101</cStat>', $xml));
    $assert($data['watermark'] === 'SUBSTITUÍDA');
    $assert(str_contains(implode(' | ', $data['additional_information']), 'NFS-e Subst.:'));
});

$test('cancelada somente com evento aceito', function () use ($fixture, $assert): void {
    $xml = str_replace('</NFSe>', '<evento><infEvento><cStat>135</cStat><e101101/></infEvento></evento></NFSe>', $fixture);
    $assert(DanfseXmlData::fromXml($xml)['watermark'] === 'CANCELADA');
    $pending = str_replace('<cStat>135</cStat>', '<cStat>128</cStat>', $xml);
    $assert(DanfseXmlData::fromXml($pending)['watermark'] === null, 'evento não aceito não pode cancelar');
});

$test('namespace alternativo', function () use ($fixture, $assert): void {
    $xml = str_replace('xmlns="http://www.sped.fazenda.gov.br/nfse"', 'xmlns="urn:namespace:alternativo"', $fixture);
    $assert(DanfseXmlData::fromXml($xml)['number'] === '123');
});

$test('campos opcionais ausentes', function () use ($fixture, $assert): void {
    $xml = preg_replace('~<exigSusp>.*?</exigSusp>~s', '', $fixture) ?? '';
    $data = DanfseXmlData::fromXml($xml);
    $assert($data['municipal_tax']['suspension'] === '');
    $assert(!str_contains(DanfseTemplate::html($data), 'Suspensão da Exigibilidade'));
});

$test('conteúdo extenso é limitado sem perder totais', function () use ($fixture, $assert): void {
    $long = str_repeat('Descrição extensa ', 200);
    $xml = str_replace('Informação complementar da operação.', $long, $fixture);
    $data = DanfseXmlData::fromXml($xml);
    $html = DanfseTemplate::html($data);
    $assert(str_contains($html, '...'));
    $assert(str_contains($html, 'Totais Aproximados dos Tributos'));
});

if (class_exists('TCPDF') && getenv('RUN_PDF') === '1') {
    $test('PDF em memória, QR Code e página única', function () use ($fixture, $assert): void {
        $pdf = (new DanfsePdf())->renderFromXml($fixture);
        $assert(str_starts_with($pdf, '%PDF-'));
        $assert(str_contains($pdf, '/Subtype /Image'), 'QR Code/imagem ausente');
        $homologation = (new DanfsePdf())->renderFromXml(str_replace('<tpAmb>1</tpAmb>', '<tpAmb>2</tpAmb>', $fixture));
        $assert(str_starts_with($homologation, '%PDF-'));
        $substituted = str_replace('<prest>', '<subst><chSubstda>35503081234567800019900000000000000000000000001</chSubstda></subst><prest>', $fixture);
        $assert(str_starts_with((new DanfsePdf())->renderFromXml($substituted), '%PDF-'));
        $long = str_replace('Informação complementar da operação.', str_repeat('Descrição extensa ', 200), $fixture);
        $assert(str_starts_with((new DanfsePdf())->renderFromXml($long), '%PDF-'));
    });
    $test('gravação de arquivo e falha explícita', function () use ($fixture, $assert): void {
        $path = tempnam(sys_get_temp_dir(), 'danfse-');
        if ($path === false) { throw new RuntimeException('tempnam'); }
        (new DanfsePdf())->saveFromXml($fixture, $path);
        $assert(str_starts_with((string) file_get_contents($path), '%PDF-'));
        unlink($path);
        try { (new DanfsePdf())->saveFromXml($fixture, '/diretorio/inexistente/danfse.pdf'); }
        catch (RuntimeException) { return; }
        throw new RuntimeException('falha de gravação não reportada');
    });
    $outputDirectory = getenv('DANFSE_OUTPUT_DIR');
    if (is_string($outputDirectory) && $outputDirectory !== '') {
        $renderer = new DanfsePdf();
        $renderer->saveFromXml($fixture, $outputDirectory . '/production.pdf');
        $renderer->saveFromXml(str_replace('<tpAmb>1</tpAmb>', '<tpAmb>2</tpAmb>', $fixture), $outputDirectory . '/homologation.pdf');
        $renderer->saveFromXml(str_replace('</NFSe>', '<evento><infEvento><cStat>135</cStat><e101101/></infEvento></evento></NFSe>', $fixture), $outputDirectory . '/cancelled.pdf');
    }
} else {
    echo "SKIP PDF: TCPDF não disponível no ambiente de teste\n";
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
