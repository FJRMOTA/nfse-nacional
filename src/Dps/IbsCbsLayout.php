<?php

namespace Hadder\NfseNacional\Dps;

use InvalidArgumentException;

final class IbsCbsLayout
{
    public const CURRENT = 'nt004-v1.01';
    public const NT009_V104 = 'nt009-v1.04';

    public static function assertSupported(string $profile): void
    {
        if (!in_array($profile, [self::CURRENT, self::NT009_V104], true)) {
            throw new InvalidArgumentException(sprintf('Perfil de leiaute DPS não suportado: %s.', $profile));
        }
    }
}
