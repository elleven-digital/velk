<?php

declare(strict_types=1);

namespace Velk\Services;

/**
 * Conversão e formatação de dinheiro. Puro, sem dependências.
 *
 * O painel guarda o preço como o usuário digita (reais, ex. "29,90").
 * O Stripe trabalha em centavos (a menor unidade da moeda). toCents()
 * faz a ponte de forma tolerante a formatos pt-BR e en-US.
 */
final class Money
{
    /**
     * Converte um valor humano (reais) em centavos inteiros.
     * Aceita "29,90", "29.90", "1.234,56", "1,234.56", "R$ 59,90",
     * floats e ints. Retorna null para vazio/indecifrável.
     */
    public static function toCents(string|int|float|null $amount): ?int
    {
        if ($amount === null) {
            return null;
        }
        if (is_int($amount)) {
            return $amount * 100;
        }
        if (is_float($amount)) {
            return (int) round($amount * 100);
        }

        // Mantém só dígitos, separadores e sinal de menos.
        $s = preg_replace('/[^0-9.,-]/', '', trim($amount)) ?? '';
        if ($s === '' || $s === '-') {
            return null;
        }

        $hasComma = str_contains($s, ',');
        $hasDot   = str_contains($s, '.');

        if ($hasComma && $hasDot) {
            // O separador mais à direita é o decimal; o outro é milhar.
            if (strrpos($s, ',') > strrpos($s, '.')) {
                $s = str_replace('.', '', $s);   // pontos = milhar
                $s = str_replace(',', '.', $s);  // virgula = decimal
            } else {
                $s = str_replace(',', '', $s);   // virgulas = milhar
            }
        } elseif ($hasComma) {
            $s = str_replace(',', '.', $s);      // virgula = decimal
        } elseif ($hasDot && preg_match('/^-?\d{1,3}(\.\d{3})+$/', $s) === 1) {
            // Ponto solitário (sem vírgula) em notação de milhar pt-BR,
            // ex. "1.500" (mil e quinhentos). Ninguém escreve preço com
            // exatamente 3 casas decimais, então isso é inequívoco.
            $s = str_replace('.', '', $s);
        }
        // so ponto decimal, ou inteiro puro: ja esta no formato certo.

        if (!is_numeric($s)) {
            return null;
        }
        return (int) round(((float) $s) * 100);
    }

    /** Formata centavos para exibição. BRL usa pt-BR; o resto, genérico. */
    public static function format(int $cents, string $currency): string
    {
        $currency = strtoupper(trim($currency)) ?: 'BRL';
        $value = $cents / 100;
        if ($currency === 'BRL') {
            return 'R$ ' . number_format($value, 2, ',', '.');
        }
        return $currency . ' ' . number_format($value, 2, '.', ',');
    }
}
