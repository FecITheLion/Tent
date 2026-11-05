<?php

declare(strict_types=1);

final class Root
{
    public SplObjectStorage $thread;

    public Closure $inform;

    public Closure $deform;

    private int $maximumDepth;

    public function __construct(int $maximumDepth = 512)
    {
        if ($maximumDepth < 1) {
            throw new InvalidArgumentException(
                'Maximum depth must be at least 1.'
            );
        }

        $this->maximumDepth = $maximumDepth;
        $this->thread = new SplObjectStorage();

        /*
         * Converts an attached object and its associated information
         * into the value supplied to the raw serializer.
         */
        $this->inform = static function (
            object $object,
            mixed $information = null
        ): array {
            return [
                'object' => $object,
                'information' => $information
            ];
        };

        /*
         * Public raw-text serializer.
         *
         * The argument is passed by reference so array references
         * remain observable during traversal.
         */
        $this->deform = function (mixed &$value): string {
            return $this->deformValue($value);
        };
    }

    public function setInform(Closure $inform): self
    {
        $this->inform = $inform;

        return $this;
    }

    public function setDeform(Closure $deform): self
    {
        $this->deform = $deform;

        return $this;
    }

    public function setMaximumDepth(int $maximumDepth): self
    {
        if ($maximumDepth < 1) {
            throw new InvalidArgumentException(
                'Maximum depth must be at least 1.'
            );
        }

        $this->maximumDepth = $maximumDepth;

        return $this;
    }

    public function attach(
        object $object,
        mixed $information = null
    ): self {
        $this->thread->attach($object, $information);

        return $this;
    }

    public function detach(object $object): self
    {
        if ($this->thread->contains($object)) {
            $this->thread->detach($object);
        }

        return $this;
    }

    public function contains(object $object): bool
    {
        return $this->thread->contains($object);
    }

    public function count(): int
    {
        return $this->thread->count();
    }

    /**
     * Serialize a complete value using raw string operations.
     *
     * No json_encode(), serialize(), var_export(), print_r(),
     * or similar serialization function is used.
     */
    private function deformValue(mixed &$value): string
    {
        /*
         * Objects currently on the active traversal path.
         */
        $activeObjects = new SplObjectStorage();

        /*
         * Stable output labels for encountered objects.
         */
        $objectLabels = new SplObjectStorage();

        /*
         * Array-reference IDs currently on the active path.
         *
         * The key is the binary string returned by
         * ReflectionReference::getId().
         */
        $activeArrayReferences = [];

        /*
         * Stable readable labels assigned to array references.
         */
        $arrayReferenceLabels = [];

        $nextObjectLabel = 1;
        $nextArrayReferenceLabel = 1;

        $deform = null;

        $deform = function (
            mixed &$item,
            int $depth = 0
        ) use (
            &$deform,
            $activeObjects,
            $objectLabels,
            &$activeArrayReferences,
            &$arrayReferenceLabels,
            &$nextObjectLabel,
            &$nextArrayReferenceLabel
        ): string {
            if ($depth > $this->maximumDepth) {
                return '{'
                    . '"$maximumDepth":'
                    . $this->maximumDepth
                    . '}';
            }

            if ($item === null) {
                return 'null';
            }

            if (is_bool($item)) {
                return $item ? 'true' : 'false';
            }

            if (is_int($item)) {
                return (string) $item;
            }

            if (is_float($item)) {
                return $this->deformFloat($item);
            }

            if (is_string($item)) {
                return '"'
                    . $this->escapeText($item)
                    . '"';
            }

            if (is_resource($item)) {
                return $this->deformResource(
                    $item,
                    $deform,
                    $depth
                );
            }

            if (is_array($item)) {
                return $this->deformArray(
                    $item,
                    $deform,
                    $activeArrayReferences,
                    $arrayReferenceLabels,
                    $nextArrayReferenceLabel,
                    $depth
                );
            }

            if (is_object($item)) {
                return $this->deformObject(
                    $item,
                    $deform,
                    $activeObjects,
                    $objectLabels,
                    $nextObjectLabel,
                    $depth
                );
            }

            return '"'
                . $this->escapeText((string) $item)
                . '"';
        };

        return $deform($value, 0);
    }

    private function deformFloat(float $value): string
    {
        /*
         * NaN and infinity are not valid JSON numbers.
         * They are therefore emitted as quoted text.
         */
        if (is_nan($value)) {
            return '"NaN"';
        }

        if ($value === INF) {
            return '"Infinity"';
        }

        if ($value === -INF) {
            return '"-Infinity"';
        }

        $text = (string) $value;

        /*
         * Preserve the visible distinction between 1 and 1.0.
         */
        if (
            strpos($text, '.') === false &&
            stripos($text, 'e') === false
        ) {
            $text .= '.0';
        }

        return $text;
    }

    /**
     * Serialize a PHP array.
     *
     * Array identity is not manufactured. Instead, each element is
     * inspected to determine whether that element is an actual PHP
     * reference. Only reference edges participate in circular-array
     * tracking.
     */
    private function deformArray(
        array &$array,
        Closure $deform,
        array &$activeArrayReferences,
        array &$arrayReferenceLabels,
        int &$nextArrayReferenceLabel,
        int $depth
    ): string {
        if ($array === []) {
            return '[]';
        }

        $isSequential = $this->isSequentialArray($array);
        $parts = [];

        foreach ($array as $key => &$value) {
            /*
             * Returns null for a normal array element and a
             * ReflectionReference when this element was assigned
             * through PHP reference semantics.
             */
            $reference = ReflectionReference::fromArrayElement(
                $array,
                $key
            );

            if ($reference !== null) {
                $referenceId = $reference->getId();

                if (
                    !array_key_exists(
                        $referenceId,
                        $arrayReferenceLabels
                    )
                ) {
                    $arrayReferenceLabels[$referenceId] =
                        $nextArrayReferenceLabel;

                    ++$nextArrayReferenceLabel;
                }

                $referenceLabel =
                    $arrayReferenceLabels[$referenceId];

                /*
                 * This exact PHP reference is already being followed
                 * higher on the active recursion path.
                 */
                if (
                    array_key_exists(
                        $referenceId,
                        $activeArrayReferences
                    )
                ) {
                    $serializedValue = '{'
                        . '"$arrayReference":'
                        . $referenceLabel
                        . '}';

                    $parts[] = $isSequential
                        ? $serializedValue
                        : '"'
                            . $this->escapeText((string) $key)
                            . '":'
                            . $serializedValue;

                    continue;
                }

                /*
                 * Mark this reference edge as active only while
                 * traversing the value reached through it.
                 */
                $activeArrayReferences[$referenceId] = true;

                try {
                    $serializedValue = $deform(
                        $value,
                        $depth + 1
                    );
                } finally {
                    unset(
                        $activeArrayReferences[$referenceId]
                    );
                }
            } else {
                /*
                 * Ordinary nonreference array element.
                 */
                $serializedValue = $deform(
                    $value,
                    $depth + 1
                );
            }

            if ($isSequential) {
                $parts[] = $serializedValue;
            } else {
                $parts[] = '"'
                    . $this->escapeText((string) $key)
                    . '":'
                    . $serializedValue;
            }
        }

        /*
         * Break the foreach reference after iteration.
         */
        unset($value);

        if ($isSequential) {
            return '['
                . implode(',', $parts)
                . ']';
        }

        return '{'
            . implode(',', $parts)
            . '}';
    }

    /**
     * Serialize an object using object identity for recursion
     * protection.
     */
    private function deformObject(
        object $object,
        Closure $deform,
        SplObjectStorage $activeObjects,
        SplObjectStorage $objectLabels,
        int &$nextObjectLabel,
        int $depth
    ): string {
        /*
         * spl_object_id() is the native identity mechanism for
         * objects. The numeric ID is also included for diagnostic
         * visibility.
         */
        $nativeObjectId = spl_object_id($object);

        if (!$objectLabels->contains($object)) {
            $objectLabels->attach(
                $object,
                $nextObjectLabel
            );

            ++$nextObjectLabel;
        }

        $objectLabel = $objectLabels[$object];

        if ($activeObjects->contains($object)) {
            return '{'
                . '"$ref":'
                . $objectLabel
                . ','
                . '"$objectId":'
                . $nativeObjectId
                . '}';
        }

        $activeObjects->attach($object);

        try {
            $parts = [];

            $parts[] = '"$id":'
                . $objectLabel;

            $parts[] = '"$objectId":'
                . $nativeObjectId;

            $parts[] = '"$class":"'
                . $this->escapeText(get_class($object))
                . '"';

            /*
             * Casting exposes stored public, protected, and private
             * properties. References are retained in the resulting
             * property array.
             */
            $properties = (array) $object;

            foreach ($properties as $key => &$propertyValue) {
                $propertyName = $this->normalizePropertyName(
                    (string) $key
                );

                $parts[] = '"'
                    . $this->escapeText($propertyName)
                    . '":'
                    . $deform(
                        $propertyValue,
                        $depth + 1
                    );
            }

            unset($propertyValue);

            return '{'
                . implode(',', $parts)
                . '}';
        } finally {
            /*
             * Active-path tracking allows an object to appear again
             * in a separate nonrecursive branch.
             */
            $activeObjects->detach($object);
        }
    }

    private function isSequentialArray(array $array): bool
    {
        /*
         * Manual implementation keeps compatibility with PHP 8.0,
         * where array_is_list() is unavailable.
         */
        $expectedKey = 0;

        foreach ($array as $key => $unused) {
            if ($key !== $expectedKey) {
                return false;
            }

            ++$expectedKey;
        }

        return true;
    }

    private function normalizePropertyName(string $key): string
    {
        if (strpos($key, "\0") === false) {
            return $key;
        }

        /*
         * Protected property:
         *
         *     "\0*\0property"
         *
         * Private property:
         *
         *     "\0ClassName\0property"
         */
        $parts = explode("\0", $key);
        $propertyName = (string) end($parts);

        if (
            isset($parts[1]) &&
            $parts[1] !== '' &&
            $parts[1] !== '*'
        ) {
            return $parts[1]
                . '::'
                . $propertyName;
        }

        return $propertyName;
    }

    /**
     * Escape raw text manually.
     *
     * No JSON encoder is involved.
     */
    private function escapeText(string $text): string
    {
        $result = '';
        $length = strlen($text);

        for ($index = 0; $index < $length; ++$index) {
            $character = $text[$index];
            $ordinal = ord($character);

            switch ($character) {
                case '"':
                    $result .= '\\"';
                    break;

                case '\\':
                    $result .= '\\\\';
                    break;

                case "\b":
                    $result .= '\\b';
                    break;

                case "\f":
                    $result .= '\\f';
                    break;

                case "\n":
                    $result .= '\\n';
                    break;

                case "\r":
                    $result .= '\\r';
                    break;

                case "\t":
                    $result .= '\\t';
                    break;

                default:
                    if ($ordinal < 32) {
                        $result .= sprintf(
                            '\\u%04x',
                            $ordinal
                        );
                    } else {
                        $result .= $character;
                    }

                    break;
            }
        }

        return $result;
    }

    private function deformResource(
        mixed $resource,
        Closure $deform,
        int $depth
    ): string {
        $resourceType = get_resource_type($resource);

        if ($resourceType === 'stream') {
            return $this->deformStream(
                $resource,
                $deform,
                $depth
            );
        }

        $typeText = $resourceType;

        return '{'
            . '"blob_data":null,'
            . '"status":"unsupported",'
            . '"resource_type":'
            . $deform($typeText, $depth + 1)
            . ','
            . '"mime_type":"application/octet-stream"'
            . '}';
    }

    private function deformStream(
        mixed $stream,
        Closure $deform,
        int $depth
    ): string {
        $position = ftell($stream);
        $rewound = false;

        if ($position !== false) {
            $rewound = rewind($stream);
        }

        $data = stream_get_contents($stream);

        if ($position !== false && $rewound) {
            fseek($stream, $position);
        }

        if ($data === false) {
            return '{'
                . '"blob_data":null,'
                . '"status":"failure",'
                . '"mime_type":"application/octet-stream"'
                . '}';
        }

        $encodedData = base64_encode($data);

        return '{'
            . '"blob_data":'
            . $deform($encodedData, $depth + 1)
            . ','
            . '"status":"success",'
            . '"mime_type":"application/octet-stream"'
            . '}';
    }

    /**
     * Produce one complete top-level JSON-compatible array.
     *
     * The text is manually constructed by the deform closure.
     */
    public function __toString(): string
    {
        try {
            $output = [];

            $this->thread->rewind();

            while ($this->thread->valid()) {
                $element = $this->thread->current();
                $information = $this->thread->getInfo();

                $informed = ($this->inform)(
                    $element,
                    $information
                );

                $output[] = $informed;

                $this->thread->next();
            }

            return ($this->deform)($output);
        } catch (Throwable $throwable) {
            return '{'
                . '"error":{'
                . '"type":"'
                . $this->escapeText(
                    get_class($throwable)
                )
                . '",'
                . '"message":"'
                . $this->escapeText(
                    $throwable->getMessage()
                )
                . '"'
                . '}'
                . '}';
        }
    }
}


/*
 * ============================================================
 * Recursive object test
 * ============================================================
 */

$first = new stdClass();
$second = new stdClass();

$first->name = 'Alpha';
$second->name = 'Beta';

$first->child = $second;
$second->parent = $first;


/*
 * ============================================================
 * Direct recursive associative-array test
 * ============================================================
 */

$metadata = [
    'enabled' => true,
    'count' => 10
];

$metadata['self'] =& $metadata;


/*
 * ============================================================
 * Direct recursive sequential-array test
 * ============================================================
 */

$list = [
    'one',
    'two'
];

$list[] =& $list;


/*
 * ============================================================
 * Indirect recursive-array test
 *
 * left -> right -> left
 * ============================================================
 */

$left = [
    'name' => 'left'
];

$right = [
    'name' => 'right'
];

$left['right'] =& $right;
$right['left'] =& $left;


/*
 * Add the recursive arrays to the recursive object.
 */

$first->metadata =& $metadata;
$first->list =& $list;
$first->left =& $left;


/*
 * ============================================================
 * Serialize
 * ============================================================
 */

$root = new Root(512);

$root->attach(
    $first,
    [
        'position' => 1,
        'active' => true
    ]
);

echo $root;
