<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * PremiumCalculator Service
 *
 * A simple, pure PHP class that calculates the insurance premium.
 * No database, no side-effects — just input → output.
 *
 * FORMULA:
 *   premium = base_daily_rate × travel_days × age_multiplier × destination_multiplier × coverage_multiplier
 *
 * HOW TO EXPLAIN IN INTERVIEW:
 *   "I put all the pricing rules in one class so it's easy to find,
 *    easy to test, and easy to change if rules update."
 */
class PremiumCalculator
{
    // Base rate per day, per plan
    private const BASE_RATES = [
        'BASIC'   => 12.0,
        'PLUS'    => 20.0,
        'PREMIUM' => 30.0,
    ];

    // Multiplier based on age at trip start
    private const AGE_MULTIPLIERS = [
        ['min' => 0,  'max' => 17,  'multiplier' => 1.10],
        ['min' => 18, 'max' => 60,  'multiplier' => 1.00],
        ['min' => 61, 'max' => 70,  'multiplier' => 1.50],
        ['min' => 71, 'max' => 999, 'multiplier' => 2.00],
    ];

    // Multiplier based on destination
    private const DESTINATION_MULTIPLIERS = [
        'DOMESTIC'      => 1.00,
        'INTERNATIONAL' => 1.80,
    ];

    // Multiplier based on coverage amount
    private const COVERAGE_MULTIPLIERS = [
        100000 => 1.00,
        300000 => 1.25,
        500000 => 1.50,
    ];

    /**
     * Calculate the premium.
     *
     * @param  string $planCode        BASIC, PLUS, or PREMIUM
     * @param  string $tripStart       Date string e.g. "2026-11-10"
     * @param  string $tripEnd         Date string e.g. "2026-11-14"
     * @param  string $dateOfBirth     Date string e.g. "1998-04-10"
     * @param  string $destination     DOMESTIC or INTERNATIONAL
     * @param  int    $sumInsured      100000, 300000, or 500000
     * @return float  Premium rounded to 2 decimal places
     *
     * @throws \InvalidArgumentException if any value is not supported
     */
    public function calculate(
        string $planCode,
        string $tripStart,
        string $tripEnd,
        string $dateOfBirth,
        string $destination,
        int $sumInsured
    ): float {
        // Step 1: Get base rate per day
        $baseRate = $this->getBaseRate($planCode);

        // Step 2: How many days is the trip? (inclusive = end - start + 1, minimum 1)
        $travelDays = max(1, Carbon::parse($tripStart)->diffInDays(Carbon::parse($tripEnd)) + 1);

        // Step 3: Get age at trip start, then find multiplier
        $ageAtTripStart = Carbon::parse($dateOfBirth)->age;
        // Recalculate age relative to trip start, not today
        $ageAtTripStart = Carbon::parse($dateOfBirth)->diffInYears(Carbon::parse($tripStart));
        $ageMultiplier = $this->getAgeMultiplier($ageAtTripStart);

        // Step 4: Destination multiplier
        $destinationMultiplier = $this->getDestinationMultiplier($destination);

        // Step 5: Coverage multiplier
        $coverageMultiplier = $this->getCoverageMultiplier($sumInsured);

        // Step 6: Apply formula and round to 2 decimal places
        $premium = $baseRate * $travelDays * $ageMultiplier * $destinationMultiplier * $coverageMultiplier;

        return round($premium, 2);
    }

    private function getBaseRate(string $planCode): float
    {
        if (!isset(self::BASE_RATES[$planCode])) {
            throw new \InvalidArgumentException("Unsupported plan code: {$planCode}. Supported: BASIC, PLUS, PREMIUM.");
        }
        return self::BASE_RATES[$planCode];
    }

    private function getAgeMultiplier(int $age): float
    {
        foreach (self::AGE_MULTIPLIERS as $bracket) {
            if ($age >= $bracket['min'] && $age <= $bracket['max']) {
                return $bracket['multiplier'];
            }
        }
        return 1.00; // fallback
    }

    private function getDestinationMultiplier(string $destination): float
    {
        if (!isset(self::DESTINATION_MULTIPLIERS[$destination])) {
            throw new \InvalidArgumentException("Unsupported destination: {$destination}. Use DOMESTIC or INTERNATIONAL.");
        }
        return self::DESTINATION_MULTIPLIERS[$destination];
    }

    private function getCoverageMultiplier(int $sumInsured): float
    {
        if (!isset(self::COVERAGE_MULTIPLIERS[$sumInsured])) {
            throw new \InvalidArgumentException("Unsupported sum_insured: {$sumInsured}. Use 100000, 300000, or 500000.");
        }
        return self::COVERAGE_MULTIPLIERS[$sumInsured];
    }
}
