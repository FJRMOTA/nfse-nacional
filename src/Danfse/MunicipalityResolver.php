<?php

namespace Hadder\NfseNacional\Danfse;

final class MunicipalityResolver
{
    /** @var array<string, array{0:string,1:string}>|null */
    private static ?array $municipalities = null;

    /** @return array{name:string,uf:string} */
    public static function resolve(string $code): array
    {
        if ($code === '') return ['name' => '', 'uf' => ''];
        if (self::$municipalities === null) {
            $path = dirname(__DIR__, 2) . '/storage/municipios-ibge.json';
            $json = file_get_contents($path);
            $data = is_string($json) ? json_decode($json, true) : null;
            self::$municipalities = is_array($data['municipalities'] ?? null) ? $data['municipalities'] : [];
        }
        $entry = self::$municipalities[$code] ?? ['', ''];
        return ['name' => (string) ($entry[0] ?? ''), 'uf' => (string) ($entry[1] ?? '')];
    }
}
