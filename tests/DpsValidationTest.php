<?php

use Hadder\NfseNacional\Dps;
use Hadder\NfseNacional\Dps\IbsCbsLayout;

$validateStd = static function (stdClass $std): array {
    $dps = new Dps($std);
    $xml = $dps->render();
    return ['errors' => $dps->getErrors(), 'schema' => $dps->validate($xml)];
};
$hasError = static function (array $errors, string $needle): bool {
    return array_filter($errors, static fn (string $error): bool => str_contains($error, $needle)) !== [];
};

$test('DPS válida não gera erros de preenchimento nem de XSD', function () use ($dpsBase, $validateStd, $assert): void {
    $result = $validateStd($dpsBase());
    $assert($result['errors'] === [], 'erros de preenchimento: ' . implode(' | ', $result['errors']));
    $assert($result['schema'] === [], 'erros XSD: ' . implode(' | ', $result['schema']));
});

$test('getErrors expõe campo obrigatório vazio acumulado pelo DOM', function () use ($dpsBase, $validateStd, $assert, $hasError): void {
    $std = $dpsBase(); $std->infDPS->toma->xNome = '';
    $result = $validateStd($std);
    $assert($hasError($result['errors'], 'Preenchimento Obrigatório! [xNome]'), implode(' | ', $result['errors']));
});

$test('série natural é tolerada somente pelo defeito conhecido de TSSerieDPS', function () use ($dpsBase, $validateStd, $assert, $hasError): void {
    foreach (['1', '7', '49999', '00001'] as $serie) {
        $std = $dpsBase(); $std->infDPS->serie = $serie;
        $assert(!$hasError($validateStd($std)['schema'], '}serie'), "série {$serie} deveria ser aceita");
    }
});

$test('série inválida não é mascarada pelo tratamento do defeito conhecido', function () use ($dpsBase, $validateStd, $assert, $hasError): void {
    foreach (['AB', '123456'] as $serie) {
        $std = $dpsBase(); $std->infDPS->serie = $serie;
        $assert($hasError($validateStd($std)['schema'], '}serie'), "série {$serie} deveria ser rejeitada");
    }
});

$test('outros erros XSD permanecem quando a série é tolerada', function () use ($dpsBase, $validateStd, $assert, $hasError): void {
    $std = $dpsBase(); $std->infDPS->valores->vServPrest->vServ = '100.5';
    $schema = $validateStd($std)['schema'];
    $assert(count($schema) === 1 && $hasError($schema, '}vServ'), implode(' | ', $schema));
});

$test('XSD detecta ausência de tpRetISSQN', function () use ($dpsBase, $validateStd, $assert, $hasError): void {
    $std = $dpsBase(); unset($std->infDPS->valores->trib->tribMun->tpRetISSQN);
    $assert($hasError($validateStd($std)['schema'], 'tpRetISSQN'));
});

$test('XSD detecta enum inválido em tpRetISSQN, opSimpNac e regEspTrib', function () use ($dpsBase, $validateStd, $assert, $hasError): void {
    $cases = [
        ['tpRetISSQN', static function (stdClass $s): void { $s->infDPS->valores->trib->tribMun->tpRetISSQN = 9; }],
        ['opSimpNac', static function (stdClass $s): void { $s->infDPS->prest->regTrib->opSimpNac = 9; }],
        ['regEspTrib', static function (stdClass $s): void { $s->infDPS->prest->regTrib->regEspTrib = 7; }],
    ];
    foreach ($cases as [$field, $mutate]) {
        $std = $dpsBase(); $mutate($std);
        $assert($hasError($validateStd($std)['schema'], "}{$field}"), "{$field} inválido deveria ser rejeitado");
    }
});

$test('XSD detecta tamanho inválido de xDescServ e cNBS', function () use ($dpsBase, $validateStd, $assert, $hasError): void {
    $std = $dpsBase(); $std->infDPS->serv->cServ->xDescServ = str_repeat('A', 2001);
    $assert($hasError($validateStd($std)['schema'], '}xDescServ'), 'xDescServ acima de 2000');
    $std = $dpsBase(); $std->infDPS->serv->cServ->cNBS = '123';
    $assert($hasError($validateStd($std)['schema'], '}cNBS'), 'cNBS fora do padrão de 9 dígitos');
});

$test('XSD detecta decimal fora do formato oficial', function () use ($dpsBase, $validateStd, $assert, $hasError): void {
    $std = $dpsBase(); $std->infDPS->valores->vServPrest->vServ = '100.5';
    $assert($hasError($validateStd($std)['schema'], '}vServ'));
    $std = $dpsBase(); $std->infDPS->valores->trib->tribMun->pAliq = '5.5';
    $assert($hasError($validateStd($std)['schema'], '}pAliq'));
});

$test('XSD aceita DPS sem tomador e sem nome/endereço do prestador', function () use ($dpsBase, $validateStd, $assert): void {
    $std = $dpsBase(); unset($std->infDPS->toma);
    $assert($validateStd($std)['schema'] === [], 'tomador é opcional no leiaute');
});

$test('validações semânticas existentes continuam no render', function () use ($dpsBase, $assert): void {
    $std = $dpsBase(); $std->infDPS->serv->cServ->cTribNac = '123';
    try { (new Dps($std))->render(); } catch (InvalidArgumentException $error) {
        $assert(str_contains($error->getMessage(), 'cTribNac'));
        return;
    }
    throw new RuntimeException('cTribNac inválido deveria ser rejeitado no render.');
});

$test('grupo obrigatório ausente é informado sem warnings do PHP', function () use ($dpsBase, $assert): void {
    $paths = ['prest', 'prest.regTrib', 'serv', 'serv.locPrest', 'serv.cServ', 'valores', 'valores.vServPrest', 'valores.trib', 'valores.trib.tribMun'];
    set_error_handler(static function (int $level, string $message): bool { throw new RuntimeException('warning: ' . $message); });
    try {
        foreach ($paths as $path) {
            $std = $dpsBase(); $parts = explode('.', $path); $last = array_pop($parts); $node = $std->infDPS;
            foreach ($parts as $part) $node = $node->{$part};
            unset($node->{$last});
            try { (new Dps($std))->render(); } catch (InvalidArgumentException $error) {
                $assert($error->getMessage() === "Campo obrigatório ausente: infDPS.{$path}.", $error->getMessage());
                continue;
            }
            throw new RuntimeException("Ausência de {$path} deveria ser rejeitada.");
        }
    } finally {
        restore_error_handler();
    }
});

$test('perfil sem XSD oficial publicado não é validado silenciosamente', function () use ($dpsBase, $assert): void {
    $dps = new Dps($dpsBase(), IbsCbsLayout::NT009_V104);
    try { $dps->validate('<DPS/>'); } catch (RuntimeException $error) {
        $assert(str_contains($error->getMessage(), 'não possui XSD oficial'));
        return;
    }
    throw new RuntimeException('Perfil NT-009 deveria informar ausência de XSD.');
});
