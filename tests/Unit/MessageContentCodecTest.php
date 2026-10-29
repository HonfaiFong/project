<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Services\MessageService\Application\MessageContentCodec;
use Tests\TestCase;

final class MessageContentCodecTest extends TestCase
{
    /** @return iterable<string,array{string,string,bool}> */
    public static function contents(): iterable
    {
        yield 'large integers' => ['{"n":9223372036854775808}', '{"n":9223372036854775809}', false];
        yield 'large decimals' => ['1.00000000000000001', '1.00000000000000002', false];
        yield 'integer and string' => ['9223372036854775808', '"9223372036854775808"', false];
        yield 'tag and string' => ['1', '"n:1e0"', false];
        yield 'numeric forms' => ['{"n":9223372036854775808}', '{"n":9223372036854775808.0}', true];
        yield 'exponent' => ['[2,0,-0,0.001,-12.50]', '[2.0,0e12,0.0,1e-3,-125e-1]', true];
        yield 'objects' => ['{"b":1,"a":{"z":2}}', '{"a":{"z":2.0},"b":1e0}', true];
        yield 'duplicate keys keep last' => ['{"a":1,"a":2}', '{"a":2}', true];
        yield 'escaped key' => ['{"\u0061":1}', '{"a":1}', true];
        yield 'object and array' => ['{}', '[]', false];
        yield 'array order' => ['[1,2]', '[2,1]', false];
        yield 'numbers inside strings' => [json_encode('a"123', JSON_THROW_ON_ERROR), json_encode('a"124', JSON_THROW_ON_ERROR), false];
    }

    #[DataProvider('contents')]
    public function testComparisonPreservesJsonbValueSemantics(string $left, string $right, bool $equal): void
    {
        $codec = new MessageContentCodec();
        self::assertNotNull($codec->canonicalJson($left));
        self::assertNotNull($codec->canonicalJson($right));
        self::assertSame($equal, $codec->canonicalJson($left) === $codec->canonicalJson($right));
    }
}
