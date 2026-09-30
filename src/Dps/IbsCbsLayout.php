<?php

namespace Hadder\NfseNacional\Dps;

use InvalidArgumentException;

final class IbsCbsLayout
{
    public const CURRENT = 'nt004-v1.01';
    public const NT009_V104 = 'nt009-v1.04';

    /** XSD oficial publicado para cada perfil; NT-009 v1.04 ainda não possui pacote XSD. */
    public const SCHEMAS = [self::CURRENT => 'DPS_v1.01.xsd'];

    public static function assertSupported(string $profile): void
    {
        if (!in_array($profile, [self::CURRENT, self::NT009_V104], true)) {
            throw new InvalidArgumentException(sprintf('Perfil de leiaute DPS não suportado: %s.', $profile));
        }
    }
}
