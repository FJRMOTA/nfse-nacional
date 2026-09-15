<?php

namespace Hadder\NfseNacional\Common;

/** Resultado imutável de uma operação HTTP, sem interpretação fiscal. */
final readonly class HttpResponse
{
    public function __construct(
        public int $status,
        public string $headers,
        public string $body,
        public mixed $json,
        public bool $jsonValid,
        public int $curlErrno,
        public string $curlError,
    ) {
    }

    public function isBodyEmpty(): bool
    {
        return $this->body === '';
    }
}
