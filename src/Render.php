<?php
declare(strict_types = 1);

namespace Innmind\StackTrace;

use Innmind\Graphviz\{
    Graph,
    Node,
    Node\Shape,
    Layout\Dot,
};
use Innmind\Stream\Readable;
use Innmind\Colour\Colour;
use Innmind\Immutable\{
    Map,
    Str,
};

final class Render
{
    private ?Map $nodes = null;
    private ?Graph $throwables = null;
    private ?Graph $callFrames = null;
    private Link $link;

    public function __construct(Link $link = null)
    {
        $this->link = $link ?? new Link\ToFile;
    }

    public function __invoke(StackTrace $stack): Readable
    {
        try {
            $this->nodes = Map::of('string', Node::class);
            $this->throwables = Graph\Graph::directed('throwables');
            $this->throwables->displayAs('Thrown');
            $this->callFrames = Graph\Graph::directed('call_frames');
            $this->callFrames->displayAs('Stack Trace');

            $this->renderNodes($stack);
            $this->renderLinks($stack);

            $graph = $this->nodes->values()->reduce(
                Graph\Graph::directed('stack_trace'),
                static function(Graph $graph, Node $node): Graph {
                    $graph->add($node);

                    return $graph;
                }
            );
            $graph->cluster($this->throwables);
            $graph->cluster($this->callFrames);

            return (new Dot)($graph);
        } finally {
            $this->nodes = null;
            $this->throwables = null;
            $this->callFrames = null;
        }
    }

    private function renderNodes(StackTrace $stack): void
    {
        $stack
            ->previous()
            ->add($stack->throwable())
            ->foreach(function(Throwable $e): void {
                $this->renderThrowable($e);

                $e
                    ->callFrames()
                    ->foreach(function(CallFrame $frame): void {
                        $this->renderCallFrame($frame);
                    });
            });
    }

    private function renderThrowable(Throwable $e): void
    {
        $node = Node\Node::named('exception_'.$this->hashThrowable($e));
        $node->displayAs(\sprintf(
            '%s[%s](%s)',
            $this->clean((string) $e->class()),
            $e->code(),
            $e->message()->toString(),
        ));
        $node->shaped(Shape::doubleoctagon()->fillWithColor(Colour::of('red')));
        $node->target(($this->link)($e->file(), $e->line()));

        $this->add(
            $this->hashThrowable($e),
            $node
        );
        $this->throwables->add(new Node\Node($node->name()));
    }

    private function renderCallFrame(CallFrame $frame): void
    {
        $hash = $this->hashFrame($frame);

        if ($this->nodes->contains($hash)) {
            return;
        }

        $name = $this->clean((string) $frame);

        $node = Node\Node::named('call_frame_'.\md5($hash));
        $node->displayAs($name);
        $node->shaped(Shape::box()->fillWithColor(Colour::of('orange')));

        if ($frame instanceof CallFrame\UserLand) {
            $node->target(($this->link)($frame->file(), $frame->line()));
        }

        $this->add($hash, $node);
        $this->callFrames->add(new Node\Node($node->name()));
    }

    private function renderLinks(StackTrace $stack): void
    {
        $stack
            ->previous()
            ->reduce(
                $stack->throwable(),
                function(Throwable $e, Throwable $previous): Throwable {
                    $this->linkCausality($previous, $e);

                    return $previous;
                }
            );

        $stack
            ->previous()
            ->add($stack->throwable())
            ->foreach(function(Throwable $e): void {
                $this->linkCallFrames($e);
            });
    }

    private function linkCausality(Throwable $cause, Throwable $consequence): void
    {
        $consequence = $this->nodes->get($this->hashThrowable($consequence));
        $cause = $this->nodes->get($this->hashThrowable($cause));

        $consequence
            ->linkedTo($cause)
            ->displayAs('Caused By');
    }

    private function linkCallFrames(Throwable $e): void
    {
        if ($e->callFrames()->empty()) {
            return;
        }

        $source = $e->callFrames()->first();
        $edge = $this
            ->nodes
            ->get($this->hashThrowable($e))
            ->linkedTo(
                $this->nodes->get($this->hashFrame($source))
            );
        $edge->displayAs("{$e->file()->path()->toString()}:{$e->line()}");
        $edge->target(($this->link)($e->file(), $e->line()));

        $e
            ->callFrames()
            ->drop(1)
            ->reduce(
                $source,
                function(CallFrame $frame, CallFrame $parent): CallFrame {
                    $frameNode = $this->nodes->get($this->hashFrame($frame));
                    $parentNode = $this->nodes->get($this->hashFrame($parent));

                    if (!$parentNode->edges()->empty()) {
                        // don't add a link if one already present as it would
                        // happen in the case of an exception triggered another one
                        return $parent;
                    }

                    $edge = $parentNode->linkedTo($frameNode);

                    if ($parent instanceof CallFrame\UserLand) {
                        $edge->displayAs("{$parent->file()->path()->toString()}:{$parent->line()}");
                        $edge->target(($this->link)($parent->file(), $parent->line()));
                    }

                    return $parent;
                }
            );
    }

    private function add(string $reference, Node $node): void
    {
        $this->nodes = $this->nodes->put($reference, $node);
    }

    /**
     * Remove special characters and escape backslashes
     */
    private function clean(string $name): string
    {
        return Str::of($name)
            ->replace("\x00", '') // remove the invisible character used in the name of anonymous classes
            ->replace('\\', '\\\\')
            ->toString();
    }

    private function hashThrowable(Throwable $e): string
    {
        return \spl_object_hash($e);
    }

    private function hashFrame(CallFrame $frame): string
    {
        $prefix = '';

        if ($frame instanceof CallFrame\UserLand) {
            $prefix = "{$frame->file()->path()->toString()}|{$frame->line()}|";
        }

        return "$prefix$frame|{$frame->arguments()->count()}";
    }
}
