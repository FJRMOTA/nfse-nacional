<?php

$validateCnpjType = static function (string $typesPath, string $value): bool {
    $schemaPath = tempnam(sys_get_temp_dir(), 'nfse-cnpj-');
    if ($schemaPath === false) {
        throw new RuntimeException('Não foi possível criar schema temporário.');
    }
    $schema = '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" targetNamespace="http://www.sped.fazenda.gov.br/nfse" xmlns="http://www.sped.fazenda.gov.br/nfse"><xs:include schemaLocation="' . htmlspecialchars($typesPath, ENT_XML1) . '"/><xs:element name="CNPJ" type="TSCNPJ"/></xs:schema>';
    file_put_contents($schemaPath, $schema);
    $xml = new DOMDocument();
    $xml->loadXML('<CNPJ xmlns="http://www.sped.fazenda.gov.br/nfse">' . $value . '</CNPJ>', LIBXML_NONET);
    libxml_use_internal_errors(true);
    $result = $xml->schemaValidate($schemaPath);
    unlink($schemaPath);
    return $result;
};

$test('CNPJ numérico valida no schema de Produção', function () use ($validateCnpjType, $assert): void {
    $assert($validateCnpjType(__DIR__ . '/../storage/schemes/tiposSimples_v1.01.xsd', '12345678000199'));
});

$test('CNPJ alfanumérico valida no schema de Produção Restrita 27/07/2026', function () use ($validateCnpjType, $assert): void {
    $assert($validateCnpjType(__DIR__ . '/../storage/schemes/production-restrita-20260727/tiposSimples_v1.01.xsd', '12ABC34501DE99'));
});
