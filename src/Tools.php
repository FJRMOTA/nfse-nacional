<?php

namespace Hadder\NfseNacional;

use NFePHP\Common\Certificate;
use Hadder\NfseNacional\Common\HttpResponse;

class Tools extends RestCurl
{
    public function __construct(string $config, Certificate $cert)
    {
        parent::__construct($config, $cert);
    }

    public function consultarNfseChave($chave, $encoding = true)
    {
        $operacao = str_replace("{chave}", $chave, $this->getOperation('consultar_nfse'));
        $retorno = $this->getData($operacao);

        if (isset($retorno['erro'])) {
            return $retorno;
        }

        if ($retorno) {
            $base_decode = base64_decode($retorno['nfseXmlGZipB64']);
            $gz_decode = gzdecode($base_decode);
            if ($gz_decode === false) {
                throw new \RuntimeException('Não foi possível descompactar o XML da NFS-e.');
            }
            if (!$encoding) {
                return $gz_decode;
            }
            // ADN returns the XML as ISO-8859-1 in this endpoint. Keep UTF-8
            // output for DOM/XML consumers and avoid converting an already UTF-8 document.
            if (preg_match('/encoding=["\']UTF-8["\']/i', substr($gz_decode, 0, 200))
                || mb_detect_encoding($gz_decode, ['UTF-8'], true) === 'UTF-8') {
                return $gz_decode;
            }
            return mb_convert_encoding($gz_decode, 'UTF-8', 'ISO-8859-1');
        }

        return null;
    }

    public function consultarDpsChave($chave)
    {
        $operacao = str_replace("{chave}", $chave, $this->getOperation('consultar_dps'));
        return $this->getData($operacao);
    }

    public function consultarNfseEventos($chave, $tipoEvento = null, $nSequencial = null)
    {
        $operacao = str_replace("{chave}", $chave, $this->getOperation('consultar_eventos'));

        if ($nSequencial !== null && $nSequencial !== '' && ($tipoEvento === null || $tipoEvento === '')) {
            throw new \InvalidArgumentException('nSequencial requer tipoEvento.');
        }
        if ($tipoEvento === null || $tipoEvento === '') {
            $operacao = str_replace('/{tipoEvento}/{nSequencial}', '', $operacao);
        } else {
            $operacao = str_replace('{tipoEvento}', rawurlencode((string) $tipoEvento), $operacao);
            if ($nSequencial === null || $nSequencial === '') {
                $operacao = str_replace('/{nSequencial}', '', $operacao);
            } else {
                $operacao = str_replace('{nSequencial}', rawurlencode((string) $nSequencial), $operacao);
            }
        }

        return $this->getData($operacao);
    }

    public function consultarDanfse($chave)
    {
        $operacao = str_replace("{chave}", $chave, $this->getOperation('consultar_danfse'));
        $retorno = $this->getData($operacao, null, 2);

        if (isset($retorno['erro'])) {
            return $retorno;
        }

        if ($retorno) {
            return $retorno;
        }

        if (empty($retorno)) {
            return $this->consultarDanfseNfse($chave);
        }

        return null;
    }

    /**
     * Consulta o DANFSe via NFSe caso o serviço direto falhe
     *
     * @param string $chave
     * @return array|null
     */
    public function consultarDanfseNfse($chave)
    {
        $operacao = $this->getOperation('consultar_danfse_nfse_certificado');
        $retorno = $this->getData($operacao, null, 3);

        if (isset($retorno) and isset($retorno['sucesso']) and $retorno['sucesso']) {
            $operacao = str_replace("{chave}", $chave, $this->getOperation('consultar_danfse_nfse_download'));
            $retorno = $this->getData($operacao, null, 3);
        }

        if (isset($retorno['erro'])) {
            return $retorno;
        }

        if ($retorno) {
            return $retorno;
        }

        return null;
    }

    public function enviaDps($content)
    {
        $content = $this->sign($content, 'infDPS', '', 'DPS');
        $content = '<?xml version="1.0" encoding="UTF-8"?>' . $content;

        $gz = gzencode($content);
        $data = base64_encode($gz);

        $dados = [
            'dpsXmlGZipB64' => $data
        ];

        $operacao = $this->getOperation('emitir_nfse');

        return $this->postData($operacao, json_encode($dados));
    }

    /** Envia bytes de uma DPS já assinada, sem tocar no conteúdo recebido. */
    public function enviaDpsAssinado(string $signedXml): HttpResponse
    {
        $dados = ['dpsXmlGZipB64' => base64_encode(gzencode($signedXml))];
        return $this->postDataDetailed($this->getOperation('emitir_nfse'), json_encode($dados, JSON_THROW_ON_ERROR));
    }

    public function consultarDpsChaveDetalhada($chave): HttpResponse
    {
        return $this->getDataDetailed(str_replace('{chave}', $chave, $this->getOperation('consultar_dps')));
    }

    public function consultarNfseChaveDetalhada($chave): HttpResponse
    {
        return $this->getDataDetailed(str_replace('{chave}', $chave, $this->getOperation('consultar_nfse')));
    }

    public function cancelaNfse($std)
    {
        $dps = new Dps($std);

        $content = $dps->renderEvento($std);
        $content = $this->sign($content, 'infPedReg', '', 'pedRegEvento');
        $content = '<?xml version="1.0" encoding="UTF-8"?>' . $content;

        $gz = gzencode($content);
        $data = base64_encode($gz);

        $dados = [
            'pedidoRegistroEventoXmlGZipB64' => $data
        ];

        $operacao = str_replace("{chave}", $std->infPedReg->chNFSe, $this->getOperation('cancelar_nfse'));

        return $this->postData($operacao, json_encode($dados));
    }
}
