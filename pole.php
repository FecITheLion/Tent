<?php

declare(strict_types=1);

class Root
{
    public private(set) SplObjectStorage $thread;

    public Closure $inform {
        set (Closure $ation) =>
            $this->inform = fn(
                object $at,
                ...$ion
            ): array|object => [
                [
                    array_values(get_object_vars($at)),
                    array_values($ion)
                ]
            ];
    }

    public Closure $deform {
        set (Closure $ation) =>
            $this->deform = function (mixed $ation): string {

                /*
                 * Objects currently being traversed.
                 *
                 * This is intentionally path-based rather than global.
                 * An object is detached after its branch finishes so the
                 * same object can legitimately appear elsewhere.
                 */
                $seen = new SplObjectStorage();

                /*
                 * Raw textual escaping.
                 *
                 * No json_encode() or serialization functions are used.
                 */
                $escape = static function (string $value): string {
                    return str_replace(
                        [
                            "\\",
                            "\"",
                            "\r",
                            "\n",
                            "\t",
                            "\b",
                            "\f"
                        ],
                        [
                            "\\\\",
                            "\\\"",
                            "\\r",
                            "\\n",
                            "\\t",
                            "\\b",
                            "\\f"
                        ],
                        $value
                    );
                };

                /*
                 * Recursive serializer.
                 */
                $deform = function (mixed $item) use (
                    &$deform,
                    $seen,
                    $escape
                ): string {

                    return match (get_debug_type($item)) {

                        "null" =>
                            'null',

                        "bool" =>
                            $item ? 'true' : 'false',

                        "int" =>
                            (string) $item,

                        "float" =>
                            match (true) {
                                is_nan($item) => 'NaN',
                                $item === INF => 'Infinity',
                                $item === -INF => '-Infinity',
                                default => (string) $item
                            },

                        "string" =>
                            '"' .
                            $escape($item) .
                            '"',

                        "array" =>
                            '[' .
                            implode(
                                ',',
                                array_map(
                                    fn(mixed $value): string =>
                                        $deform($value),
                                    $item
                                )
                            ) .
                            ']',

                        default =>
                            is_resource($item)
                                ? $this->deformResource(
                                    $item,
                                    $deform,
                                    $escape
                                )
                                : (
                                    is_object($item)
                                        ? $this->deformObject(
                                            $item,
                                            $deform,
                                            $escape,
                                            $seen
                                        )
                                        : '"' .
                                            $escape((string) $item) .
                                            '"'
                                )
                    };
                };

                return $deform($ation);
            };
    }

    public function __construct()
    {
        $this->thread = new SplObjectStorage();

        /*
         * Trigger the property hooks so the default closures are created.
         *
         * The closure supplied here is intentionally only the setter
         * input. The setter constructs the actual implementation.
         */
        $this->inform = static fn(
            object $at,
            mixed ...$ion
        ): array|object => [];

        $this->deform = static fn(
            mixed $ation
        ): string => '';
    }

    public function attach(
        object $object,
        mixed $information = null
    ): static {
        $this->thread->attach(
            $object,
            $information
        );

        return $this;
    }

    public function detach(
        object $object
    ): static {
        if ($this->thread->contains($object)) {
            $this->thread->detach($object);
        }

        return $this;
    }

    public function contains(
        object $object
    ): bool {
        return $this->thread->contains($object);
    }

    public function count(): int
    {
        return $this->thread->count();
    }

    private function deformObject(
        object $object,
        Closure $deform,
        Closure $escape,
        SplObjectStorage $seen
    ): string {

        /*
         * Circular reference.
         *
         * spl_object_id() gives us an identity without inspecting
         * or serializing the object.
         */
        if ($seen->contains($object)) {
            return '{'
                . '"$ref":'
                . spl_object_id($object)
                . '}';
        }

        $seen->attach($object);

        try {
            $pairs = [];

            /*
             * Give every emitted object an identity that a circular
             * $ref can refer back to.
             */
            $pairs[] =
                '"$id":'
                . spl_object_id($object);

            /*
             * Cast exposes the object's stored properties.
             */
            foreach ((array) $object as $key => $value) {

                /*
                 * Private/protected properties may contain PHP's
                 * internal NUL-qualified property names.
                 * Convert them into ordinary textual keys.
                 */
                $key = str_replace(
                    "\0",
                    '',
                    (string) $key
                );

                $pairs[] =
                    '"'
                    . $escape($key)
                    . '":'
                    . $deform($value);
            }

            return '{'
                . implode(',', $pairs)
                . '}';

        } finally {

            /*
             * Important:
             *
             * Removing the object here means $seen represents the
             * active recursion path rather than every previously
             * encountered object.
             */
            $seen->detach($object);
        }
    }

    private function deformResource(
        mixed $resource,
        Closure $deform,
        Closure $escape
    ): string {

        $type = get_resource_type($resource);

        return match ($type) {

            'stream' =>
                $this->deformStream(
                    $resource,
                    $deform
                ),

            default =>
                '{'
                . '"blob_data":'
                . $deform(
                    'Processing unhandled type: '
                    . $type
                )
                . ','
                . '"status":"unsupported",'
                . '"mime_type":"application/octet-stream"'
                . '}'
        };
    }

    private function deformStream(
        mixed $stream,
        Closure $deform
    ): string {

        $position = ftell($stream);

        if ($position !== false) {
            rewind($stream);
        }

        $data = stream_get_contents($stream);

        /*
         * Restore the original stream position so serialization
         * does not permanently consume the stream.
         */
        if ($position !== false) {
            fseek(
                $stream,
                $position
            );
        }

        if ($data === false) {
            return '{'
                . '"blob_data":null,'
                . '"status":"failure",'
                . '"mime_type":"application/octet-stream"'
                . '}';
        }

        /*
         * Binary data cannot safely be embedded directly into arbitrary
         * textual output, so the blob itself is represented as base64.
         *
         * base64_encode() is binary-to-text conversion, not JSON
         * serialization.
         */
        return '{'
            . '"blob_data":'
            . $deform(base64_encode($data))
            . ','
            . '"status":"success",'
            . '"mime_type":"application/octet-stream"'
            . '}';
    }

    public function __toString(): string
    {
        $strung = '';

        $this->thread->rewind();

        while ($this->thread->valid()) {

            $element =
                $this->thread->current();

            $info =
                $this->thread->getInfo();

            $information =
                ($this->inform)(
                    $element,
                    $info
                );

            $identity =
                ($this->deform)(
                    $information
                );

            $strung .= $identity;

            $this->thread->next();
        }

        return $strung;
    }
}


/*
 * ------------------------------------------------------------
 * Example
 * ------------------------------------------------------------
 */

$a = new stdClass();
$b = new stdClass();

$a->name = 'Alpha';
$b->name = 'Beta';

/*
 * Circular graph:
 *
 * A -> B
 * B -> A
 */
$a->child = $b;
$b->parent = $a;

$root = new Root();

$root->attach(
    $a,
    [
        'position' => 1,
        'active' => true
    ]
);

echo $root;
