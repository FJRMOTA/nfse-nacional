<?php

namespace Hadder\NfseNacional\Danfse;

final class DanfseTemplate
{
    /** @param array<string, mixed> $data */
    public static function html(array $data): string
    {
        $h = static fn ($value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $show = static fn ($value): string => trim((string) ($value ?? '')) === '' ? '-' : (string) $value;
        $party = static fn (array $value, string $title): string => self::party($value, $title, $h, $show);
        $service = $data['service'];
        $municipal = $data['municipal_tax'];
        $federal = $data['federal_tax'];
        $ibsCbs = $data['ibs_cbs'];
        $totals = $data['totals'];
        $logo = __DIR__ . '/assets/logo_nfse_horizontal.png';
        $homologation = $data['homologation']
            ? '<div class="invalid">NFS-e SEM VALIDADE JURÍDICA</div>'
            : '';
        $municipality = $data['show_municipality'] ? 'Município: ' . $show($data['municipality']) . '<br>' : '';

        $customer = $data['customer_identified']
            ? $party($data['customer'], 'TOMADOR/ADQUIRENTE DA OPERAÇÃO')
            : self::notice('TOMADOR/ADQUIRENTE DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e');
        if ($data['recipient_is_customer']) {
            $recipient = self::notice('O DESTINATÁRIO É O PRÓPRIO TOMADOR/ADQUIRENTE DA OPERAÇÃO');
        } elseif ($data['recipient_identified']) {
            $recipient = $party($data['recipient'], 'DESTINATÁRIO DA OPERAÇÃO', false);
        } else {
            $recipient = self::notice('DESTINATÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e');
        }
        $intermediary = $data['intermediary_identified']
            ? $party($data['intermediary'], 'INTERMEDIÁRIO DA OPERAÇÃO')
            : self::notice('INTERMEDIÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e');
        $provider = $party($data['provider'], 'PRESTADOR / FORNECEDOR') . self::row([
            ['Simples Nacional na Data de Competência', $data['simple_national']],
            ['Regime de Apuração Tributária pelo SN', $data['simple_assessment']],
        ], $h, $show);

        $municipalBlock = $municipal['applicable']
            ? self::municipalTax($municipal, $h, $show)
            : self::notice('TRIBUTAÇÃO MUNICIPAL (ISSQN) - OPERAÇÃO NÃO SUJEITA AO ISSQN');

        $federalOptional = '';
        if (($data['competence_year'] ?? 9999) <= 2026) {
            $federalOptional = self::row([
                ['PIS - Débito Apuração Própria', $federal['pis']],
                ['COFINS - Débito Apuração Própria', $federal['cofins']],
                ['Descrição Contrib. Sociais - Retidas', $federal['retained_description']],
            ], $h, $show);
        }

        $ibsCbsBlock = '';
        if ($ibsCbs['applicable']) {
            $ibsCbsBlock = '<div class="block">TRIBUTAÇÃO IBS / CBS</div>' . self::row([
                ['CST / cClassTrib', $ibsCbs['classification']], ['Indicador de Operação / Incidência', trim($ibsCbs['operation'] . ' / ' . $ibsCbs['incidence'], ' /')],
                ['Exclusões e Reduções da BC', $ibsCbs['exclusions']], ['Base após Exclusões e Reduções', $ibsCbs['base']],
            ], $h, $show) . self::row([
                ['Red. Alíquota IBS UF / IBS Mun / CBS', $ibsCbs['reduction_rates']], ['Alíquota IBS UF / IBS Mun', $ibsCbs['rates']],
                ['Alíquota Efetiva Municipal / IBS Mun', trim($ibsCbs['effective_municipal_rate'] . ' / ' . $ibsCbs['municipal_amount'], ' /')],
                ['Alíquota Efetiva Estadual / IBS UF', trim($ibsCbs['effective_state_rate'] . ' / ' . $ibsCbs['state_amount'], ' /')],
            ], $h, $show) . self::row([
                ['Valor Total IBS', $ibsCbs['ibs_amount']], ['Alíquota / Alíquota Efetiva CBS', trim($ibsCbs['cbs_rate'] . ' / ' . $ibsCbs['effective_cbs_rate'], ' /')],
                ['Valor Total CBS', $ibsCbs['cbs_amount']], ['Diferimento IBS UF / Mun / CBS', trim(($ibsCbs['state_differment'] ?? '') . ' / ' . ($ibsCbs['municipal_differment'] ?? '') . ' / ' . ($ibsCbs['cbs_differment'] ?? ''), ' /')],
            ], $h, $show);
        }

        $additionalItems = $data['additional_information'];
        $taxTotals = (string) array_pop($additionalItems);
        $separator = $additionalItems ? ' | ' : '';
        $available = max(0, 2000 - mb_strlen($separator . $taxTotals));
        $additional = self::truncate(implode(' | ', $additionalItems), $available) . $separator . $taxTotals;

        return '<style>
            body { font-family: dejavusans, sans-serif; font-size: 7pt; color:#000; }
            table { border-collapse:collapse; width:100%; }
            td { border:0.5pt solid #000; padding:1.1mm; vertical-align:top; line-height:1.05; }
            .frame { border:1pt solid #000; }
            .frame td { border:0; }
            .head { background-color:#f2f2f2; }
            .block { background-color:#f2f2f2; border:0.5pt solid #000; font-family:helvetica,sans-serif; font-size:7pt; font-weight:bold; padding:0.7mm; }
            .label { font-family:helvetica,sans-serif; font-size:6pt; font-weight:bold; }
            .idlabel { font-family:helvetica,sans-serif; font-size:7pt; font-weight:bold; }
            .center { text-align:center; }
            .invalid { color:#ed1c24; font-family:helvetica,sans-serif; font-size:9pt; font-weight:bold; text-align:center; }
            .gap { height:0.7mm; line-height:0.7mm; }
            .description { line-height:1.0; }
        </style>
        <table class="frame head" cellpadding="0"><tr>
          <td style="width:20%"><img src="' . $h($logo) . '" width="115"></td>
          <td style="width:55%" class="center"><b>DANFSe v2.0</b><br><b>Documento Auxiliar da NFS-e</b>' . $homologation . '</td>
          <td style="width:25%;font-size:6pt">' . $municipality . 'Ambiente gerador: ' . $h($show($data['generator'])) . '<br>Ambiente: ' . $h($show($data['environment_label'])) . '</td>
        </tr></table>
        <table cellpadding="0"><tr><td style="width:75%;padding:0">
          <table cellpadding="0"><tr><td colspan="5"><span class="idlabel">CHAVE DE ACESSO DA NFS-e</span><br>' . $h($data['key']) . '</td></tr><tr>' .
            self::cell('NÚMERO DA NFS-e', $data['number'], 20, $h, $show, true) .
            self::cell('COMPETÊNCIA DA NFS-e', $data['competence'], 20, $h, $show, true) .
            self::cell('DATA E HORA DA EMISSÃO DA NFS-e', $data['nfse_issued_at'], 20, $h, $show, true) .
            self::cell('NÚMERO DA DPS', $data['dps_number'], 20, $h, $show, true) .
            self::cell('SÉRIE DA DPS', $data['dps_series'], 20, $h, $show, true) . '</tr><tr>' .
            self::cell('DATA E HORA DA EMISSÃO DA DPS', $data['dps_issued_at'], 25, $h, $show, true) .
            self::cell('EMITENTE DA NFS-e', $data['issuer'], 25, $h, $show, true) .
            self::cell('SITUAÇÃO DA NFS-e', self::truncate($data['status'], 40), 25, $h, $show, true) .
            self::cell('FINALIDADE', self::truncate($data['purpose'], 40), 25, $h, $show, true) .
          '</tr></table></td><td style="width:25%;height:31mm"></td></tr></table>' .
        $provider . $customer . $recipient . $intermediary .
        '<div class="block">SERVIÇO PRESTADO</div><table cellpadding="0"><tr>' .
            self::cell('CÓDIGO DE TRIBUTAÇÃO NACIONAL / MUNICIPAL', trim($service['national_code'] . ($service['municipal_code'] !== '' ? ' / ' . $service['municipal_code'] : '')), 32, $h, $show) .
            self::cell('CÓDIGO NBS', $service['nbs'], 18, $h, $show) .
            self::cell('LOCAL DA PRESTAÇÃO / SIGLA UF / PAÍS', $service['location'], 50, $h, $show) .
        '</tr><tr><td colspan="3">' . $h(self::truncate($service['code_description'], 170)) . '</td></tr>
        <tr><td colspan="3" class="description"><span class="label">DESCRIÇÃO DO SERVIÇO</span><br>' . nl2br($h(self::truncate($service['description'], 1300))) . '</td></tr></table>' .
        $municipalBlock .
        '<div class="block">TRIBUTAÇÃO FEDERAL (EXCETO CBS)</div>' . self::row([
            ['IRRF', $federal['irrf']], ['Contribuição Previdenciária - Retida', $federal['social_security']],
            ['Contribuições Sociais - Retidas', $federal['social_contributions']],
        ], $h, $show) . $federalOptional . $ibsCbsBlock .
        '<div class="block">VALOR TOTAL DA NFS-e</div>' . self::row([
            ['Valor da Operação / Serviço', $totals['service']], ['Desconto Incondicionado', $totals['unconditional_discount']],
            ['Desconto Condicionado', $totals['conditional_discount']], ['Total das Retenções (ISSQN / Federais)', $totals['withholdings']],
            ['Valor Líquido da NFS-e', $totals['net']], ['Total do IBS/CBS', $ibsCbs['total'] ?? null],
            ['Valor Líquido + IBS/CBS', $ibsCbs['net_with_taxes'] ?? null],
        ], $h, $show) .
        '<div class="block">INFORMAÇÕES COMPLEMENTARES</div><table cellpadding="0"><tr><td class="description">' . nl2br($h($additional)) . '</td></tr></table>';
    }

    /** @param array<string, mixed> $data */
    private static function party(array $data, string $title, callable $h, callable $show, bool $withIm = true): string
    {
        $first = [['CNPJ / CPF / NIF', $data['document'] ?? null]];
        if ($withIm) { $first[] = ['Indicador Municipal (Inscrição)', $data['municipal_registration'] ?? null]; }
        $first[] = ['Telefone', $data['phone'] ?? null];
        return '<div class="block">' . $h($title) . '</div>' . self::row($first, $h, $show) . self::row([
            ['Nome / Nome Empresarial', self::truncate($data['name'] ?? '', 80)],
            ['Município / Sigla UF', $data['city'] ?? null], ['Código IBGE / CEP', $data['ibge_postal'] ?? null],
        ], $h, $show) . self::row([
            ['Endereço', self::truncate($data['address'] ?? '', 80)], ['E-mail', $data['email'] ?? null],
        ], $h, $show);
    }

    private static function municipalTax(array $data, callable $h, callable $show): string
    {
        $optional = array_filter([
            ['Regime Especial de Tributação do ISSQN', $data['special_regime']], ['Tipo de Imunidade do ISSQN', $data['immunity']],
            ['Suspensão da Exigibilidade do ISSQN', $data['suspension']], ['Número Processo Suspensão', $data['suspension_process']],
            ['Benefício Municipal', $data['benefit']], ['Cálculo do BM', $data['benefit_calculation']],
            ['Total Deduções/Reduções', $data['deductions']], ['Desconto Incondicionado', $data['unconditional_discount']],
        ], static fn (array $field): bool => $field[1] !== null && $field[1] !== '');
        return '<div class="block">TRIBUTAÇÃO MUNICIPAL (ISSQN)</div>' . self::row([
            ['Tipo de Tributação do ISSQN', $data['type']], ['Município / Sigla UF / País da Incidência do ISSQN', $data['incidence']],
        ], $h, $show) . ($optional ? self::row(array_values($optional), $h, $show) : '') . self::row([
            ['BC ISSQN', $data['base']], ['Alíquota Aplicada', $data['rate']],
            ['Retenção do ISSQN', $data['withholding']], ['ISSQN Apurado', $data['amount']],
        ], $h, $show);
    }

    private static function notice(string $text): string
    {
        return '<div class="block">' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
    }

    /** @param array<int, array{0:string,1:mixed}> $fields */
    private static function row(array $fields, callable $h, callable $show): string
    {
        $width = 100 / max(1, count($fields));
        $html = '<table cellpadding="0"><tr>';
        foreach ($fields as [$label, $value]) { $html .= self::cell($label, $value, $width, $h, $show); }
        return $html . '</tr></table>';
    }

    private static function cell(string $label, mixed $value, float $width, callable $h, callable $show, bool $identity = false): string
    {
        return '<td style="width:' . $width . '%"><span class="' . ($identity ? 'idlabel' : 'label') . '">' . $h($label) . '</span><br>' . $h($show($value)) . '</td>';
    }

    private static function truncate(string $value, int $max): string
    {
        if ($max <= 3) { return mb_substr($value, 0, $max); }
        return mb_strlen($value) <= $max ? $value : rtrim(mb_substr($value, 0, $max - 3)) . '...';
    }
}
