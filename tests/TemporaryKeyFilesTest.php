<?php

use Hadder\NfseNacional\Common\RestBase;
use Hadder\NfseNacional\Tools;
use NFePHP\Common\Certificate;

final class TemporaryKeyFilesStream
{
    /** @var array<string, array{mode: int, content: string}> */
    public static array $files = [];
    /** @var array<string, true> */
    public static array $dirs = [];
    /** @var list<array{path: string, mode: int, pem: bool}> */
    public static array $writes = [];
    public $context;
    private string $path = '';

    public static function reset(): void
    {
        self::$files = [];
        self::$dirs = [];
        self::$writes = [];
    }

    private static function key(string $path): string
    {
        return rtrim(preg_replace('#(?<!:)/{2,}#', '/', $path), '/');
    }

    public function url_stat(string $path, int $flags)
    {
        $key = self::key($path);
        if (isset(self::$dirs[$key])) return ['mode' => 040700, 'size' => 0, 'mtime' => time()];
        if (isset(self::$files[$key])) return ['mode' => 0100000 | self::$files[$key]['mode'], 'size' => strlen(self::$files[$key]['content']), 'mtime' => time()];
        return false;
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        $key = self::key($path);
        while (str_contains($key, '/') && !str_ends_with($key, ':/')) {
            self::$dirs[$key] = true;
            $key = substr($key, 0, strrpos($key, '/'));
        }
        return true;
    }

    public function stream_open(string $path, string $mode): bool
    {
        $this->path = self::key($path);
        if ($mode[0] === 'w') {
            self::$files[$this->path] ??= ['mode' => 0644, 'content' => ''];
            self::$files[$this->path]['content'] = '';
            return true;
        }
        return isset(self::$files[$this->path]);
    }

    public function stream_write(string $data): int
    {
        self::$files[$this->path]['content'] .= $data;
        self::$writes[] = ['path' => $this->path, 'mode' => self::$files[$this->path]['mode'], 'pem' => str_contains($data, '-----BEGIN')];
        return strlen($data);
    }

    public function stream_read(int $count): string|false
    {
        return false;
    }

    public function stream_eof(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_stat(): array|false
    {
        return $this->url_stat($this->path, 0);
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        $key = self::key($path);
        if ($option === STREAM_META_TOUCH) {
            self::$files[$key] ??= ['mode' => 0644, 'content' => ''];
            return true;
        }
        if ($option === STREAM_META_ACCESS && isset(self::$files[$key])) {
            self::$files[$key]['mode'] = $value;
            return true;
        }
        return false;
    }

    public function unlink(string $path): bool
    {
        unset(self::$files[self::key($path)]);
        return true;
    }
}

$syntheticCertificate = static function (): Certificate {
    $config = tempnam(sys_get_temp_dir(), 'nfse-test-cnf');
    file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[ext]\nsubjectAltName=otherName:2.16.76.1.3.3;UTF8:11222333000181\n");
    try {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'config' => $config]);
        $csr = openssl_csr_new(['commonName' => 'TESTE SINTETICO:11222333000181'], $key, ['config' => $config]);
        $x509 = openssl_csr_sign($csr, null, $key, 2, ['config' => $config, 'x509_extensions' => 'ext']);
        openssl_pkcs12_export($x509, $pfx, $key, 'teste');
        return Certificate::readPfx($pfx, 'teste');
    } finally {
        unlink($config);
    }
};
$temporaryFolder = static function (): string {
    $folder = sys_get_temp_dir() . '/nfse-test-' . bin2hex(random_bytes(6)) . '/';
    mkdir($folder, 0700, true);
    return $folder;
};
$removeFolder = static function (string $folder): void {
    foreach (glob($folder . 'certs/*') ?: [] as $file) unlink($file);
    if (is_dir($folder . 'certs')) rmdir($folder . 'certs');
    if (is_dir($folder)) rmdir($folder);
};
$pemFiles = static fn (string $folder): array => glob($folder . 'certs/*.pem') ?: [];

$test('RestBase carregado do código do fork', function () use ($assert): void {
    $assert(str_starts_with((new ReflectionClass(RestBase::class))->getFileName(), realpath(__DIR__ . '/../src')), 'RestBase fora do fork');
});

$test('PEMs temporários existem enquanto Tools vive, com permissão 0600, e são removidos na destruição', function () use ($syntheticCertificate, $temporaryFolder, $removeFolder, $pemFiles, $assert): void {
    $folder = $temporaryFolder();
    try {
        $tools = new Tools(json_encode(['tpamb' => 2]), $syntheticCertificate());
        $tools->setTemporaryFolder($folder);
        $tools->saveTemporarilyKeyFiles();
        $files = $pemFiles($folder);
        $assert(count($files) === 3, 'devem existir 3 PEMs');
        $private = array_filter($files, static fn (string $file): bool => str_contains((string) file_get_contents($file), 'PRIVATE KEY'));
        $assert(count($private) === 2, 'chave privada em prifile e certfile');
        foreach ($files as $file) $assert((fileperms($file) & 0777) === 0600, 'permissão ' . decoct(fileperms($file) & 0777));
        $tools->saveTemporarilyKeyFiles();
        $assert($pemFiles($folder) === $files, 'segunda chamada não cria nem troca arquivos');
        unset($tools);
        gc_collect_cycles();
        $assert($pemFiles($folder) === [], 'arquivos devem ser removidos na destruição');
    } finally {
        $removeFolder($folder);
    }
});

$test('múltiplas instâncias não deixam resíduos', function () use ($syntheticCertificate, $temporaryFolder, $removeFolder, $pemFiles, $assert): void {
    $folder = $temporaryFolder();
    $certificate = $syntheticCertificate();
    try {
        for ($i = 0; $i < 3; $i++) {
            $tools = new Tools(json_encode(['tpamb' => 2]), $certificate);
            $tools->setTemporaryFolder($folder);
            $tools->saveTemporarilyKeyFiles();
            unset($tools);
        }
        gc_collect_cycles();
        $assert($pemFiles($folder) === [], count($pemFiles($folder)) . ' resíduos');
    } finally {
        $removeFolder($folder);
    }
});

$test('limpeza sem arquivos criados e limpeza repetida não geram erro', function () use ($syntheticCertificate, $temporaryFolder, $removeFolder, $pemFiles, $assert): void {
    $folder = $temporaryFolder();
    try {
        $empty = new Tools(json_encode(['tpamb' => 2]), $syntheticCertificate());
        $empty->removeTemporarilyFiles();
        unset($empty);
        $tools = new Tools(json_encode(['tpamb' => 2]), $syntheticCertificate());
        $tools->setTemporaryFolder($folder);
        $tools->saveTemporarilyKeyFiles();
        $tools->removeTemporarilyFiles();
        $tools->removeTemporarilyFiles();
        $assert($pemFiles($folder) === [], 'limpeza explícita remove os arquivos');
        unset($tools);
        gc_collect_cycles();
        $assert($pemFiles($folder) === [], 'destrutor após limpeza explícita é seguro');
    } finally {
        $removeFolder($folder);
    }
});

$test('conteúdo PEM só é escrito em arquivo que já está com permissão 0600', function () use ($syntheticCertificate, $assert): void {
    if (!in_array('nfsetmp', stream_get_wrappers(), true)) stream_wrapper_register('nfsetmp', TemporaryKeyFilesStream::class);
    TemporaryKeyFilesStream::reset();
    $tools = new Tools(json_encode(['tpamb' => 2]), $syntheticCertificate());
    $tools->setTemporaryFolder('nfsetmp://keys/');
    $tools->saveTemporarilyKeyFiles();
    $pemWrites = array_filter(TemporaryKeyFilesStream::$writes, static fn (array $write): bool => $write['pem']);
    $assert(count($pemWrites) >= 3, 'escritas PEM registradas: ' . count($pemWrites));
    foreach ($pemWrites as $write) $assert($write['mode'] === 0600, 'escrita PEM com permissão ' . decoct($write['mode']));
    $assert(count(TemporaryKeyFilesStream::$files) === 3, 'devem existir 3 arquivos');
    set_error_handler(static fn (): bool => true, E_WARNING);
    try {
        unset($tools);
        gc_collect_cycles();
    } finally {
        restore_error_handler();
    }
    $assert(TemporaryKeyFilesStream::$files === [], 'arquivos removidos na destruição');
});
