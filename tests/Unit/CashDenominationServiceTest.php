<?php

namespace Tests\Unit;

use App\Services\CashDenominationService;
use PHPUnit\Framework\TestCase;

class CashDenominationServiceTest extends TestCase
{
    private CashDenominationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CashDenominationService;
    }

    public function test_breakdown_of_zero_is_empty(): void
    {
        $this->assertSame([], $this->service->breakdown(0));
    }

    public function test_breakdown_of_negative_is_empty(): void
    {
        $this->assertSame([], $this->service->breakdown(-50));
    }

    public function test_breakdown_of_single_denomination(): void
    {
        $this->assertSame([20 => 1], $this->service->breakdown(20));
        $this->assertSame([1000 => 1], $this->service->breakdown(1000));
    }

    public function test_breakdown_uses_minimum_pieces(): void
    {
        // 1247 = 1000 + 200 + 20 + 20 + 5 + 2 = 1×1000, 1×200, 2×20, 1×5, 1×2
        $this->assertSame(
            [1000 => 1, 200 => 1, 20 => 2, 5 => 1, 2 => 1],
            $this->service->breakdown(1247)
        );
    }

    public function test_breakdown_of_3333(): void
    {
        // 3333 = 3×1000 + 1×200 + 1×100 + 1×20 + 1×10 + 1×2 + 1×1
        $this->assertSame(
            [1000 => 3, 200 => 1, 100 => 1, 20 => 1, 10 => 1, 2 => 1, 1 => 1],
            $this->service->breakdown(3333)
        );
    }

    public function test_round_to_peso(): void
    {
        $this->assertSame(100, $this->service->roundToPeso(100.49));
        $this->assertSame(101, $this->service->roundToPeso(100.50));
        $this->assertSame(0, $this->service->roundToPeso(0.0));
    }

    public function test_breakdown_global_is_sum_of_individuals(): void
    {
        // Two employees: 1247 and 3333.
        $result = $this->service->breakdownGlobal([1247, 3333]);

        // Per-denomination sum of both individual breakdowns.
        $expected = [
            1000 => 4, // 1 + 3
            200 => 2,  // 1 + 1
            100 => 1,  // 0 + 1
            20 => 3,   // 2 + 1
            10 => 1,   // 0 + 1
            5 => 1,    // 1 + 0
            2 => 2,    // 1 + 1
            1 => 1,    // 0 + 1
        ];

        $this->assertSame($expected, $result['denominations']);
        $this->assertSame(array_sum($expected), $result['total_pieces']);
        $this->assertSame(1247 + 3333, $result['total_amount']);
    }

    public function test_breakdown_global_rounds_decimal_amounts(): void
    {
        $result = $this->service->breakdownGlobal([19.6, 0.4]);

        // 19.6 -> 20 (1×20); 0.4 -> 0 (nothing).
        $this->assertSame([20 => 1], $result['denominations']);
        $this->assertSame(1, $result['total_pieces']);
        $this->assertSame(20, $result['total_amount']);
    }
}
