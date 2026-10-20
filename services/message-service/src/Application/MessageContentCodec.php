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
            $decoded = json_decode($value, false, 512, JSON_THROW_ON_ERROR);
            return json_encode(
                $this->normalizeJsonValue($decoded),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException) {
            return null;
        }
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
