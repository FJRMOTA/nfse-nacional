<?php

use Hadder\NfseNacional\Common\HttpResponse;
use Hadder\NfseNacional\Tools;

require_once __DIR__ . '/../src/Common/HttpResponse.php';
require_once __DIR__ . '/../src/RestCurl.php';
require_once __DIR__ . '/../src/Tools.php';

final class TransportToolsDouble extends Tools
{
    public string $postedBody = '';
    public function postDataDetailed($operation, $data, $origin = 1): HttpResponse
    {
        $this->postedBody = $data;
        return new HttpResponse(200, "X-Test: yes\r\n", '{"ok":true}', ['ok' => true], true, 0, '');
    }
    public function getDataDetailed($operation, $data = null, $origin = 1): HttpResponse
    {
        return new HttpResponse(404, "", '', null, false, 0, '');
    }
    public function getOperation($operation)
    {
        return 'nfse';
    }
}

$test('DPS já assinada preserva bytes no payload', function () use ($assert): void {
    $tools = (new ReflectionClass(TransportToolsDouble::class))->newInstanceWithoutConstructor();
    $signed = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<DPS>  <Signature>abc</Signature>\n</DPS>\n";
    $response = $tools->enviaDpsAssinado($signed);
    $payload = json_decode($tools->postedBody, true, 512, JSON_THROW_ON_ERROR);
    $assert(gzdecode(base64_decode($payload['dpsXmlGZipB64'], true)) === $signed, 'bytes alterados');
    $assert($response->status === 200 && $response->jsonValid, 'resposta detalhada');
});

$test('resposta detalhada distingue body vazio de JSON null e inválido', function () use ($assert): void {
    $empty = new HttpResponse(204, '', '', null, false, 0, '');
    $null = new HttpResponse(200, '', 'null', null, true, 0, '');
    $invalid = new HttpResponse(200, '', '{', null, false, 0, '');
    $assert($empty->isBodyEmpty() && !$empty->jsonValid, 'vazio');
    $assert(!$null->isBodyEmpty() && $null->jsonValid, 'null');
    $assert(!$invalid->isBodyEmpty() && !$invalid->jsonValid, 'inválido');
});
