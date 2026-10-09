<?php

namespace App\Services;

use App\Models\Item;
use RuntimeException;

/**
 * Makes 13-digit item barcodes in the EAN-13 format.
 *
 * They start with 2, the range GS1 reserves for in-store / internal use, so they can never collide with a
 * barcode a manufacturer printed on a real product. The 13th digit is the standard EAN-13 check digit, so
 * any scanner reads them. A code that is already on an item is never returned.
 */
class BarcodeGenerator
{
    private const MAX_ATTEMPTS = 25;

    /**
     * @param  (callable(): string)|null  $digits  supplies the 11 random digits (injectable for tests)
     */
    public function generate(?callable $digits = null): string
    {
        $digits ??= fn () => str_pad((string) random_int(0, 99_999_999_999), 11, '0', STR_PAD_LEFT);

        for ($i = 0; $i < self::MAX_ATTEMPTS; $i++) {
            $body = '2'.$digits();
            $code = $body.self::checkDigit($body);

            if (! Item::where('barcode', $code)->exists()) {
                return $code;
            }
        }

        throw new RuntimeException('Could not find an unused barcode. Please try again.');
    }

    /** EAN-13 check digit for the first 12 digits. */
    public static function checkDigit(string $first12): int
    {
        $sum = 0;
        foreach (str_split($first12) as $position => $digit) {
            $sum += (int) $digit * ($position % 2 === 0 ? 1 : 3);
        }

        return (10 - ($sum % 10)) % 10;
    }

    public static function isValid(string $code): bool
    {
        return preg_match('/^\d{13}$/', $code) === 1 && self::checkDigit(substr($code, 0, 12)) === (int) $code[12];
    }
}
