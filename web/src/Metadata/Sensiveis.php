<?php

declare(strict_types=1);

namespace Elogica\Metadata;

/** Sugere quais campos provavelmente guardam dados pessoais (LGPD). É só sugestão: quem decide é a revisão humana. */
final class Sensiveis
{
    /** Trechos do nome do campo. */
    private const NOME = ['CPF', 'CGC', 'CNPJ', 'NOME', 'ENDER', 'LOGRAD', 'BAIRRO', 'CEP', 'FONE', 'TEL', 'CELUL', 'EMAIL', 'MAIL',
        'RENDA', 'SALAR', 'NASC', 'CONJUGE', 'CONJ_', 'PAI', 'MAE', 'RG_', 'IDENTID', 'PIS', 'PASEP', 'TITULO', 'CNH', 'NACIONAL'];

    /** Palavras na descrição (comparadas sem acento e em maiúsculas). */
    private const DESCRICAO = ['CPF', 'CNPJ', 'NOME DO', 'NOME DA', 'ENDERECO', 'LOGRADOURO', 'TELEFONE', 'E-MAIL', 'EMAIL', 'RENDA',
        'SALARIO', 'NASCIMENTO', 'CONJUGE', 'ESTADO CIVIL', 'IDENTIDADE', 'PROFISSAO'];

    /** Palavras que mostram que "nome"/"renda"/"salário" se refere a outra coisa (cidade, salário mínimo, arquivo...). */
    private const NAO_PESSOAL = ['CIDADE', 'MUNICIPIO', 'NUCLEO', 'BANCO', 'AGENCIA', 'ARQUIVO', 'PRODUTO', 'TABELA', 'CAMPO', 'MINIMO', 'PISO', 'GARAGEM', 'TAXA'];

    public static function sugerir(string $coluna, string $descricao): bool
    {
        $c = strtoupper($coluna);
        $d = self::semAcento(mb_strtoupper($descricao));
        foreach (self::NAO_PESSOAL as $p) {
            if (str_contains($d, $p) && !str_contains($d, 'CPF') && !str_contains($d, 'CNPJ')) {
                return false;
            }
        }
        foreach (self::NOME as $t) {
            if (str_contains($c, $t) && !self::falsoPositivo($c, $t)) {
                return true;
            }
        }
        foreach (self::DESCRICAO as $t) {
            if (str_contains($d, $t)) {
                return true;
            }
        }

        return false;
    }

    /** Trechos que aparecem dentro de palavras comuns e por isso só valem como palavra inteira. */
    private const AMBIGUOS = ['PAI', 'MAE', 'TEL', 'PIS', 'CEP', 'RG_'];

    /** Trechos curtos aparecem dentro de palavras comuns (PAI em "PAINEL", TEL em "HOTEL", PIS em "PISTA"...). */
    private static function falsoPositivo(string $coluna, string $trecho): bool
    {
        if (!in_array($trecho, self::AMBIGUOS, true)) {
            return false; // CPF, CGC, NOME, RENDA... não aparecem dentro de outras palavras
        }
        // só vale como palavra inteira entre separadores: NOME_PAI, PAI, CEP_ADQ
        return preg_match('/(^|[_\d])' . preg_quote($trecho, '/') . '($|[_\d])/', $coluna) !== 1;
    }

    private static function semAcento(string $s): string
    {
        return strtr($s, ['Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'É' => 'E', 'Ê' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ú' => 'U', 'Ç' => 'C']);
    }
}
