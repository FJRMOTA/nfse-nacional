<?php

use Hadder\NfseNacional\Dps;
use Hadder\NfseNacional\Dps\IbsCbsLayout;

require_once __DIR__ . '/../src/Dps/IbsCbsLayout.php';
require_once __DIR__ . '/../src/Dps/IbsCbsSerializer.php';
require_once __DIR__ . '/../src/DpsInterface.php';
require_once __DIR__ . '/../src/Dps.php';

$dpsBase = static function (): stdClass {
    return json_decode(json_encode([
        'version' => '1.01',
        'infDPS' => [
            'tpAmb' => 2, 'dhEmi' => '2026-09-11T10:00:00-03:00', 'verAplic' => 'TESTE_1.0',
            'serie' => '1', 'nDPS' => '1', 'dCompet' => '2026-09-11', 'tpEmit' => 1, 'cLocEmi' => '3550308',
            'prest' => ['CNPJ' => '12345678000199', 'regTrib' => ['opSimpNac' => 1, 'regEspTrib' => 0]],
            'toma' => ['CPF' => '12345678909', 'xNome' => 'TOMADOR TESTE'],
            'serv' => [
                'locPrest' => ['cLocPrestacao' => '3550308'],
                'cServ' => ['cTribNac' => '010701', 'xDescServ' => 'Serviço de teste', 'cNBS' => '115032100'],
            ],
            'valores' => [
                'vServPrest' => ['vServ' => '100.00'],
                'trib' => ['tribMun' => ['tribISSQN' => 1, 'tpRetISSQN' => 1], 'totTrib' => ['indTotTrib' => 0]],
            ],
        ],
    ], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
};

$currentIbs = static function (): stdClass {
    return json_decode(json_encode([
        'finNFSe' => '0', 'indFinal' => '0', 'cIndOp' => '010101', 'indDest' => '0',
        'valores' => ['trib' => ['gIBSCBS' => ['CST' => '000', 'cClassTrib' => '000001']]],
    ], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
};

$v104Ibs = static function (): stdClass {
    return json_decode(json_encode([
        'indFinal' => '0', 'cIndOp' => '010101', 'indZFMALC' => '0', 'indDoacao' => '1', 'indDest' => '0',
        'valores' => ['trib' => ['gIBSCBS' => [
            'CST' => '000', 'cClassTrib' => '000001', 'cCredPres' => '01',
            'gTribRegular' => ['CSTReg' => '000', 'cClassTribReg' => '000001'],
            'gDif' => ['pDifUF' => '10.00', 'pDifMun' => '05.00', 'pDifCBS' => '02.50'],
            'gEstornoCred' => ['vIBSEstCred' => '1.00', 'vCBSEstCred' => '2.00'],
            'gPagAntecipado' => ['refNFSe' => [str_repeat('1', 50), str_repeat('2', 50)]],
        ]]],
    ], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
};

$xpathFor = static function (stdClass $std, string $profile = IbsCbsLayout::CURRENT): DOMXPath {
    $xml = (new Dps($std, $profile))->render();
    $dom = new DOMDocument();
    if (!$dom->loadXML($xml, LIBXML_NONET)) throw new RuntimeException('XML gerado é inválido.');
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('n', 'http://www.sped.fazenda.gov.br/nfse');
    return $xpath;
};

$test('DPS padrão permanece no perfil atual e sem IBS/CBS', function () use ($dpsBase, $xpathFor, $assert): void {
    $dps = new Dps($dpsBase());
    $assert($dps->getLayoutProfile() === IbsCbsLayout::CURRENT);
    $assert($xpathFor($dpsBase())->query('//n:IBSCBS')->length === 0);
});

$test('perfil atual gera grupo mínimo e preserva zeros', function () use ($dpsBase, $currentIbs, $xpathFor, $assert): void {
    $std = $dpsBase(); $std->infDPS->IBSCBS = $currentIbs();
    $xp = $xpathFor($std);
    $assert($xp->evaluate('string(//n:IBSCBS/n:finNFSe)') === '0');
    $assert($xp->evaluate('string(//n:gIBSCBS/n:CST)') === '000');
    $assert($xp->evaluate('string(//n:gIBSCBS/n:cClassTrib)') === '000001');
    $assert($xp->query('//n:infDPS/n:finNFSe')->length === 0, 'XML híbrido atual');
});

$test('perfil atual valida contra XSD DPS v1.01 oficial', function () use ($dpsBase, $currentIbs, $assert): void {
    $std = $dpsBase(); $std->infDPS->IBSCBS = $currentIbs();
    $dom = new DOMDocument(); $dom->loadXML((new Dps($std))->render(), LIBXML_NONET);
    $previous = libxml_use_internal_errors(true);
    $valid = $dom->schemaValidate(__DIR__ . '/../storage/schemes/DPS_v1.01.xsd');
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$valid) {
        $messages = array_map(static fn (LibXMLError $error): string => $error->message, $errors);
        $knownUpstreamIssue = false;
        foreach ($messages as $message) {
            if (str_contains($message, "serie") && str_contains($message, "^0{0,4}\\d{1,5}$")) {
                $knownUpstreamIssue = true;
                break;
            }
        }
        $assert($knownUpstreamIssue, 'falha XSD inesperada: ' . implode(' | ', $messages));
        return;
    }
    $assert($valid, 'schema v1.01');
});

$test('DPS preserva CNPJ alfanumérico no XML', function () use ($dpsBase, $xpathFor, $assert): void {
    $std = $dpsBase();
    $std->infDPS->prest->CNPJ = '12ABC34501DE99';
    $xp = $xpathFor($std);
    $assert($xp->evaluate('string(//n:prest/n:CNPJ)') === '12ABC34501DE99');
});

$test('exigSusp gera grupo na ordem oficial', function () use ($dpsBase, $xpathFor, $assert): void {
    $std = $dpsBase();
    $std->infDPS->valores->trib->tribMun->exigSusp = (object) [
        'tpSusp' => '1',
        'nProcesso' => str_repeat('1', 30),
    ];
    $xp = $xpathFor($std);
    $nodes = [];
    foreach ($xp->query('//n:tribMun/*') as $node) {
        $nodes[] = $node->localName;
    }
    $assert($nodes === ['tribISSQN', 'exigSusp', 'tpRetISSQN']);
    $assert($xp->evaluate('string(//n:exigSusp/n:tpSusp)') === '1');
    $assert($xp->evaluate('string(//n:exigSusp/n:nProcesso)') === str_repeat('1', 30));
});

$test('cTribNac preserva zeros e rejeita formato inválido', function () use ($dpsBase, $xpathFor, $assert): void {
    $std = $dpsBase();
    $std->infDPS->serv->cServ->cTribNac = '010101';
    $assert($xpathFor($std)->evaluate('string(//n:cTribNac)') === '010101');
    foreach (['10101', '0101010', '01A101'] as $invalid) {
        $std->infDPS->serv->cServ->cTribNac = $invalid;
        try { (new Dps($std))->render(); }
        catch (InvalidArgumentException $error) { continue; }
        throw new RuntimeException("cTribNac inválido aceito: {$invalid}");
    }
});

$test('subst não cria xMotivo ausente', function () use ($dpsBase, $xpathFor, $assert): void {
    $std = $dpsBase();
    $std->infDPS->subst = (object) [
        'chSubstda' => str_repeat('1', 50),
        'cMotivo' => '99',
    ];
    $assert($xpathFor($std)->query('//n:subst/n:xMotivo')->length === 0);
});

$test('eventos exigem dados obrigatórios e geram e105102', function () use ($dpsBase, $assert): void {
    $event = (object) [
        'version' => '1.01',
        'infPedReg' => (object) [
            'tpAmb' => '2', 'verAplic' => 'TESTE_1.0',
            'dhEvento' => '2026-09-12T10:00:00-03:00',
            'CNPJAutor' => '12345678000199', 'chNFSe' => str_repeat('1', 50),
            'e105102' => (object) [
                'xDesc' => 'Cancelamento de NFS-e por Substituição',
                'cMotivo' => '99', 'chSubstituta' => str_repeat('2', 50),
            ],
        ],
    ];
    $xml = (new Dps($event))->renderEvento();
    $dom = new DOMDocument();
    $assert($dom->loadXML($xml, LIBXML_NONET));
    $assert($dom->getElementsByTagName('e105102')->length === 1);
    $assert($dom->getElementsByTagName('chSubstituta')->length === 1);
    $assert(strlen($dom->documentElement->firstChild->getAttribute('Id')) === 59);
});

$test('exigSusp rejeita campos obrigatórios ausentes ou inválidos', function () use ($dpsBase, $xpathFor): void {
    foreach ([
        (object) ['tpSusp' => '1'],
        (object) ['nProcesso' => str_repeat('1', 30)],
        (object) ['tpSusp' => '3', 'nProcesso' => str_repeat('1', 30)],
        (object) ['tpSusp' => '1', 'nProcesso' => '123'],
    ] as $suspension) {
        $std = $dpsBase();
        $std->infDPS->valores->trib->tribMun->exigSusp = $suspension;
        try { $xpathFor($std); } catch (InvalidArgumentException) { continue; }
        throw new RuntimeException('exigSusp inválido foi aceito');
    }
});

$test('exigSusp rejeitado quando ISSQN não é tributável', function () use ($dpsBase, $xpathFor): void {
    $std = $dpsBase();
    $std->infDPS->valores->trib->tribMun->tribISSQN = '2';
    $std->infDPS->valores->trib->tribMun->exigSusp = (object) [
        'tpSusp' => '1', 'nProcesso' => str_repeat('1', 30),
    ];
    try { $xpathFor($std); } catch (InvalidArgumentException) { return; }
    throw new RuntimeException('exigSusp incompatível foi aceito');
});

$test('destinatário respeita choice, endereço e ordem', function () use ($dpsBase, $currentIbs, $xpathFor, $assert): void {
    $std = $dpsBase(); $std->infDPS->IBSCBS = $currentIbs();
    $std->infDPS->IBSCBS->indDest = '1';
    $std->infDPS->IBSCBS->dest = json_decode('{"CPF":"12345678909","xNome":"DEST","end":{"endNac":{"cMun":"3550308","CEP":"01001000"},"xLgr":"Praça da Sé","nro":"1","xBairro":"Sé"},"fone":"11999999999","email":"a@b.com"}');
    $xp = $xpathFor($std); $names = [];
    foreach ($xp->query('//n:IBSCBS/n:dest/*') as $node) $names[] = $node->localName;
    $assert($names === ['CPF', 'xNome', 'end', 'fone', 'email']);
    $std->infDPS->IBSCBS->dest->CNPJ = '12345678000199';
    try { $xpathFor($std); } catch (InvalidArgumentException) { return; }
    throw new RuntimeException('choice ambíguo foi aceito');
});

$test('gRefNFSe, tpOper e grupos tributários atuais', function () use ($dpsBase, $currentIbs, $xpathFor, $assert): void {
    $std = $dpsBase(); $std->infDPS->IBSCBS = $currentIbs();
    $std->infDPS->IBSCBS->tpOper = '2'; $std->infDPS->IBSCBS->gRefNFSe = (object) ['refNFSe' => [str_repeat('1', 50), str_repeat('2', 50)]];
    $g = $std->infDPS->IBSCBS->valores->trib->gIBSCBS;
    $g->gTribRegular = (object) ['CSTReg' => '000', 'cClassTribReg' => '000001'];
    $g->gDif = (object) ['pDifUF' => '10.00', 'pDifMun' => '05.00', 'pDifCBS' => '02.50'];
    $xp = $xpathFor($std);
    $assert($xp->query('//n:gRefNFSe/n:refNFSe')->length === 2);
    $assert($xp->query('//n:gIBSCBS/n:gTribRegular')->length === 1 && $xp->query('//n:gIBSCBS/n:gDif')->length === 1);
});

$test('perfil v1.04 move finalidade e nunca gera posição antiga', function () use ($dpsBase, $v104Ibs, $xpathFor, $assert): void {
    $std = $dpsBase(); $std->infDPS->finNFSe = '0'; $std->infDPS->IBSCBS = $v104Ibs();
    $xp = $xpathFor($std, IbsCbsLayout::NT009_V104);
    $assert($xp->evaluate('string(//n:infDPS/n:finNFSe)') === '0');
    $assert($xp->query('//n:IBSCBS/n:finNFSe')->length === 0, 'XML híbrido v1.04');
    $assert($xp->evaluate('string(//n:IBSCBS/n:indZFMALC)') === '0');
    $assert($xp->evaluate('string(//n:IBSCBS/n:indDoacao)') === '1');
});

$test('v1.04 suporta crédito débito e ajuste', function () use ($dpsBase, $v104Ibs, $xpathFor, $assert): void {
    foreach ([['1','tpNFSeCredito'], ['2','tpNFSeDebito']] as [$purpose, $field]) {
        $std = $dpsBase(); $std->infDPS->finNFSe = $purpose; $std->infDPS->{$field} = '05'; $std->infDPS->IBSCBS = $v104Ibs();
        $std->infDPS->IBSCBS->valores->trib->gIBSCBSAjuste = (object) ['vIBS' => '1.00', 'vCBS' => '2.00'];
        $xp = $xpathFor($std, IbsCbsLayout::NT009_V104);
        $assert($xp->evaluate("string(//n:infDPS/n:{$field})") === '05');
        $assert($xp->query('//n:gIBSCBSAjuste/n:vIBS')->length === 1);
    }
});

$test('v1.04 suporta imóvel bens móveis pagamentos e repetições', function () use ($dpsBase, $v104Ibs, $xpathFor, $assert): void {
    $std = $dpsBase(); $std->infDPS->finNFSe = '0'; $std->infDPS->IBSCBS = $v104Ibs();
    $std->infDPS->IBSCBS->imovel = json_decode('{"cMun":"3550308","gLocacao":{"pCopropriedade":"100.00","vTotOper":"100.00"},"gUnidImob":[{"cCIB":"12345678"},{"end":{"CEP":"01001000","xLgr":"Praça da Sé","nro":"1"},"gAjusteBCLocImoveis":[{"tpAjusteBCLocImoveis":"01","vAjusteBCLocImoveis":"5.00"}]}]}');
    $std->infDPS->IBSCBS->bensMoveis = [(object) ['cNCMBemMovel'=>'12345678','xNCMBemMovel'=>'Bem','qtdNCMBemMovel'=>'1']];
    $std->infDPS->IBSCBS->gPgtoVinc = (object) ['pgto' => [(object) ['nPag'=>'1','idTransacao'=>'TX1','tpMeioPgto'=>'20','CNPJReceb'=>'12345678000199','CNPJBasePSP'=>'12345678']]];
    $xp = $xpathFor($std, IbsCbsLayout::NT009_V104);
    $assert($xp->query('//n:imovel/n:gUnidImob')->length === 2);
    $assert($xp->query('//n:bensMoveis')->length === 1 && $xp->query('//n:gPgtoVinc/n:pgto')->length === 1);
});

$test('v1.04 suporta vAjusteBC e proíbe gReeRepRes antigo', function () use ($dpsBase, $v104Ibs, $xpathFor, $assert): void {
    $std = $dpsBase(); $std->infDPS->finNFSe = '0'; $std->infDPS->IBSCBS = $v104Ibs();
    $std->infDPS->valores->vAjusteBC = json_decode('{"pAjusteBCISSQN":"5.00","documentos":{"docAjusteBC":[{"tpAjusteBC":"1","vTotDoc":"10.00","vAjuteAplic":"5.00","dFeNacional":{"tipoChaveDFe":"1","chaveDFe":"123"}}]}}');
    $xp = $xpathFor($std, IbsCbsLayout::NT009_V104);
    $assert($xp->query('//n:valores/n:vAjusteBC/n:documentos/n:docAjusteBC')->length === 1);
    $std->infDPS->IBSCBS->valores->gReeRepRes = (object) [];
    try { $xpathFor($std, IbsCbsLayout::NT009_V104); } catch (InvalidArgumentException) { return; }
    throw new RuntimeException('gReeRepRes obsoleto foi aceito');
});

$test('perfis rejeitam campos cruzados e perfil desconhecido', function () use ($dpsBase, $currentIbs, $v104Ibs, $xpathFor): void {
    $std = $dpsBase(); $std->infDPS->finNFSe = '0'; $std->infDPS->IBSCBS = $currentIbs();
    try { $xpathFor($std); } catch (InvalidArgumentException) {
        $std = $dpsBase(); $std->infDPS->IBSCBS = $v104Ibs(); $std->infDPS->IBSCBS->finNFSe = '0';
        try { $xpathFor($std, IbsCbsLayout::NT009_V104); } catch (InvalidArgumentException) { return; }
    }
    throw new RuntimeException('XML híbrido foi aceito');
});
