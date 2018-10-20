<?php
declare(strict_types = 1);

namespace Tests\Innmind\StackTrace;

use Innmind\StackTrace\Throwable;
use Innmind\Immutable\{
    StreamInterface,
    Str,
};
use PHPUnit\Framework\TestCase;

class ThrowableTest extends TestCase
{
    public function testInterface()
    {
        $throwable = new Throwable($e = new \Exception('foo', 42));

        $this->assertInstanceOf(Str::class, $throwable->class());
        $this->assertSame(\Exception::class, (string) $throwable->class());
        $this->assertInstanceOf(Str::class, $throwable->message());
        $this->assertSame('foo', (string) $throwable->message());
        $this->assertSame(42, $throwable->code());
        $this->assertInstanceOf(StreamInterface::class, $throwable->trace());
        $this->assertSame(Str::class, (string) $throwable->trace()->type());
        $this->assertCount(12, $throwable->trace());
        $this->assertSame($e->getTraceAsString(), (string) $throwable->trace()->join("\n"));
    }
}