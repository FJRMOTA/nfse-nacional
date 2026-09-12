<?php

namespace Hadder\NfseNacional\Dps;

use DOMElement;
use InvalidArgumentException;
use NFePHP\Common\DOMImproved;
use stdClass;

final class IbsCbsSerializer
{
    private DOMImproved $dom;
    private string $profile;

    public function __construct(DOMImproved $dom, string $profile)
    {
        IbsCbsLayout::assertSupported($profile);
        $this->dom = $dom;
        $this->profile = $profile;
    }

    public function renderHeader(DOMElement $infDps, stdClass $data): void
    {
        if ($this->profile === IbsCbsLayout::CURRENT) {
            foreach (['finnfse', 'tpnfsedebito', 'tpnfsecredito'] as $field) {
                if (isset($data->{$field})) {
                    throw new InvalidArgumentException("{$field} na raiz de infDPS é exclusivo do perfil NT-009 v1.04.");
                }
            }
            return;
        }

        if (isset($data->ibscbs->finnfse)) {
            throw new InvalidArgumentException('No perfil NT-009 v1.04, finNFSe deve ser informado em infDPS, não em IBSCBS.');
        }
        $this->required($infDps, 'finNFSe', $data->finnfse ?? null);
        if (($data->finnfse ?? null) === '1' || ($data->finnfse ?? null) === 1) {
            if (!isset($data->tpnfsecredito) || isset($data->tpnfsedebito)) {
                throw new InvalidArgumentException('finNFSe=1 exige somente tpNFSeCredito.');
            }
        } elseif (($data->finnfse ?? null) === '2' || ($data->finnfse ?? null) === 2) {
            if (!isset($data->tpnfsedebito) || isset($data->tpnfsecredito)) {
                throw new InvalidArgumentException('finNFSe=2 exige somente tpNFSeDebito.');
            }
        } elseif (isset($data->tpnfsedebito) || isset($data->tpnfsecredito)) {
            throw new InvalidArgumentException('Tipos de ajuste não podem ser informados para NFS-e regular.');
        }
        $this->optional($infDps, 'tpNFSeDebito', $data->tpnfsedebito ?? null);
        $this->optional($infDps, 'tpNFSeCredito', $data->tpnfsecredito ?? null);
    }

    public function renderValueAdjustment(DOMElement $valores, stdClass $data): void
    {
        $adjustment = $data->valores->vajustebc ?? null;
        if (!$adjustment) {
            return;
        }
        if ($this->profile !== IbsCbsLayout::NT009_V104) {
            throw new InvalidArgumentException('vAjusteBC é exclusivo do perfil NT-009 v1.04.');
        }

        $group = $this->group($valores, 'vAjusteBC');
        $this->optional($group, 'pAjusteBCISSQN', $adjustment->pajustebcissqn ?? null);
        $this->optional($group, 'vAjusteBCISSQN', $adjustment->vajustebcissqn ?? null);
        if (!isset($adjustment->documentos)) {
            return;
        }
        $documents = $this->group($group, 'documentos');
        foreach ($this->items($adjustment->documentos->docajustebc ?? null, 1000, 'docAjusteBC') as $item) {
            $node = $this->group($documents, 'docAjusteBC');
            $this->required($node, 'tpAjusteBC', $item->tpajustebc ?? null);
            $this->optional($node, 'xTpAjusteBC', $item->xtpajustebc ?? null);
            $this->required($node, 'vTotDoc', $item->vtotdoc ?? null);
            // A grafia vAjuteAplic é a publicada no Anexo VI v1.04.00.
            $this->required($node, 'vAjuteAplic', $item->vajuteaplic ?? null);
            $this->optional($node, 'dtEmiDoc', $item->dtemidoc ?? null);
            $this->optional($node, 'dtCompDoc', $item->dtcompdoc ?? null);
            $this->documentChoice($node, $item);
            if (isset($item->fornec)) {
                $supplier = $this->group($node, 'fornec');
                $this->party($supplier, $item->fornec);
            }
        }
    }

    public function render(DOMElement $infDps, stdClass $data): void
    {
        if (!isset($data->ibscbs)) {
            return;
        }
        $ibs = $data->ibscbs;
        $this->rejectFieldsFromOtherProfile($ibs);
        $root = $this->group($infDps, 'IBSCBS');

        if ($this->profile === IbsCbsLayout::CURRENT) {
            if (isset($ibs->finnfse) && (string) $ibs->finnfse !== '0') {
                throw new InvalidArgumentException('O perfil atual (NT-004/v1.01) aceita apenas finNFSe=0.');
            }
            $this->required($root, 'finNFSe', $ibs->finnfse ?? null);
            $this->required($root, 'indFinal', $ibs->indfinal ?? null);
            $this->required($root, 'cIndOp', $ibs->cindop ?? null);
        } else {
            $this->optional($root, 'indFinal', $ibs->indfinal ?? null);
            $this->optional($root, 'cIndOp', $ibs->cindop ?? null);
            $this->optional($root, 'indZFMALC', $ibs->indzfmalc ?? null);
        }
        $this->optional($root, 'tpOper', $ibs->tpoper ?? null);
        if (in_array((string) ($ibs->tpoper ?? ''), ['2', '3'], true) && !isset($ibs->grefnfse)) {
            throw new InvalidArgumentException('tpOper 2 ou 3 exige gRefNFSe.');
        }
        $this->references($root, $ibs->grefnfse ?? null, 'gRefNFSe');
        $this->optional($root, 'tpEnteGov', $ibs->tpentegov ?? null);
        if ($this->profile === IbsCbsLayout::NT009_V104) {
            $this->optional($root, 'indDoacao', $ibs->inddoacao ?? null);
        }
        $this->required($root, 'indDest', $ibs->inddest ?? null);
        if ((string) ($ibs->inddest ?? '') === '1' && !isset($ibs->dest)) {
            throw new InvalidArgumentException('indDest=1 exige o grupo dest.');
        }
        if (isset($ibs->dest)) {
            $dest = $this->group($root, 'dest');
            $this->party($dest, $ibs->dest);
        }
        if (isset($ibs->imovel)) {
            $this->profile === IbsCbsLayout::CURRENT
                ? $this->currentProperty($root, $ibs->imovel)
                : $this->v104Property($root, $ibs->imovel);
        }
        if ($this->profile === IbsCbsLayout::NT009_V104 && isset($ibs->bensmoveis)) {
            foreach ($this->items($ibs->bensmoveis, 1000, 'bensMoveis') as $item) {
                $node = $this->group($root, 'bensMoveis');
                $this->required($node, 'cNCMBemMovel', $item->cncmbemmovel ?? null);
                $this->required($node, 'xNCMBemMovel', $item->xncmbemmovel ?? null);
                $this->required($node, 'qtdNCMBemMovel', $item->qtdncmbemmovel ?? null);
            }
        }
        $this->values($root, $ibs->valores ?? null);
        if ($this->profile === IbsCbsLayout::NT009_V104 && isset($ibs->gpgtovinc)) {
            $payments = $this->group($root, 'gPgtoVinc');
            foreach ($this->items($ibs->gpgtovinc->pgto ?? null, 99, 'pgto') as $item) {
                $node = $this->group($payments, 'pgto');
                foreach (['npag' => 'nPag', 'idtransacao' => 'idTransacao', 'tpmeiopgto' => 'tpMeioPgto', 'cnpjreceb' => 'CNPJReceb', 'cnpjbasepsp' => 'CNPJBasePSP'] as $key => $tag) {
                    $this->required($node, $tag, $item->{$key} ?? null);
                }
            }
        }
    }

    private function values(DOMElement $root, ?stdClass $values): void
    {
        if (!$values) {
            throw new InvalidArgumentException('IBSCBS/valores é obrigatório quando IBSCBS é informado.');
        }
        if ($this->profile === IbsCbsLayout::NT009_V104 && isset($values->greerepres)) {
            throw new InvalidArgumentException('gReeRepRes foi substituído por valores/vAjusteBC no perfil NT-009 v1.04.');
        }
        $valuesNode = $this->group($root, 'valores');
        if ($this->profile === IbsCbsLayout::CURRENT && isset($values->greerepres)) {
            $this->currentReimbursements($valuesNode, $values->greerepres);
        }
        $trib = $this->group($valuesNode, 'trib');
        $g = $values->trib->gibscbs ?? null;
        if (!$g) {
            throw new InvalidArgumentException('IBSCBS/valores/trib/gIBSCBS é obrigatório.');
        }
        $gNode = $this->group($trib, 'gIBSCBS');
        $this->required($gNode, 'CST', $g->cst ?? null);
        $this->required($gNode, 'cClassTrib', $g->cclasstrib ?? null);
        $this->optional($gNode, 'cCredPres', $g->ccredpres ?? null);
        if ($this->profile === IbsCbsLayout::NT009_V104 && isset($values->trib->gibscbsajuste)) {
            $adjust = $this->group($trib, 'gIBSCBSAjuste');
            $this->required($adjust, 'vIBS', $values->trib->gibscbsajuste->vibs ?? null);
            $this->required($adjust, 'vCBS', $values->trib->gibscbsajuste->vcbs ?? null);
        }
        if (isset($g->gtribregular)) {
            $node = $this->group($gNode, 'gTribRegular');
            $this->required($node, 'CSTReg', $g->gtribregular->cstreg ?? null);
            $this->required($node, 'cClassTribReg', $g->gtribregular->cclasstribreg ?? null);
        }
        if (isset($g->gdif)) {
            $node = $this->group($gNode, 'gDif');
            foreach (['pdifuf' => 'pDifUF', 'pdifmun' => 'pDifMun', 'pdifcbs' => 'pDifCBS'] as $key => $tag) {
                $this->required($node, $tag, $g->gdif->{$key} ?? null);
            }
        }
        if ($this->profile === IbsCbsLayout::NT009_V104 && isset($g->gestornocred)) {
            $node = $this->group($gNode, 'gEstornoCred');
            $this->required($node, 'vIBSEstCred', $g->gestornocred->vibsestcred ?? null);
            $this->required($node, 'vCBSEstCred', $g->gestornocred->vcbsestcred ?? null);
        }
        if ($this->profile === IbsCbsLayout::NT009_V104 && isset($g->gpagantecipado)) {
            $this->references($gNode, $g->gpagantecipado, 'gPagAntecipado');
        }
    }

    private function party(DOMElement $parent, stdClass $party): void
    {
        $choices = array_values(array_filter(['cnpj', 'cpf', 'nif', 'cnaonif'], static fn ($key) => isset($party->{$key})));
        if (count($choices) !== 1) {
            throw new InvalidArgumentException('A identificação deve conter exatamente um entre CNPJ, CPF, NIF ou cNaoNIF.');
        }
        $tags = ['cnpj' => 'CNPJ', 'cpf' => 'CPF', 'nif' => 'NIF', 'cnaonif' => 'cNaoNIF'];
        $key = $choices[0];
        $this->required($parent, $tags[$key], $party->{$key});
        $this->required($parent, 'xNome', $party->xnome ?? null);
        if (isset($party->end)) {
            $end = $this->group($parent, 'end');
            if (isset($party->end->endnac)) {
                $national = $this->group($end, 'endNac');
                $this->required($national, 'cMun', $party->end->endnac->cmun ?? null);
                $this->required($national, 'CEP', $party->end->endnac->cep ?? null);
            } elseif (isset($party->end->endext)) {
                $external = $this->group($end, 'endExt');
                foreach (['cpais' => 'cPais', 'cendpost' => 'cEndPost', 'xcidade' => 'xCidade', 'xestprovreg' => 'xEstProvReg'] as $field => $tag) {
                    $this->required($external, $tag, $party->end->endext->{$field} ?? null);
                }
            } else {
                throw new InvalidArgumentException('Endereço deve conter endNac ou endExt.');
            }
            $this->required($end, 'xLgr', $party->end->xlgr ?? null);
            $this->required($end, 'nro', $party->end->nro ?? null);
            $this->optional($end, 'xCpl', $party->end->xcpl ?? null);
            $this->required($end, 'xBairro', $party->end->xbairro ?? null);
        }
        $this->optional($parent, 'fone', $party->fone ?? null);
        $this->optional($parent, 'email', $party->email ?? null);
    }

    private function currentProperty(DOMElement $root, stdClass $property): void
    {
        $node = $this->group($root, 'imovel');
        $this->optional($node, 'inscImobFisc', $property->inscimobfisc ?? null);
        if (isset($property->ccib)) {
            $this->required($node, 'cCIB', $property->ccib);
        } elseif (isset($property->end)) {
            $end = $this->group($node, 'end');
            $this->optional($end, 'CEP', $property->end->cep ?? null);
            foreach (['xlgr' => 'xLgr', 'nro' => 'nro', 'xcpl' => 'xCpl', 'xbairro' => 'xBairro'] as $key => $tag) {
                $this->optional($end, $tag, $property->end->{$key} ?? null);
            }
        } else {
            throw new InvalidArgumentException('imovel deve conter cCIB ou end no perfil atual.');
        }
    }

    private function v104Property(DOMElement $root, stdClass $property): void
    {
        $node = $this->group($root, 'imovel');
        $this->required($node, 'cMun', $property->cmun ?? null);
        if (isset($property->glocacao)) {
            $lease = $this->group($node, 'gLocacao');
            $this->required($lease, 'pCopropriedade', $property->glocacao->pcopropriedade ?? null);
            $this->required($lease, 'vTotOper', $property->glocacao->vtotoper ?? null);
            foreach (['vdescincondtot' => 'vDescIncondTot', 'vdesccondtot' => 'vDescCondTot', 'dvencorig' => 'dVencOrig'] as $key => $tag) {
                $this->optional($lease, $tag, $property->glocacao->{$key} ?? null);
            }
        }
        foreach ($this->items($property->gunidimob ?? null, 99, 'gUnidImob', false) as $unit) {
            $unitNode = $this->group($node, 'gUnidImob');
            $this->optional($unitNode, 'inscImobFisc', $unit->inscimobfisc ?? null);
            if (isset($unit->ccib)) {
                $this->required($unitNode, 'cCIB', $unit->ccib);
            } elseif (isset($unit->end)) {
                $end = $this->group($unitNode, 'end');
                foreach (['cep' => 'CEP', 'xlgr' => 'xLgr', 'nro' => 'nro'] as $key => $tag) {
                    $this->required($end, $tag, $unit->end->{$key} ?? null);
                }
                $this->optional($end, 'xCpl', $unit->end->xcpl ?? null);
                $this->optional($end, 'xBairro', $unit->end->xbairro ?? null);
            } else {
                throw new InvalidArgumentException('gUnidImob deve conter cCIB ou end.');
            }
            foreach ($this->items($unit->gajustebclocimoveis ?? null, 1000, 'gAjusteBCLocImoveis', false) as $adjustment) {
                $adjust = $this->group($unitNode, 'gAjusteBCLocImoveis');
                $this->required($adjust, 'tpAjusteBCLocImoveis', $adjustment->tpajustebclocimoveis ?? null);
                $this->optional($adjust, 'xTpAjusteBCLocImoveis', $adjustment->xtpajustebclocimoveis ?? null);
                $this->required($adjust, 'vAjusteBCLocImoveis', $adjustment->vajustebclocimoveis ?? null);
            }
        }
    }

    private function documentChoice(DOMElement $node, stdClass $item): void
    {
        $choices = array_values(array_filter(['dfenacional', 'docfiscaloutro', 'docoutro'], static fn ($key) => isset($item->{$key})));
        if (count($choices) !== 1) {
            throw new InvalidArgumentException('docAjusteBC deve conter exatamente um documento de origem.');
        }
        $key = $choices[0];
        $tags = ['dfenacional' => 'dFeNacional', 'docfiscaloutro' => 'docFiscalOutro', 'docoutro' => 'docOutro'];
        $group = $this->group($node, $tags[$key]);
        $fields = $key === 'dfenacional'
            ? ['tipochavedfe' => 'tipoChaveDFe', 'xtipochavedfe' => 'xTipoChaveDFe', 'chavedfe' => 'chaveDFe']
            : ($key === 'docfiscaloutro'
                ? ['cmundocfiscal' => 'cMunDocFiscal', 'ndocfiscal' => 'nDocFiscal', 'xdocfiscal' => 'xDocFiscal']
                : ['ndoc' => 'nDoc', 'xdoc' => 'xDoc']);
        foreach ($fields as $field => $tag) {
            $field === 'xtipochavedfe' ? $this->optional($group, $tag, $item->{$key}->{$field} ?? null) : $this->required($group, $tag, $item->{$key}->{$field} ?? null);
        }
    }

    private function currentReimbursements(DOMElement $parent, stdClass $data): void
    {
        $group = $this->group($parent, 'gReeRepRes');
        foreach ($this->items($data->documentos ?? null, 1000, 'gReeRepRes/documentos') as $item) {
            $node = $this->group($group, 'documentos');
            $this->documentChoice($node, $item);
            if (isset($item->fornec)) {
                $supplier = $this->group($node, 'fornec');
                $this->party($supplier, $item->fornec);
            }
            $this->required($node, 'dtEmiDoc', $item->dtemidoc ?? null);
            $this->required($node, 'dtCompDoc', $item->dtcompdoc ?? null);
            $this->required($node, 'tpReeRepRes', $item->tpreerepres ?? null);
            $this->optional($node, 'xTpReeRepRes', $item->xtpreerepres ?? null);
            $this->required($node, 'vlrReeRepRes', $item->vlrreerepres ?? null);
        }
    }

    private function references(DOMElement $parent, ?stdClass $data, string $name): void
    {
        if (!$data) return;
        $items = $this->items($data->refnfse ?? null, 99, $name . '/refNFSe');
        $node = $this->group($parent, $name);
        foreach ($items as $reference) $this->required($node, 'refNFSe', $reference);
    }

    private function rejectFieldsFromOtherProfile(stdClass $ibs): void
    {
        $v104 = ['indzfmalc', 'inddoacao', 'bensmoveis', 'gpgtovinc'];
        if ($this->profile === IbsCbsLayout::CURRENT) {
            foreach ($v104 as $field) if (isset($ibs->{$field})) throw new InvalidArgumentException("{$field} é exclusivo do perfil NT-009 v1.04.");
        }
    }

    private function group(DOMElement $parent, string $name): DOMElement
    {
        $node = $this->dom->createElement($name);
        $parent->appendChild($node);
        return $node;
    }

    private function required(DOMElement $parent, string $name, $value): void
    {
        if ($value === null || $value === '') throw new InvalidArgumentException("Campo obrigatório ausente: {$name}.");
        $this->dom->addChild($parent, $name, (string) $value, true);
    }

    private function optional(DOMElement $parent, string $name, $value): void
    {
        if ($value !== null && $value !== '') $this->dom->addChild($parent, $name, (string) $value);
    }

    private function items($value, int $max, string $name, bool $required = true): array
    {
        $items = $value === null ? [] : (is_array($value) ? $value : [$value]);
        if ($required && !$items) throw new InvalidArgumentException("Grupo repetível vazio: {$name}.");
        if (count($items) > $max) throw new InvalidArgumentException("{$name} excede o máximo de {$max} ocorrências.");
        return $items;
    }
}
