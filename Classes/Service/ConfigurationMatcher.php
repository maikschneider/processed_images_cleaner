<?php

declare(strict_types=1);

namespace Derhansen\ProcessedImagesCleaner\Service;

/**
 * Matches the unserialized processing configuration of a sys_file_processedfile
 * record against user-provided key/value filters and an optional crop variant.
 *
 * Background on the stored `configuration` column:
 * TYPO3 stores the processing configuration as a serialized PHP array (see
 * \TYPO3\CMS\Core\Resource\Service\ConfigurationService). Values are therefore
 * not normalized and can appear in several forms for the same logical meaning:
 *
 *  - null          → serialized as `N;`. The frontend image path writes a fixed
 *                    key set (width, height, minWidth, minHeight, maxWidth,
 *                    maxHeight, crop) and sets every unused key to null, which is
 *                    why so many records contain `N` values.
 *  - integer       → e.g. `i:64;` (numeric strings are cast to int on processing)
 *  - string        → width/height may carry a TypoScript modifier: "800m" (max)
 *                    or "32c" (crop-scale). These are NOT integers.
 *  - crop          → a JSON string like {"default":{...}} whose top-level keys are
 *                    the crop variant names, or (rarely) a serialized Area object.
 *
 * Matching is intentionally lenient for numeric values so `width=800` also matches
 * "800m" and "800c".
 */
final class ConfigurationMatcher
{
    public function matches(array $configuration, array $filters): bool
    {
        foreach ($filters as $key => $expected) {
            if ($key === 'cropVariant') {
                if (!$this->hasCropVariant($configuration['crop'] ?? null, $expected)) {
                    return false;
                }
                continue;
            }

            if (!$this->keyMatches($configuration, $key, $expected)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function keyMatches(array $configuration, string $key, string $expected): bool
    {
        $expectsNull = $this->isNullLiteral($expected);

        if (!array_key_exists($key, $configuration)) {
            // A key that is completely absent is treated like null, so "key=null"
            // also matches configuration shapes that omit the key entirely.
            return $expectsNull;
        }

        return $this->valueMatches($configuration[$key], $expected, $expectsNull);
    }

    private function valueMatches(mixed $stored, string $expected, bool $expectsNull): bool
    {
        if ($stored === null) {
            return $expectsNull;
        }
        if ($expectsNull) {
            return false;
        }
        if (is_bool($stored)) {
            $stored = $stored ? '1' : '0';
        }
        if (is_array($stored) || is_object($stored)) {
            // Nested configuration (e.g. "JSwindow.") or an Area object cannot be
            // matched by a scalar value.
            return false;
        }

        $storedString = trim((string)$stored);
        $expected = trim($expected);

        // Width/height style values may carry a TypoScript modifier ("800m", "32c").
        // Compare only the numeric part so `width=800` matches "800", "800m" and "800c".
        if (is_numeric($expected) && preg_match('/^(\d+)[cm]?$/', $storedString, $matches) === 1) {
            return (int)$matches[1] === (int)$expected;
        }

        return strcasecmp($storedString, $expected) === 0;
    }

    private function hasCropVariant(mixed $crop, string $variant): bool
    {
        if (!is_string($crop) || $crop === '') {
            return false;
        }
        $decoded = json_decode($crop, true);

        return is_array($decoded) && array_key_exists($variant, $decoded);
    }

    private function isNullLiteral(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['null', '~'], true);
    }
}
