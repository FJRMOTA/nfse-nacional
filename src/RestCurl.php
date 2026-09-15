<?php

namespace Hadder\NfseNacional;

use Exception;
use Hadder\NfseNacional\Common\RestBase;
use NFePHP\Common\Certificate;
use NFePHP\Common\Exception\SoapException;
use NFePHP\Common\Signer;
use RuntimeException;
use Hadder\NfseNacional\Common\HttpResponse;

class RestCurl extends RestBase
{
    const DEFAULT_URLS = [
        "sefin_homologacao" => "https://sefin.producaorestrita.nfse.gov.br/SefinNacional",
        "sefin_producao" => "https://sefin.nfse.gov.br/sefinnacional",
        "adn_homologacao" => "https://adn.producaorestrita.nfse.gov.br",
        "adn_producao" => "https://adn.nfse.gov.br",
        "nfse_homologacao" => "https://www.producaorestrita.nfse.gov.br/EmissorNacional",
        "nfse_producao" => "https://www.nfse.gov.br/EmissorNacional"
    ];
    const DEFAULT_OPERATIONS = [
        "consultar_nfse" => "nfse/{chave}",
        "consultar_dps" => "dps/{chave}",
        "consultar_eventos" => "nfse/{chave}/eventos/{tipoEvento}/{nSequencial}",
        "consultar_danfse" => "danfse/{chave}",
        "consultar_danfse_nfse_certificado" => "Certificado",
        "consultar_danfse_nfse_download" => "Notas/Download/DANFSe/{chave}",
        "emitir_nfse" => "nfse",
        "cancelar_nfse" => "nfse/{chave}/eventos"
    ];
    private $urls = [];
    private $operations = [];
    private mixed $config;
    private string $url_api;
    private $connection_timeout = 30;
    private $timeout = 30;
    private $httpver;
    public string $soaperror;
    public int $soaperror_code;
    public array $soapinfo;
    public string $responseHead;
    public string $responseBody;
    private string $cookies = '';

    protected $canonical = [true, false, null, null];

    public function __construct(string $config, Certificate $cert)
    {
        parent::__construct($cert);
        $this->config = json_decode($config);
        $this->certificate = $cert;
        $configFile = __DIR__ . '/../storage/prefeituras.json';

        $this->loadConfigOverrides($configFile, $this->config->prefeitura ?? null);
    }

    private function loadConfigOverrides($jsonFile, $context): void
    {
        $json = json_decode(file_get_contents($jsonFile) ?: "", true);

        if (!is_array($json)) {
            throw new RuntimeException("JSON inválido em $jsonFile");
        }

        $contextData = $json[$context] ?? [];

        $this->urls = $this->mergeDefaults(self::DEFAULT_URLS, $contextData['urls'] ?? []);

        $this->operations = $this->mergeDefaults(self::DEFAULT_OPERATIONS, $contextData['operations'] ?? []);
    }

    private function mergeDefaults(array $defaults, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (array_key_exists($key, $defaults)) {
                $defaults[$key] = $value;
            }
        }
        return $defaults;
    }

    public function getOperation($operation)
    {
        return $this->operations[$operation];
    }

    /**
     * @param $operacao
     * @param $data
     * @param $origem - URL de consulta 1 = Sefin (emissão), 2 = ADN (DANFSe)
     * @return mixed|string
     */
    public function getData($operacao, $data = null, $origem = 1)
    {
        // Mantém a semântica histórica: GET com payload era enviado como POST.
        $response = $this->requestDetailed($data ? 'POST' : 'GET', $operacao, $data, $origem);
        if ($origem == 3 && $response->status === 302) {
            $this->captureCookies($this->responseHead, $origem);
            return ['sucesso' => true];
        }
        $contentType = $this->soapinfo['content_type'] ?? '';
        return $contentType === 'application/pdf' ? $this->responseBody : $response->json;
    }

    public function getDataDetailed($operacao, $data = null, $origem = 1): HttpResponse
    {
        return $this->requestDetailed('GET', $operacao, $data, $origem);
    }

    /**
     * @param $operacao
     * @param $data
     * @param $origem - URL de consulta 1 = Sefin (emissão), 2 = ADN (DANFSe)
     * @return mixed|string
     */
    public function postData($operacao, $data, $origem = 1)
    {
        return $this->postDataDetailed($operacao, $data, $origem)->json;
    }

    public function postDataDetailed($operacao, $data, $origem = 1): HttpResponse
    {
        return $this->requestDetailed('POST', $operacao, $data, $origem);
    }

    private function requestDetailed(string $method, $operacao, $data, $origem): HttpResponse
    {
        $this->resolveUrl($origem);
        $this->saveTemporarilyKeyFiles();
        try {
            $parameters = $method === 'POST'
                ? ['Content-Type: application/json', 'Content-length: ' . ($data ? strlen($data) : 0)]
                : ['Content-Type: application/json;charset=utf-8;', 'Content-length: ' . ($data ? strlen($data) : 0)];
            $curl = curl_init();
            $url = $this->url_api . (strlen($operacao) > 0 ? '/' . $operacao : '');
            curl_setopt_array($curl, [
                CURLOPT_URL => $url, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_CONNECTTIMEOUT => $this->connection_timeout, CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_HEADER => 1, CURLOPT_HTTP_VERSION => $this->httpver,
                CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_SSL_VERIFYPEER => 0,
                CURLOPT_SSLVERSION => CURL_SSLVERSION_DEFAULT,
                CURLOPT_SSLCERT => $this->tempdir . $this->certfile,
                CURLOPT_SSLKEY => $this->tempdir . $this->prifile,
                CURLOPT_KEYPASSWD => $this->temppass ?? '', CURLOPT_RETURNTRANSFER => 1,
            ]);
            if ($method === 'POST') {
                curl_setopt($curl, CURLOPT_POST, 1);
                curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
                curl_setopt($curl, CURLOPT_HTTPHEADER, $parameters);
            } elseif ($origem === 3 && !empty($this->cookies)) {
                $parameters[] = 'Cookie: ' . $this->cookies;
                curl_setopt($curl, CURLOPT_HTTPHEADER, $parameters);
            }
            $response = curl_exec($curl);
            $error = curl_error($curl); $errno = curl_errno($curl); $info = curl_getinfo($curl);
            $raw = is_string($response) ? $response : '';
            $headSize = (int) ($info['header_size'] ?? 0);
            $headers = substr($raw, 0, $headSize);
            $body = substr($raw, $headSize);
            $this->soaperror = $error; $this->soaperror_code = $errno; $this->soapinfo = $info;
            $this->responseHead = trim($headers); $this->responseBody = trim($body);
            $json = json_decode($body, true); $jsonValid = json_last_error() === JSON_ERROR_NONE;
            return new HttpResponse((int) ($info['http_code'] ?? 0), $headers, $body, $json, $jsonValid, $errno, $error);
        } catch (Exception $e) { throw SoapException::unableToLoadCurl($e->getMessage()); }
    }

    public function setTimeout($timeout)
    {
        $this->timeout = $timeout;
    }

    public function setConnectionTimeout($connection_timeout)
    {
        $this->connection_timeout = $connection_timeout;
    }

    /**
     * Sign XML passing in content
     * @param string $content
     * @param string $tagname
     * @param string|null $mark
     * @param $rootname
     * @return string XML signed
     */
    public function sign(string $content, string $tagname, ?string $mark, $rootname)
    {
        if (empty($mark)) {
            $mark = 'Id';
        }
        $xml = Signer::sign(
            $this->certificate,
            $content,
            $tagname,
            $mark,
            OPENSSL_ALGO_SHA1,
            $this->canonical,
            $rootname
        );
        return $xml;
    }

    private function resolveUrl(int $origem = 0)
    {
        switch ($origem) {
            case 1: // SEFIN
                $this->url_api = $this->urls['sefin_homologacao'];
                if ($this->config->tpamb === 1) {
                    $this->url_api = $this->urls['sefin_producao'];
                }
                break;
            case 2: // ADN
                $this->url_api = $this->urls['adn_homologacao'];
                if ($this->config->tpamb === 1) {
                    $this->url_api = $this->urls['adn_producao'];
                }
                break;
            case 3: // NFSE
                $this->url_api = $this->urls['nfse_homologacao'];
                if ($this->config->tpamb === 1) {
                    $this->url_api = $this->urls['nfse_producao'];
                }
                break;
        }

    }

    private function captureCookies(string $headers, int $origem): void
    {
        if ($origem !== 3) {
            return;
        }
        if (!preg_match_all('/^Set-Cookie:\s*([^;\r\n]*)/mi', $headers, $matches)) {
            return;
        }
        $cookies = array_map('trim', $matches[1]);
        if (!empty($cookies)) {
            $this->cookies = implode('; ', $cookies);
        }
    }
}
