<?php

namespace App\Tests\Service;

use App\Entity\PricingConfig;
use App\Service\PricingCalculator;
use PHPUnit\Framework\TestCase;

class PricingCalculatorTest extends TestCase
{
    private PricingCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new PricingCalculator();
    }

    public function testZeroDistanceGivesZeroPrice(): void
    {
        $this->assertSame(0.0, $this->calculator->calculateSegmentPrice(0.0, 4.0));
    }

    public function testBaseCalculationWithRatingThree(): void
    {
        // Distance 100km, rate 1.5 DH/km => Base = 150 DH.
        // Rating 3.0 => Margin = 5% (no bonus).
        // Price = 150 * (1 + 0.05) = 157.50 DH.
        $config = new PricingConfig();
        $config->setPricePerKm(1.5);
        $config->setBaseMargin(0.05);

        $price = $this->calculator->calculateSegmentPrice(100.0, 3.0, $config);
        $this->assertEquals(157.50, $price);
    }

    public function testCalculationWithRatingBonusAboveThree(): void
    {
        // Distance 100km, rate 1.5 DH/km => Base = 150 DH.
        // Rating 4.0 => Margin = 5% + (4 - 3)*1% = 6%.
        // Price = 150 * (1 + 0.06) = 159.00 DH.
        $config = new PricingConfig();
        $config->setPricePerKm(1.5);
        $config->setBaseMargin(0.05);

        $price = $this->calculator->calculateSegmentPrice(100.0, 4.0, $config);
        $this->assertEquals(159.00, $price);
    }

    public function testHighRatingDriverBonus(): void
    {
        // Rating 5.0 => Margin = 5% + (5 - 3)*1% = 7%.
        // Distance 200km, rate 1.5 DH/km => Base = 300 DH.
        // Price = 300 * 1.07 = 321.00 DH.
        $config = new PricingConfig();
        $config->setPricePerKm(1.5);
        $config->setBaseMargin(0.05);

        $price = $this->calculator->calculateSegmentPrice(200.0, 5.0, $config);
        $this->assertEquals(321.00, $price);
    }
}
