<?php namespace Vmod\Erp\Tests;

use Vmod\Tests\ApplicationTestCase;
use Vmod\Erp\Classes\DocumentTotals;
use October\Rain\Exception\ValidationException;

class DocumentTotalsTest extends ApplicationTestCase
{
    protected $autoMigrate = false;

    public function testCommercialExamplesAndRoundedLineSums(): void
    {
        $this->assertTrue(class_exists(DocumentTotals::class), 'Decimal calculator must exist.');
        foreach ([
            ['3', '10.00', '6', 'exclusive', ['net' => '30.00', 'tax' => '1.80', 'gross' => '31.80']],
            ['3', '10.00', '6', 'inclusive', ['net' => '28.30', 'tax' => '1.70', 'gross' => '30.00']],
            ['1', '0.05', '10', 'exclusive', ['net' => '0.05', 'tax' => '0.01', 'gross' => '0.06']],
            ['0.1', '0.2', '0', 'inclusive', ['net' => '0.02', 'tax' => '0.00', 'gross' => '0.02']],
            ['100', '999999999999999.9999', '0', 'exclusive', ['net' => '99999999999999999.99', 'tax' => '0.00', 'gross' => '99999999999999999.99']],
        ] as [$quantity, $price, $rate, $mode, $expected]) {
            $result = DocumentTotals::calculate([['quantity' => $quantity, 'unit_price' => $price, 'tax_rate' => $rate]], $mode);
            $this->assertSame($expected, $result['totals']);
            $this->assertSame($expected, $result['lines'][0]);
        }
        $result = DocumentTotals::calculate([
            ['quantity' => '1', 'unit_price' => '0.05', 'tax_rate' => '10'],
            ['quantity' => '1', 'unit_price' => '0.05', 'tax_rate' => '10'],
            ['quantity' => '3', 'unit_price' => '0.1', 'tax_rate' => '0'],
            ['quantity' => '1', 'unit_price' => '10', 'tax_rate' => '6'],
        ], 'exclusive');
        $this->assertSame(['net' => '10.40', 'tax' => '0.62', 'gross' => '11.02'], $result['totals']);
    }

    public function testRejectsMalformedPrecisionAndOverflowInsteadOfRoundingStock(): void
    {
        $this->assertTrue(class_exists(DocumentTotals::class), 'Decimal calculator must exist.');
        $line = ['quantity' => '1', 'unit_price' => '10', 'tax_rate' => '6'];
        foreach ([
            [['bad'], 'exclusive'], [[$line], 'unknown'],
            [[array_replace($line, ['quantity' => 1.2])], 'exclusive'],
            [[array_replace($line, ['unit_price' => null])], 'exclusive'],
            [[array_replace($line, ['quantity' => '0'])], 'exclusive'],
            [[array_replace($line, ['quantity' => '1e3'])], 'exclusive'],
            [[array_replace($line, ['unit_price' => '0.00001'])], 'exclusive'],
            [[array_replace($line, ['tax_rate' => '100.0001'])], 'exclusive'],
            [[array_replace($line, ['unit_price' => '1000000000000000'])], 'exclusive'],
            [[array_replace($line, ['quantity' => '999999999999999', 'unit_price' => '999999999999999'])], 'exclusive'],
            [array_fill(0, 2, ['quantity' => '100', 'unit_price' => '900000000000000', 'tax_rate' => '0']), 'exclusive'],
        ] as [$lines, $mode]) {
            try {
                DocumentTotals::calculate($lines, $mode);
                $this->fail('Invalid input must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors(), json_encode($lines));
            }
        }
        $this->assertSame('1.2345', DocumentTotals::baseQuantity('2.469', '0.5'));
        try {
            DocumentTotals::baseQuantity('0.0001', '0.0001');
            $this->fail('Stock precision must be exact.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }
}
