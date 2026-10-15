<?php namespace Vmod\Erp\Classes;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Math\Exception\RoundingNecessaryException;
use October\Rain\Exception\ValidationException;

class DocumentTotals
{
    public static function decimal($value, string $field, bool $positive = false, string $maximum = '999999999999999.9999'): string
    {
        if (!is_string($value) || !preg_match('/^\d{1,15}(?:\.\d{1,4})?$/D', $value)) {
            throw new ValidationException([$field => 'Enter a decimal number with at most four decimal places.']);
        }
        $number = BigDecimal::of($value);
        if (($positive && $number->isZero()) || $number->isGreaterThan($maximum)) {
            throw new ValidationException([$field => $positive ? 'Enter a positive number within the supported range.' : 'Number exceeds the supported range.']);
        }
        return (string) $number->toScale(4);
    }

    public static function baseQuantity($quantity, $conversion): string
    {
        $quantity = BigDecimal::of(self::decimal($quantity, 'quantity', true));
        $conversion = BigDecimal::of(self::decimal($conversion, 'conversion', true));
        try {
            $base = (string) $quantity->multipliedBy($conversion)->toScale(4, RoundingMode::Unnecessary);
        } catch (RoundingNecessaryException $exception) {
            throw new ValidationException(['quantity' => 'The base quantity must be exact to four decimal places.']);
        }
        return self::decimal($base, 'quantity', true);
    }

    public static function calculate(array $lines, string $priceMode): array
    {
        if (!array_is_list($lines) || !in_array($priceMode, ['exclusive', 'inclusive'], true)) {
            throw new ValidationException(['lines' => 'Supply a list of lines and an inclusive or exclusive price mode.']);
        }
        $result = ['lines' => [], 'totals' => ['net' => '0.00', 'tax' => '0.00', 'gross' => '0.00']];
        foreach ($lines as $index => $line) {
            if (!is_array($line)) {
                throw new ValidationException(['lines.' . $index => 'Each line must contain quantity, unit price and optional tax rate.']);
            }
            $quantity = BigDecimal::of(self::decimal($line['quantity'] ?? null, "lines.$index.quantity", true));
            $price = BigDecimal::of(self::decimal($line['unit_price'] ?? null, "lines.$index.unit_price"));
            $rate = BigDecimal::of(self::decimal(array_key_exists('tax_rate', $line) ? $line['tax_rate'] : '0', "lines.$index.tax_rate", false, '100'));
            $fraction = $rate->dividedBy('100', 6);
            $extended = self::money($quantity->multipliedBy($price));
            if ($priceMode === 'exclusive') {
                $net = $extended;
                $tax = self::money($net->multipliedBy($fraction));
                $gross = self::money($net->plus($tax));
            } else {
                $gross = $extended;
                $net = $gross->dividedBy($fraction->plus('1'), 2, RoundingMode::HalfUp);
                $tax = $gross->minus($net);
            }
            $amounts = ['net' => (string) $net, 'tax' => (string) $tax, 'gross' => (string) $gross];
            $result['lines'][] = $amounts;
            foreach ($amounts as $key => $value) {
                $result['totals'][$key] = (string) self::money(BigDecimal::of($result['totals'][$key])->plus($value));
            }
        }
        return $result;
    }

    private static function money(BigDecimal $value): BigDecimal
    {
        $value = $value->toScale(2, RoundingMode::HalfUp);
        if ($value->isGreaterThan('99999999999999999.99')) {
            throw new ValidationException(['lines' => 'Calculated amount exceeds the supported range.']);
        }
        return $value;
    }
}
