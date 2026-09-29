<?php

declare(strict_types=1);

namespace Elogica\Admin;

/** Coleta os arquivos HTML enviados (soltos ou dentro de um .zip) sem gravar nada em disco. */
final class DocUpload
{
    public const MAX_ARQUIVO = 8 * 1024 * 1024;
    public const MAX_TOTAL = 200 * 1024 * 1024;
    public const MAX_ENTRADAS = 5000;

    /**
     * @param array<string, mixed> $files conteúdo de $_FILES['arquivos'] (múltiplo)
     * @return array{arquivos: list<array{nome: string, bytes: string}>, erros: list<string>}
     */
    public static function coletar(array $files): array
    {
        $arquivos = [];
        $erros = [];
        $total = 0;
        foreach (self::normalizar($files) as $f) {
            if ($f['error'] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($f['error'] !== UPLOAD_ERR_OK) {
                $erros[] = $f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE
                    ? "{$f['name']}: maior que o limite de upload do servidor."
                    : "{$f['name']}: falha no envio.";
                continue;
            }
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if ($ext === 'zip') {
                foreach (self::lerZip($f['tmp_name'], $total, $erros) as $a) {
                    $arquivos[] = $a;
                }
            } elseif ($ext === 'htm' || $ext === 'html') {
                $bytes = self::lerSolto($f['tmp_name'], $f['size']);
                if ($bytes === null) {
                    $erros[] = "{$f['name']}: arquivo grande demais.";
                    continue;
                }
                $total += strlen($bytes);
                $arquivos[] = ['nome' => basename($f['name']), 'bytes' => $bytes];
            } else {
                $erros[] = "{$f['name']}: use arquivos .htm, .html ou .zip.";
            }
        }

        return ['arquivos' => $arquivos, 'erros' => $erros];
    }

    /** @param array<string, mixed> $files @return list<array{name: string, tmp_name: string, error: int, size: int}> */
    private static function normalizar(array $files): array
    {
        if (!isset($files['name'])) {
            return [];
        }
        $out = [];
        foreach ((array) $files['name'] as $i => $nome) {
            $out[] = [
                'name' => (string) $nome,
                'tmp_name' => (string) ($files['tmp_name'][$i] ?? ''),
                'error' => (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($files['size'][$i] ?? 0),
            ];
        }

        return $out;
    }

    private static function lerSolto(string $caminho, int $tamanho): ?string
    {
        if ($tamanho > self::MAX_ARQUIVO) {
            return null;
        }
        $b = file_get_contents($caminho);

        return $b === false ? null : $b;
    }

    /**
     * @param list<string> $erros
     * @return list<array{nome: string, bytes: string}>
     */
    private static function lerZip(string $caminho, int &$total, array &$erros): array
    {
        if (!class_exists(\ZipArchive::class)) {
            $erros[] = 'O servidor não tem a extensão zip do PHP habilitada (extension=zip).';

            return [];
        }
        $zip = new \ZipArchive();
        if ($zip->open($caminho) !== true) {
            $erros[] = 'Não foi possível abrir o .zip.';

            return [];
        }
        $out = [];
        if ($zip->numFiles > self::MAX_ENTRADAS) {
            $erros[] = 'O .zip tem arquivos demais (limite ' . self::MAX_ENTRADAS . ').';
            $zip->close();

            return [];
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            if ($st === false || str_ends_with($st['name'], '/') || str_contains($st['name'], '__MACOSX')) {
                continue;
            }
            if (preg_match('/\.html?$/i', $st['name']) !== 1) {
                continue;
            }
            if ($st['size'] > self::MAX_ARQUIVO) {
                $erros[] = basename($st['name']) . ': arquivo grande demais no .zip.';
                continue;
            }
            if ($total + $st['size'] > self::MAX_TOTAL) {
                $erros[] = 'O conteúdo do .zip passa do limite total; parte dos arquivos foi ignorada.';
                break;
            }
            $bytes = $zip->getFromIndex($i);
            if ($bytes === false) {
                continue;
            }
            $total += strlen($bytes);
            $out[] = ['nome' => basename(str_replace('\\', '/', $st['name'])), 'bytes' => $bytes];
        }
        $zip->close();

        return $out;
    }
}
