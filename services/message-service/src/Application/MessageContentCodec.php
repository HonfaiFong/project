<?php

declare(strict_types=1);

namespace Services\MessageService\Application;

use JsonException;

final class MessageContentCodec
{
    public function canonicalJson(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        try {
            // Validate grammar, but never compare the decoded PHP numbers: they may be floats.
            json_decode($value, false, 512, JSON_THROW_ON_ERROR);
            $pattern = <<<'REGEX'
~"(?:\\.|[^"\\])*"|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?~s
REGEX;
            // Tag strings as well as numbers so JSON strings cannot impersonate numeric tokens.
            $tagged = preg_replace_callback($pattern, function (array $match): string {
                $token = $match[0];
                $tag = $token[0] === '"'
                    ? 's:' . json_decode($token, flags: JSON_THROW_ON_ERROR)
                    : 'n:' . $this->normalizeNumber($token);
                return json_encode($tag, JSON_THROW_ON_ERROR);
            }, $value);
            $decoded = json_decode($tagged, false, 512, JSON_THROW_ON_ERROR);
            return json_encode(
                $this->normalizeJsonValue($decoded),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException) {
            return null;
        }
    }

    private function normalizeNumber(string $token): string
    {
        preg_match('/\A(-?)([0-9]+)(?:\.([0-9]+))?(?:[eE]([+-]?[0-9]+))?\z/', $token, $parts);
        $fraction = $parts[3] ?? '';
        $exponent = $parts[4] ?? '0';
        if (strlen(ltrim($exponent, '+-0')) > 6) {
            throw new JsonException('Number exponent exceeds PostgreSQL numeric range');
        }
        $digits = ltrim($parts[2] . $fraction, '0');
        if ($digits === '') {
            return '0';
        }
        $coefficient = rtrim($digits, '0');
        $scale = (int)$exponent - strlen($fraction) + strlen($digits) - strlen($coefficient);
        return $parts[1] . $coefficient . 'e' . $scale;
    }

    private function normalizeJsonValue(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            uksort($properties, static fn (string $left, string $right): int => strcmp($left, $right));
            foreach ($properties as $key => $child) {
                $properties[$key] = $this->normalizeJsonValue($child);
            }
            return (object)$properties;
        }
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->normalizeJsonValue($child);
        }
        return $value;
    }
}
