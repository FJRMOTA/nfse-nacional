# Schemas RTC utilizados

## Perfil atualmente implantado

Os testes do perfil `nt004-v1.01` usam o conjunto XSD de Produção atualmente
publicado no Portal NFS-e. A página oficial de Documentação Atual foi
atualizada em 15/08/2026 e referencia o pacote:

<https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual/nfse-esquemas_xsd-v1-01-20260209.zip>

SHA-256 do pacote: `e7935cbd9470527c6cc32984c1b2263e614183bf0139ce2733eaaed2de9a8072`.
Os arquivos efetivamente referenciados são `DPS_v1.01.xsd`,
`tiposComplexos_v1.01.xsd`, `tiposSimples_v1.01.xsd` e
`xmldsig-core-schema.xsd`. Seus SHA-256 são, respectivamente:

```text
fe45e5250a48e519aba89fc6a472863b8e602ed957778fb64692804933a00d0c
e8e09d525574cc224ca6d1f8d8eb0366043ab2a3aa8d0d234058e6244d60e371
830ea116c34d7310699e34b214b7214a65f7e3d5b1f09aeaa702f7e3f4283b17
49848f732663aecb618d72ad6130c5c3240f0a10f3a1a8544b7d48a6c726046f
```

Embora os filenames permaneçam `v1.01`, este pacote não é o snapshot de
10/12/2025: há alterações de conteúdo em tipos simples e complexos, incluindo
`tpRetPisCofins`, campos opcionais adicionais e regras de identificadores.
O tipo `TSCNPJ` do pacote de Produção, entretanto, ainda é `[0-9]{14}`.

### Validação em runtime

`Dps::validate($xml)` valida a DPS contra o XSD do perfil (`IbsCbsLayout::SCHEMAS`)
sem alterar o arquivo oficial. O `TSSerieDPS` oficial declara o pattern
`^0{0,4}\d{1,5}$`; em XML Schema `^` e `$` são caracteres literais, então nenhuma
série numérica é aceita pelo libxml. Somente esse erro é descartado, e apenas
quando o valor da série atende ao pattern pretendido (`^0{0,4}\d{1,5}$` com
âncoras). Qualquer outro erro, inclusive o `maxLength` da série, é devolvido.
`Dps::getErrors()` expõe os campos obrigatórios vazios acumulados no render.

## Produção Restrita / homologação — CNPJ alfanumérico

O comunicado oficial de 28/07/2026 informa a disponibilização em 27/07/2026
dos schemas atualizados para CNPJ alfanumérico. O pacote oficial é:

<https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/producao-restrita/esquemas-nfse-rtc-v1-01-20260727.zip>

SHA-256 do pacote: `6c7e0510d3ecff4454f291f4e10b742d27a4818f23aab181494f96d0ea79f3dc`.
Ele mantém os filenames `DPS_v1.01.xsd`, `tiposComplexos_v1.01.xsd` e
`tiposSimples_v1.01.xsd`; os tipos simples relacionados a CNPJ e às
chaves/identificadores passam a aceitar `[0-9A-Z]`. O `DPS_v1.01.xsd` e os
tipos complexos permanecem estruturalmente equivalentes ao pacote de Produção.
Esse pacote é referência de homologação e não é usado para validar
`LAYOUT_CURRENT`.

## Perfil preparado NT-009 v1.04

O Portal publicou o leiaute consolidado no Anexo VI v1.04.00:

<https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc/anexovi-leiautesrn_rtc_ibscbs-v1-04-00-2013-nt009.xlsx>

Até a data desta implementação, o Portal não publicou um pacote XSD v1.04
correspondente. Por isso o perfil `nt009-v1.04` possui testes estruturais e
não é validado contra o XSD v1.01.

## Municípios

`storage/municipios-ibge.json` é um snapshot local da API oficial de
Localidades do IBGE, obtido em 11/09/2026. A geração do DANFSe não consulta
rede.

## Extensões de imagem

GD/Imagick não é requisito da emissão da DPS. O TCPDF exige uma dessas
extensões apenas quando o `DanfsePdf` carrega o logo PNG RGBA; a verificação é
feita no momento da geração do DANFSe. A dependência Composer é uma sugestão,
não um requisito global do pacote.
