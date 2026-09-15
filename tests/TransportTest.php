<?php

use Hadder\NfseNacional\Common\HttpResponse;
use Hadder\NfseNacional\Tools;

require_once __DIR__ . '/../src/Common/HttpResponse.php';
require_once __DIR__ . '/../src/RestCurl.php';
require_once __DIR__ . '/../src/Tools.php';

final class TransportToolsDouble extends Tools
{
    public string $postedBody = '';
    public bool $signed = false;
    public array $responses = [];

    public function postDataDetailed($operation, $data, $origin = 1): HttpResponse
    {
        $this->postedBody = $data;
        return array_shift($this->responses) ?? new HttpResponse(0, '', '', null, false, 0, '');
    }

    public function getDataDetailed($operation, $data = null, $origin = 1): HttpResponse
    {
        return array_shift($this->responses) ?? new HttpResponse(0, '', '', null, false, 0, '');
    }

    public function sign(string $content, string $tagname, ?string $mark, $rootname)
    {
        $this->signed = true;
        return $content . '<Signature>test</Signature>';
    }

    public function getOperation($operation)
    {
        return 'nfse';
    }
}

function transportResponse(int $status, string $body, string $headers = "X-Test: yes\r\n", int $errno = 0, string $error = ''): HttpResponse
{
    $json = json_decode($body, true);
    return new HttpResponse($status, $headers, $body, $json, json_last_error() === JSON_ERROR_NONE, $errno, $error);
}

$test('DPS já assinada preserva bytes UTF-8 no payload', function () use ($assert): void {
    $tools = (new ReflectionClass(TransportToolsDouble::class))->newInstanceWithoutConstructor();
    $signed = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<DPS>\n  <texto>São João — Descrição do serviço</texto>\n  <detalhe>ação ç ã é</detalhe>\n  <Signature>  abc  </Signature>\n</DPS>\n";
    $tools->responses[] = transportResponse(200, '{"ok":true}');
    $response = $tools->enviaDpsAssinado($signed);
    $payload = json_decode($tools->postedBody, true, 512, JSON_THROW_ON_ERROR);
    $decoded = gzdecode(base64_decode($payload['dpsXmlGZipB64'], true));
    $assert($decoded === $signed, 'bytes alterados');
    $assert(substr_count($decoded, '<Signature>') === 1, 'assinatura alterada');
    $assert($response->status === 200 && $response->jsonValid, 'resposta detalhada');
    $assert(!$tools->signed, 'enviaDpsAssinado não deve assinar');
});

$test('HttpResponse distingue vazio, null, inválido e JSON válido', function () use ($assert): void {
    $empty = transportResponse(500, '');
    $null = transportResponse(200, 'null');
    $invalid = transportResponse(200, '{invalido');
    $valid = transportResponse(200, '{"ok":true}');
    $assert($empty->isBodyEmpty() && !$empty->jsonValid && $empty->body === '');
    $assert(!$null->isBodyEmpty() && $null->jsonValid && $null->json === null);
    $assert(!$invalid->isBodyEmpty() && !$invalid->jsonValid && $invalid->body === '{invalido');
    $assert($valid->jsonValid && $valid->json['ok'] === true);
});

$test('POST detalhado preserva status, headers, body e JSON independentemente do status', function () use ($assert): void {
    $tools = (new ReflectionClass(TransportToolsDouble::class))->newInstanceWithoutConstructor();
    foreach ([
        [200, '{"ok":true}'], [400, '{"errors":["negado"]}'], [400, '{invalido'],
        [401, '{"error":"unauthorized"}'], [403, '{"error":"forbidden"}'],
        [429, '{"error":"rate-limit"}'], [500, ''],
    ] as [$status, $body]) {
        $tools->responses[] = transportResponse($status, $body, "X-Request: abc\r\n");
        $response = $tools->postDataDetailed('nfse', '{}');
        $assert($response->status === $status, "status {$status}");
        $assert($response->headers === "X-Request: abc\r\n", "headers {$status}");
        $assert($response->body === $body, "body {$status}");
        $assert($response->curlErrno === 0 && $response->curlError === '', "curl {$status}");
        $assert($status === 500 ? !$response->jsonValid : $response->jsonValid === ($body !== '{invalido'));
    }
});

$test('POST detalhado distingue erro cURL de HTTP 500 e timeout', function () use ($assert): void {
    $tools = (new ReflectionClass(TransportToolsDouble::class))->newInstanceWithoutConstructor();
    $tools->responses[] = transportResponse(0, '', '', 6, 'Could not resolve host');
    $curl = $tools->postDataDetailed('nfse', '{}');
    $assert($curl->status === 0 && $curl->curlErrno === 6 && $curl->curlError !== '');
    $tools->responses[] = transportResponse(0, '', '', CURLE_OPERATION_TIMEDOUT, 'Operation timed out');
    $timeout = $tools->postDataDetailed('nfse', '{}');
    $assert($timeout->status === 0 && $timeout->curlErrno === CURLE_OPERATION_TIMEDOUT && $timeout->curlError !== '');
});

$test('GET detalhado preserva respostas 200 e 404', function () use ($assert): void {
    $tools = (new ReflectionClass(TransportToolsDouble::class))->newInstanceWithoutConstructor();
    $tools->responses[] = transportResponse(200, '{"dps":true}', "X-Test: get\r\n");
    $tools->responses[] = transportResponse(404, '{"error":"not-found"}', "X-Test: get\r\n");
    $ok = $tools->getDataDetailed('dps/1');
    $notFound = $tools->getDataDetailed('dps/2');
    $assert($ok->status === 200 && $ok->jsonValid && $ok->json['dps'] === true);
    $assert($notFound->status === 404 && $notFound->body === '{"error":"not-found"}' && $notFound->jsonValid);
});

$test('consultas detalhadas retornam HttpResponse sem transformar o body', function () use ($assert): void {
    $tools = (new ReflectionClass(TransportToolsDouble::class))->newInstanceWithoutConstructor();
    $body = '{"nfseXmlGZipB64":"NAO_DESCOMPACTAR"}';
    $tools->responses[] = transportResponse(200, $body);
    $dps = $tools->consultarDpsChaveDetalhada('DPS-1');
    $tools->responses[] = transportResponse(200, $body);
    $nfse = $tools->consultarNfseChaveDetalhada('NFS-1');
    $assert($dps instanceof HttpResponse && $dps->body === $body);
    $assert($nfse instanceof HttpResponse && $nfse->body === $body);
});

$test('postData legado continua projetando JSON decodificado', function () use ($assert): void {
    $tools = (new ReflectionClass(TransportToolsDouble::class))->newInstanceWithoutConstructor();
    $tools->responses[] = transportResponse(400, '{"erro":"negado"}');
    $legacy = $tools->postData('nfse', '{}');
    $assert($legacy === ['erro' => 'negado']);
});

$test('getData legado preserva a peculiaridade de payload como POST', function () use ($assert): void {
    $source = (string) file_get_contents(__DIR__ . '/../src/RestCurl.php');
    $assert(str_contains($source, 'GET com payload era enviado como POST'));
});

$test('enviaDps legado continua assinando antes de transportar', function () use ($assert): void {
    $tools = (new ReflectionClass(TransportToolsDouble::class))->newInstanceWithoutConstructor();
    $tools->responses[] = transportResponse(200, '{"ok":true}');
    $result = $tools->enviaDps('<DPS><infDPS/></DPS>');
    $assert($tools->signed, 'enviaDps não chamou sign');
    $assert($result === ['ok' => true], 'retorno legado');
    $payload = json_decode($tools->postedBody, true, 512, JSON_THROW_ON_ERROR);
    $assert(str_contains((string) gzdecode(base64_decode($payload['dpsXmlGZipB64'], true)), '<Signature>test</Signature>'));
});
