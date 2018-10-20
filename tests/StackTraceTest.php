<?php
declare(strict_types = 1);

namespace Tests\Innmind\StackTrace;

use Innmind\StackTrace\{
    StackTrace,
    Throwable,
};
use Innmind\Immutable\StreamInterface;
use PHPUnit\Framework\TestCase;

class StackTraceTest extends TestCase
{
    public function testInterface()
    {
        $stack = new StackTrace(
            $foo = new \RuntimeException(
                'foo',
                24,
                $bar = new \LogicException(
                    'bar',
                    42,
                    $baz = new \Exception(
                        'baz',
                        66
                    )
                )
            )
        );

        $this->assertEquals(new Throwable($foo), $stack->throwable());
        $this->assertInstanceOf(StreamInterface::class, $stack->previous());
        $this->assertSame(Throwable::class, (string) $stack->previous()->type());
        $this->assertCount(2, $stack->previous());
        $this->assertEquals(
            [new Throwable($bar), new Throwable($baz)],
            $stack->previous()->toPrimitive()
        );
    }
}