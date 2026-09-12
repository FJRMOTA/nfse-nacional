<?php

namespace Hadder\NfseNacional\Danfse;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use InvalidArgumentException;

/**
 * Extracts only data represented in an authorised national NFS-e XML.
 *
 * Field paths and presentation rules follow NT-008 v1.02 (14/07/2026).
 */
final class DanfseXmlData
{
    private DOMDocument $dom;
    private DOMXPath $xpath;

    public function __construct(string $xml)
    {
        if (trim($xml) === '') {
            throw new InvalidArgumentException('XML da NFS-e não informado.');
        }

        $this->dom = new DOMDocument('1.0', 'UTF-8');
        $this->dom->preserveWhiteSpace = false;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $this->dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
            $errors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$loaded) {
            $detail = isset($errors[0]) ? trim($errors[0]->message) : 'estrutura malformada';
            throw new InvalidArgumentException('Não foi possível carregar o XML da NFS-e: ' . $detail);
        }

        $this->xpath = new DOMXPath($this->dom);
        if (!$this->node('/*[local-name()="NFSe"]/*[local-name()="infNFSe"]')) {
            throw new InvalidArgumentException('XML inválido: elemento NFSe/infNFSe não encontrado.');
        }
        if (!$this->node('/*[local-name()="NFSe"]/*[local-name()="infNFSe"]/*[local-name()="DPS"]/*[local-name()="infDPS"]')) {
            throw new InvalidArgumentException('XML inválido: elemento NFSe/infNFSe/DPS/infDPS não encontrado.');
        }
    }

    /** @return array<string, mixed> */
    public static function fromXml(string $xml): array
    {
        return (new self($xml))->toArray();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $inf = $this->node('/*[local-name()="NFSe"]/*[local-name()="infNFSe"]');
        $dps = $this->node('./*[local-name()="DPS"]/*[local-name()="infDPS"]', $inf);
        $prest = $this->node('./*[local-name()="prest"]', $dps);
        $toma = $this->node('./*[local-name()="toma"]', $dps);
        $interm = $this->node('./*[local-name()="interm"]', $dps);
        $dest = $this->node('./*[local-name()="IBSCBS"]/*[local-name()="dest"]', $dps);
        $serv = $this->node('./*[local-name()="serv"]', $dps);
        $dpsValores = $this->node('./*[local-name()="valores"]', $dps);
        $tribMun = $this->node('./*[local-name()="trib"]/*[local-name()="tribMun"]', $dpsValores);
        $tribFed = $this->node('./*[local-name()="trib"]/*[local-name()="tribFed"]', $dpsValores);
        $ibsCbs = $this->node('./*[local-name()="IBSCBS"]', $inf);
        $nfseValores = $this->node('./*[local-name()="valores"]', $inf);

        $id = $inf instanceof DOMElement ? $inf->getAttribute('Id') : '';
        $key = preg_replace('/^NFS/i', '', trim($id)) ?? '';
        if ($key === '') {
            throw new InvalidArgumentException('XML inválido: atributo Id de infNFSe não contém a chave da NFS-e.');
        }

        $statusCode = $this->text('./*[local-name()="cStat"]', $inf);
        $substitutedKey = $this->text('./*[local-name()="subst"]/*[local-name()="chSubstda"]', $dps);
        $cancelled = $this->hasAcceptedCancellationEvent();
        $watermark = $cancelled ? 'CANCELADA' : ($substitutedKey !== '' || $statusCode === '101' ? 'SUBSTITUÍDA' : null);
        $tpAmb = $this->text('./*[local-name()="tpAmb"]', $dps);
        $competenceRaw = $this->text('./*[local-name()="dCompet"]', $dps);

        $municipality = $this->text('./*[local-name()="xLocEmi"]', $inf);
        $issuerAddress = $this->node('./*[local-name()="emit"]/*[local-name()="enderNac"]', $inf);
        $uf = $this->text('./*[local-name()="UF"]', $issuerAddress);
        $nationalCode = $this->text('./*[local-name()="cServ"]/*[local-name()="cTribNac"]', $serv);

        $pis = $this->text('./*[local-name()="piscofins"]/*[local-name()="vPis"]', $tribFed);
        $cofins = $this->text('./*[local-name()="piscofins"]/*[local-name()="vCofins"]', $tribFed);
        $pisCofinsRetention = $this->text('./*[local-name()="piscofins"]/*[local-name()="tpRetPisCofins"]', $tribFed);

        return [
            'version' => '2.0',
            'key' => $key,
            'number' => $this->text('./*[local-name()="nNFSe"]', $inf),
            'competence' => $this->date($competenceRaw),
            'competence_year' => $this->year($competenceRaw),
            'nfse_issued_at' => $this->dateTime($this->text('./*[local-name()="dhProc"]', $inf)),
            'dps_number' => $this->text('./*[local-name()="nDPS"]', $dps),
            'dps_series' => $this->text('./*[local-name()="serie"]', $dps),
            'dps_issued_at' => $this->dateTime($this->text('./*[local-name()="dhEmi"]', $dps)),
            'issuer' => $this->mapCode($this->text('./*[local-name()="tpEmit"]', $dps), [
                '1' => 'Prestador', '2' => 'Tomador', '3' => 'Intermediário',
            ]),
            'status' => $this->mapCode($statusCode, [
                '100' => 'NFS-e Gerada', '101' => 'NFS-e de Substituição Gerada',
                '102' => 'NFS-e de Decisão Judicial', '103' => 'NFS-e Avulsa', '107' => 'NFS-e MEI',
            ]),
            // finNFSe belongs to the current XML layout. This does not render the IBS/CBS tax block.
            'purpose' => $this->mapCode($this->text('./*[local-name()="IBSCBS"]/*[local-name()="finNFSe"]', $dps), [
                '0' => 'NFS-e regular',
            ]),
            'environment' => $tpAmb,
            'environment_label' => $this->mapCode($tpAmb, ['1' => 'Produção', '2' => 'Homologação']),
            'generator' => $this->mapCode($this->text('./*[local-name()="ambGer"]', $inf), [
                '1' => 'Prefeitura', '2' => 'Sistema Nacional da NFS-e',
            ]),
            'municipality' => trim($municipality . ($uf !== '' ? ' / ' . $uf : '')),
            'show_municipality' => substr($nationalCode, 0, 2) !== '99',
            'homologation' => $tpAmb === '2',
            'watermark' => $watermark,
            'consultation_url' => 'https://www.nfse.gov.br/ConsultaPublica/?tpc=1&chave=' . rawurlencode($key),
            'provider' => $this->party($prest, $municipality, $uf),
            'customer' => $this->party($toma),
            'customer_identified' => $toma !== null,
            'recipient' => $this->party($dest),
            'recipient_identified' => $dest !== null,
            'recipient_is_customer' => $dest !== null && $this->sameParty($toma, $dest),
            'intermediary' => $this->party($interm),
            'intermediary_identified' => $interm !== null,
            'simple_national' => $this->mapCode($this->text('./*[local-name()="regTrib"]/*[local-name()="opSimpNac"]', $prest), [
                '1' => 'Não optante', '2' => 'Optante - MEI', '3' => 'Optante - ME/EPP',
            ]),
            'simple_assessment' => $this->mapCode($this->text('./*[local-name()="regTrib"]/*[local-name()="regApTribSN"]', $prest), [
                '1' => 'Tributos federais e municipal pelo SN',
                '2' => 'Tributos federais pelo SN e ISSQN fora do SN',
                '3' => 'Tributos federais e municipal fora do SN',
            ]),
            'service' => [
                'national_code' => $this->taxCode($nationalCode),
                'municipal_code' => $this->text('./*[local-name()="cServ"]/*[local-name()="cTribMun"]', $serv),
                'nbs' => $this->text('./*[local-name()="cServ"]/*[local-name()="cNBS"]', $serv),
                'location' => $this->location(
                    $this->text('./*[local-name()="xLocPrestacao"]', $inf),
                    $this->text('./*[local-name()="cPaisPrestacao"]', $this->node('./*[local-name()="locPrest"]', $serv)),
                    $uf
                ),
                'code_description' => $this->firstNonEmpty([
                    $this->text('./*[local-name()="xTribMun"]', $inf),
                    $this->text('./*[local-name()="xTribNac"]', $inf),
                ]),
                'description' => $this->text('./*[local-name()="cServ"]/*[local-name()="xDescServ"]', $serv),
            ],
            'municipal_tax' => $this->municipalTax($tribMun, $nfseValores, $dpsValores, $inf, $uf),
            'federal_tax' => [
                'irrf' => $this->money($this->text('./*[local-name()="vRetIRRF"]', $tribFed)),
                'social_security' => $this->money($this->text('./*[local-name()="vRetCP"]', $tribFed)),
                'social_contributions' => $this->moneyFromFloat(
                    $this->decimal($this->text('./*[local-name()="vRetCSLL"]', $tribFed))
                    + ($pisCofinsRetention === '1' ? $this->decimal($pis) + $this->decimal($cofins) : 0.0)
                ),
                'pis' => $pisCofinsRetention === '1' ? $this->money('0') : $this->money($pis),
                'cofins' => $pisCofinsRetention === '1' ? $this->money('0') : $this->money($cofins),
                'retained_description' => $this->retainedDescription($pisCofinsRetention, $pis, $cofins),
            ],
            'ibs_cbs' => $this->ibsCbs($ibsCbs, $dps),
            'totals' => [
                'service' => $this->money($this->text('./*[local-name()="vServPrest"]/*[local-name()="vServ"]', $dpsValores)),
                'unconditional_discount' => $this->money($this->text('./*[local-name()="vDescCondIncond"]/*[local-name()="vDescIncond"]', $dpsValores)),
                'conditional_discount' => $this->money($this->text('./*[local-name()="vDescCondIncond"]/*[local-name()="vDescCond"]', $dpsValores)),
                'withholdings' => $this->money($this->text('./*[local-name()="vTotalRet"]', $nfseValores)),
                'net' => $this->money($this->text('./*[local-name()="vLiq"]', $nfseValores)),
            ],
            'additional_information' => $this->additionalInformation($dps, $inf),
        ];
    }

    /** @return array<string, mixed> */
    private function ibsCbs(?DOMElement $ibsCbs, ?DOMElement $dps): array
    {
        if (!$ibsCbs) {
            return ['applicable' => false];
        }
        $values = $this->node('./*[local-name()="valores"]', $ibsCbs);
        $uf = $this->node('./*[local-name()="uf"]', $values);
        $mun = $this->node('./*[local-name()="mun"]', $values);
        $fed = $this->node('./*[local-name()="fed"]', $values);
        $totals = $this->node('./*[local-name()="totCIBS"]', $ibsCbs);
        $ibsTotals = $this->node('./*[local-name()="gIBS"]', $totals);
        $ufTotals = $this->node('./*[local-name()="gIBSUFTot"]', $ibsTotals);
        $munTotals = $this->node('./*[local-name()="gIBSMunTot"]', $ibsTotals);
        $cbsTotals = $this->node('./*[local-name()="gCBS"]', $totals);
        $declaration = $this->node('./*[local-name()="IBSCBS"]', $dps);
        $gDeclaration = $this->node('./*[local-name()="valores"]/*[local-name()="trib"]/*[local-name()="gIBSCBS"]', $declaration);

        $exclusions = $this->decimal($this->text('./*[local-name()="vDescIncond"]', $values))
            + $this->decimal($this->text('./*[local-name()="vCalcReeRepRes"]', $values))
            + $this->decimal($this->text('./*[local-name()="vISSQN"]', $values))
            + $this->decimal($this->text('./*[local-name()="vPIS"]', $values))
            + $this->decimal($this->text('./*[local-name()="vCOFINS"]', $values));
        $ibsTotal = $this->decimal($this->text('./*[local-name()="vIBSTot"]', $ibsTotals));
        $cbsTotal = $this->decimal($this->text('./*[local-name()="vCBS"]', $cbsTotals));

        return [
            'applicable' => true,
            'classification' => trim($this->text('./*[local-name()="CST"]', $gDeclaration) . ' / ' . $this->text('./*[local-name()="cClassTrib"]', $gDeclaration), ' /'),
            'operation' => $this->text('./*[local-name()="cIndOp"]', $declaration),
            'incidence' => trim($this->text('./*[local-name()="cLocalidadeIncid"]', $ibsCbs) . ' / ' . $this->text('./*[local-name()="xLocalidadeIncid"]', $ibsCbs), ' /'),
            'exclusions' => $this->moneyFromFloat($exclusions),
            'base' => $this->money($this->text('./*[local-name()="vBC"]', $values)),
            'reduction_rates' => $this->joinedPercent([$this->text('./*[local-name()="pRedAliqUF"]', $uf), $this->text('./*[local-name()="pRedAliqMun"]', $mun), $this->text('./*[local-name()="pRedAliqCBS"]', $fed)]),
            'rates' => $this->joinedPercent([$this->text('./*[local-name()="pIBSUF"]', $uf), $this->text('./*[local-name()="pIBSMun"]', $mun)]),
            'effective_municipal_rate' => $this->percent($this->text('./*[local-name()="pAliqEfetMun"]', $mun)),
            'municipal_amount' => $this->money($this->text('./*[local-name()="vIBSMun"]', $munTotals)),
            'municipal_differment' => $this->money($this->text('./*[local-name()="vDifMun"]', $munTotals)),
            'effective_state_rate' => $this->percent($this->text('./*[local-name()="pAliqEfetUF"]', $uf)),
            'state_amount' => $this->money($this->text('./*[local-name()="vIBSUF"]', $ufTotals)),
            'state_differment' => $this->money($this->text('./*[local-name()="vDifUF"]', $ufTotals)),
            'ibs_amount' => $this->moneyFromFloat($ibsTotal),
            'cbs_rate' => $this->percent($this->text('./*[local-name()="pCBS"]', $fed)),
            'effective_cbs_rate' => $this->percent($this->text('./*[local-name()="pAliqEfetCBS"]', $fed)),
            'cbs_amount' => $this->moneyFromFloat($cbsTotal),
            'cbs_differment' => $this->money($this->text('./*[local-name()="vDifCBS"]', $cbsTotals)),
            'total' => $this->moneyFromFloat($ibsTotal + $cbsTotal),
            'net_with_taxes' => $this->money($this->text('./*[local-name()="vTotNF"]', $totals)),
            'differment_rates' => $this->joinedPercent([
                $this->text('./*[local-name()="gDif"]/*[local-name()="pDifUF"]', $gDeclaration),
                $this->text('./*[local-name()="gDif"]/*[local-name()="pDifMun"]', $gDeclaration),
                $this->text('./*[local-name()="gDif"]/*[local-name()="pDifCBS"]', $gDeclaration),
            ]),
        ];
    }

    /** @param array<int, string> $values */
    private function joinedPercent(array $values): string
    {
        $formatted = array_values(array_filter(array_map(fn (string $value): string => $this->percent($value), $values)));
        return implode(' / ', $formatted);
    }

    /** @return array<string, mixed> */
    private function municipalTax(?DOMElement $tribMun, ?DOMElement $nfseValores, ?DOMElement $dpsValores, ?DOMElement $inf, string $uf): array
    {
        $type = $this->text('./*[local-name()="tribISSQN"]', $tribMun);
        $benefitType = $this->text('./*[local-name()="tpBM"]', $nfseValores);
        $benefit = $this->node('./*[local-name()="BM"]', $tribMun);
        $suspension = $this->node('./*[local-name()="exigSusp"]', $tribMun);
        $calc = $this->firstNonEmpty([
            $this->text('./*[local-name()="vCalcBM"]', $nfseValores),
            $this->text('./*[local-name()="vRedBCBM"]', $benefit),
        ]);
        $calcPercent = $this->text('./*[local-name()="pRedBCBM"]', $benefit);

        return [
            'applicable' => in_array($type, ['1', '2'], true),
            'type' => $this->mapCode($type, [
                '1' => 'Operação tributável', '2' => 'Imunidade', '3' => 'Exportação de serviço', '4' => 'Não incidência',
            ]),
            'incidence' => $this->location(
                $this->text('./*[local-name()="xLocIncid"]', $inf),
                $this->text('./*[local-name()="cPaisResult"]', $tribMun),
                $uf
            ),
            'special_regime' => $this->mapCode($this->text('./*[local-name()="regTrib"]/*[local-name()="regEspTrib"]', $this->node('./*[local-name()="prest"]', $this->node('ancestor-or-self::*[local-name()="infDPS"]', $tribMun))), [
                '0' => 'Nenhum', '1' => 'Ato Cooperado (Cooperativa)', '2' => 'Estimativa',
                '3' => 'Microempresa Municipal', '4' => 'Notário ou Registrador',
                '5' => 'Profissional Autônomo', '6' => 'Sociedade de Profissionais', '9' => 'Outros',
            ]),
            'immunity' => $this->mapCode($this->text('./*[local-name()="tpImunidade"]', $tribMun), [
                '0' => 'Imunidade (tipo não informado)',
                '1' => 'Patrimônio, renda ou serviços, uns dos outros', '2' => 'Templos de qualquer culto',
                '3' => 'Partidos, sindicatos, educação e assistência social sem fins lucrativos',
                '4' => 'Livros, jornais, periódicos e papel para impressão',
                '5' => 'Fonogramas e videofonogramas musicais brasileiros',
            ]),
            'suspension' => $this->mapCode($this->text('./*[local-name()="tpSusp"]', $suspension), [
                '1' => 'Exigibilidade suspensa por decisão judicial',
                '2' => 'Exigibilidade suspensa por processo administrativo',
            ]),
            'suspension_process' => $this->text('./*[local-name()="nProcesso"]', $suspension),
            'benefit' => $this->mapCode($benefitType, [
                '1' => 'Isenção', '2' => 'Redução percentual da base de cálculo',
                '3' => 'Redução monetária da base de cálculo', '4' => 'Alíquota diferenciada',
            ]),
            'benefit_calculation' => $calc !== '' ? $this->money($calc) : $this->percent($calcPercent),
            'deductions' => $this->money($this->firstNonEmpty([
                $this->text('./*[local-name()="vDedRed"]', $dpsValores),
                $this->text('./*[local-name()="vCalcDR"]', $nfseValores),
            ])),
            'unconditional_discount' => $this->money($this->text('./*[local-name()="vDescCondIncond"]/*[local-name()="vDescIncond"]', $dpsValores)),
            'base' => $this->money($this->text('./*[local-name()="vBC"]', $nfseValores)),
            'rate' => $this->percent($this->text('./*[local-name()="pAliqAplic"]', $nfseValores)),
            'withholding' => $this->mapCode($this->text('./*[local-name()="tpRetISSQN"]', $tribMun), [
                '1' => 'Não retido', '2' => 'Retido pelo tomador', '3' => 'Retido pelo intermediário',
            ]),
            'amount' => $this->money($this->text('./*[local-name()="vISSQN"]', $nfseValores)),
        ];
    }

    /** @return array<string, mixed> */
    private function party(?DOMElement $node, string $knownCity = '', string $knownUf = ''): array
    {
        if (!$node) {
            return [];
        }
        $address = $this->node('./*[local-name()="end"]', $node) ?: $this->node('./*[local-name()="enderNac"]', $node);
        $national = $this->node('./*[local-name()="endNac"]', $address) ?: $address;
        $external = $this->node('./*[local-name()="endExt"]', $address);
        $city = $this->firstNonEmpty([$this->text('./*[local-name()="xCidade"]', $external), $knownCity]);
        $uf = $this->firstNonEmpty([$this->text('./*[local-name()="UF"]', $national), $knownUf]);
        $country = $this->text('./*[local-name()="cPais"]', $external);
        $cityUf = trim($city . ($uf !== '' ? ' / ' . $uf : '') . ($country !== '' ? ' / ' . $country : ''));
        $municipalityCode = $this->text('./*[local-name()="cMun"]', $national);
        $resolvedMunicipality = MunicipalityResolver::resolve($municipalityCode);
        $city = $this->firstNonEmpty([$city, $resolvedMunicipality['name']]);
        $uf = $this->firstNonEmpty([$uf, $resolvedMunicipality['uf']]);
        $cityUf = trim($city . ($uf !== '' ? ' / ' . $uf : '') . ($country !== '' ? ' / ' . $country : ''));
        $postalCode = $this->firstNonEmpty([
            $this->formatCep($this->text('./*[local-name()="CEP"]', $national)),
            $this->text('./*[local-name()="cEndPost"]', $external),
        ]);

        return [
            'document' => $this->document($node),
            'municipal_registration' => $this->text('./*[local-name()="IM"]', $node),
            'phone' => $this->phone($this->text('./*[local-name()="fone"]', $node)),
            'name' => $this->text('./*[local-name()="xNome"]', $node),
            'city' => $cityUf,
            'ibge_postal' => trim($municipalityCode . ($postalCode !== '' ? ' / ' . $postalCode : '')),
            'address' => implode(', ', array_filter([
                $this->text('./*[local-name()="xLgr"]', $address),
                $this->text('./*[local-name()="nro"]', $address),
                $this->text('./*[local-name()="xCpl"]', $address),
                $this->text('./*[local-name()="xBairro"]', $address),
            ], static fn ($value): bool => $value !== '')),
            'email' => $this->text('./*[local-name()="email"]', $node),
        ];
    }

    private function hasAcceptedCancellationEvent(): bool
    {
        foreach (['e101101', 'e105102', 'e105104', 'e105109'] as $name) {
            $event = $this->node('//*[local-name()="' . $name . '"]');
            if (!$event) {
                continue;
            }
            $eventInfo = $this->node('ancestor::*[local-name()="infEvento"][1]', $event);
            $eventStatus = $this->text('./*[local-name()="cStat"]', $eventInfo);
            if (in_array($eventStatus, ['101', '135', '136'], true)) {
                return true;
            }
        }
        return false;
    }

    /** @return string[] */
    private function additionalInformation(?DOMElement $dps, ?DOMElement $inf): array
    {
        $items = [];
        $this->append($items, 'Inf. Cont.', $this->text('./*[local-name()="serv"]/*[local-name()="infoCompl"]/*[local-name()="xInfComp"]', $dps));
        $this->append($items, 'NFS-e Subst.', $this->text('./*[local-name()="subst"]/*[local-name()="chSubstda"]', $dps));
        $this->append($items, 'Doc. Ref.', $this->text('./*[local-name()="serv"]/*[local-name()="infoCompl"]/*[local-name()="docRef"]', $dps));
        $this->append($items, 'Cod. Obra', $this->text('./*[local-name()="serv"]/*[local-name()="obra"]/*[local-name()="cObra"]', $dps));
        $this->append($items, 'Insc. Imob.', $this->text('./*[local-name()="serv"]/*[local-name()="obra"]/*[local-name()="inscImobFisc"]', $dps));
        $this->append($items, 'Cod. Evt.', $this->text('./*[local-name()="serv"]/*[local-name()="atvEvento"]/*[local-name()="idAtvEvt"]', $dps));
        $this->append($items, 'Doc. Tec.', $this->text('./*[local-name()="serv"]/*[local-name()="infoCompl"]/*[local-name()="idDocTec"]', $dps));
        $this->append($items, 'Núm. Ped.', $this->text('./*[local-name()="serv"]/*[local-name()="infoCompl"]/*[local-name()="xPed"]', $dps));
        $this->append($items, 'Item Ped.', $this->text('./*[local-name()="serv"]/*[local-name()="infoCompl"]/*[local-name()="gItemPed"]/*[local-name()="xItemPed"]', $dps));
        $this->append($items, 'Inf. A. T. Mun.', $this->text('./*[local-name()="xOutInf"]', $inf));

        $valueTotals = $this->node('./*[local-name()="valores"]/*[local-name()="trib"]/*[local-name()="totTrib"]/*[local-name()="vTotTrib"]', $dps);
        $percentTotals = $this->node('./*[local-name()="valores"]/*[local-name()="trib"]/*[local-name()="totTrib"]/*[local-name()="pTotTrib"]', $dps);
        $federal = $this->firstNonEmpty([$this->money($this->text('./*[local-name()="vTotTribFed"]', $valueTotals)), $this->percent($this->text('./*[local-name()="pTotTribFed"]', $percentTotals))]);
        $state = $this->firstNonEmpty([$this->money($this->text('./*[local-name()="vTotTribEst"]', $valueTotals)), $this->percent($this->text('./*[local-name()="pTotTribEst"]', $percentTotals))]);
        $municipal = $this->firstNonEmpty([$this->money($this->text('./*[local-name()="vTotTribMun"]', $valueTotals)), $this->percent($this->text('./*[local-name()="pTotTribMun"]', $percentTotals))]);
        $items[] = sprintf(
            'Totais Aproximados dos Tributos cfe. Lei nº 12.741/2012: Federais: %s ; Estaduais: %s ; Municipais: %s',
            $federal ?: '-', $state ?: '-', $municipal ?: '-'
        );
        return $items;
    }

    /** @param string[] $items */
    private function append(array &$items, string $label, string $value): void
    {
        if ($value !== '') {
            $items[] = $label . ': ' . $value;
        }
    }

    private function retainedDescription(string $type, string $pis, string $cofins): ?string
    {
        if ($type === '') {
            return null;
        }
        return [
            '0' => 'PIS/COFINS/CSLL Não Retidos',
            '1' => 'PIS/COFINS Retido',
            '2' => 'PIS/COFINS Não Retido',
            '3' => 'PIS/COFINS/CSLL Retidos',
            '4' => 'PIS/COFINS Retidos, CSLL Não Retido',
            '5' => 'PIS Retido, COFINS/CSLL Não Retido',
            '6' => 'COFINS Retido, PIS/CSLL Não Retido',
            '7' => 'PIS Não Retido, COFINS/CSLL Retidos',
            '8' => 'PIS/COFINS Não Retidos, CSLL Retido',
            '9' => 'COFINS Não Retido, PIS/CSLL Retidos',
        ][$type] ?? $type;
    }

    private function sameParty(?DOMElement $a, ?DOMElement $b): bool
    {
        return $a !== null && $b !== null && $this->document($a) !== '' && $this->document($a) === $this->document($b);
    }

    private function document(DOMElement $node): string
    {
        $value = $this->firstNonEmpty([$this->text('./*[local-name()="CNPJ"]', $node), $this->text('./*[local-name()="CPF"]', $node), $this->text('./*[local-name()="NIF"]', $node)]);
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) === 11) { return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $digits) ?? $value; }
        if (strlen($digits) === 14) { return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $digits) ?? $value; }
        return $value;
    }

    private function phone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) === 10) { return preg_replace('/(\d{2})(\d{4})(\d{4})/', '($1) $2-$3', $digits) ?? $value; }
        if (strlen($digits) === 11) { return preg_replace('/(\d{2})(\d{5})(\d{4})/', '($1) $2-$3', $digits) ?? $value; }
        return $value;
    }

    private function formatCep(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        return strlen($digits) === 8 ? (preg_replace('/(\d{2})(\d{3})(\d{3})/', '$1.$2-$3', $digits) ?? $value) : $value;
    }

    private function location(string $city, string $country, string $uf): string
    {
        return implode(' / ', array_filter([$city, $city !== '' ? $uf : '', $country], static fn ($value): bool => $value !== ''));
    }

    private function taxCode(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        return strlen($digits) === 6 ? substr($digits, 0, 2) . '.' . substr($digits, 2, 2) . '.' . substr($digits, 4, 2) : $value;
    }

    private function money(string $value): ?string
    {
        if (trim($value) === '') { return null; }
        return 'R$ ' . number_format((float) str_replace(',', '.', $value), 2, ',', '.');
    }

    private function decimal(string $value): float
    {
        return trim($value) === '' ? 0.0 : (float) str_replace(',', '.', $value);
    }

    private function moneyFromFloat(float $value): ?string
    {
        return $value === 0.0 ? null : 'R$ ' . number_format($value, 2, ',', '.');
    }

    private function percent(string $value): ?string
    {
        if (trim($value) === '') { return null; }
        return number_format((float) str_replace(',', '.', $value), 2, ',', '.') . '%';
    }

    private function date(string $value): string
    {
        if ($value === '') { return ''; }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
        return $date ? $date->format('d/m/Y') : $value;
    }

    private function year(string $value): ?int
    {
        return preg_match('/^(\d{4})-/', $value, $match) ? (int) $match[1] : null;
    }

    private function dateTime(string $value): string
    {
        if ($value === '') { return ''; }
        try { return (new DateTimeImmutable($value))->format('d/m/Y H:i:s'); }
        catch (\Exception) { return $value; }
    }

    /** @param array<string, string> $map */
    private function mapCode(string $value, array $map): string
    {
        return $value === '' ? '' : ($map[$value] ?? $value);
    }

    /** @param string[] $values */
    private function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) { if (trim((string) $value) !== '') { return trim((string) $value); } }
        return '';
    }

    private function text(string $query, ?DOMNode $context = null): string
    {
        $node = $this->node($query, $context);
        return $node ? trim($node->textContent) : '';
    }

    private function node(string $query, ?DOMNode $context = null): ?DOMElement
    {
        if ($context === null && str_starts_with(ltrim($query), '.')) { return null; }
        $nodes = $this->xpath->query($query, $context);
        $node = $nodes && $nodes->length ? $nodes->item(0) : null;
        return $node instanceof DOMElement ? $node : null;
    }
}
