<?php

declare(strict_types=1);

final class Root
{
    /**
     * Objects and their associated information.
     */
    public SplObjectStorage $thread;

    /**
     * Converts each stored object and its information into the value
     * passed to the raw-text serializer.
     */
    public Closure $inform;

    /**
     * Raw-text serializer.
     */
    public Closure $deform;

    /**
     * Secondary protection against unexpectedly deep structures.
     */
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
         * Preserve the root object.
         *
         * Do not convert it with:
         *
         *     array_values(get_object_vars($object))
         *
         * The serializer must receive the actual object so root and
         * nested objects use the same representation.
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
         * The value must be accepted by reference so PHP array
         * references remain observable during recursive traversal.
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

    public function getMaximumDepth(): int
    {
        return $this->maximumDepth;
    }

    /**
     * Add an object to the root storage.
     *
     * offsetSet() is used instead of deprecated attach().
     */
    public function attach(
        object $object,
        mixed $information = null
    ): self {
        $this->thread->offsetSet(
            $object,
            $information
        );

        return $this;
    }

    /**
     * Remove an object from the root storage.
     *
     * offsetUnset() is used instead of deprecated detach().
     */
    public function detach(object $object): self
    {
        if ($this->thread->offsetExists($object)) {
            $this->thread->offsetUnset($object);
        }

        return $this;
    }

    /**
     * Determine whether an object is in the root storage.
     *
     * offsetExists() is used instead of deprecated contains().
     */
    public function contains(object $object): bool
    {
        return $this->thread->offsetExists($object);
    }

    public function count(): int
    {
        return $this->thread->count();
    }

    public function clear(): self
    {
        /*
         * Collect first so the storage isn't mutated while its
         * iterator is active.
         */
        $objects = [];

        foreach ($this->thread as $object) {
            $objects[] = $object;
        }

        foreach ($objects as $object) {
            $this->thread->offsetUnset($object);
        }

        return $this;
    }

    /**
     * Serialize a complete PHP value using only raw text operations.
     *
     * No json_encode(), serialize(), var_export(), or print_r()
     * is used.
     */
    private function deformValue(mixed &$value): string
    {
        /*
         * Objects on the current recursion path.
         */
        $activeObjects = new SplObjectStorage();

        /*
         * Stable labels assigned to objects for this serialization.
         */
        $objectLabels = new SplObjectStorage();

        /*
         * PHP array-reference IDs on the active recursion path.
         */
        $activeArrayReferences = [];

        /*
         * Stable readable labels assigned to PHP array references.
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
         * NaN and infinity are not valid JSON numbers, so they are
         * emitted as manually quoted text.
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
         * Preserve the visible difference between an integer-like
         * float and an actual integer.
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
     * Serialize an array and protect against recursive references.
     *
     * Arrays cannot use spl_object_id(). Instead, only elements that
     * are actual PHP references are identified with
     * ReflectionReference::getId().
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

        /*
         * Iteration by reference is required to preserve nested PHP
         * reference identity.
         */
        foreach ($array as $key => &$value) {
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
                 * The same reference edge is already active higher
                 * on the current traversal path.
                 */
                if (
                    array_key_exists(
                        $referenceId,
                        $activeArrayReferences
                    )
                ) {
                    $serializedValue = '{'
                        . '"$arrayRef":'
                        . $referenceLabel
                        . '}';

                    $parts[] = $this->formatArrayElement(
                        $key,
                        $serializedValue,
                        $isSequential
                    );

                    continue;
                }

                /*
                 * Mark this PHP reference as active only for the
                 * duration of the branch reached through it.
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
                 * Ordinary non-reference array element.
                 */
                $serializedValue = $deform(
                    $value,
                    $depth + 1
                );
            }

            $parts[] = $this->formatArrayElement(
                $key,
                $serializedValue,
                $isSequential
            );
        }

        /*
         * Break the lingering foreach reference.
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

    private function formatArrayElement(
        int|string $key,
        string $serializedValue,
        bool $isSequential
    ): string {
        if ($isSequential) {
            return $serializedValue;
        }

        return '"'
            . $this->escapeText((string) $key)
            . '":'
            . $serializedValue;
    }

    /**
     * Serialize an object using native object identity.
     */
    private function deformObject(
        object $object,
        Closure $deform,
        SplObjectStorage $activeObjects,
        SplObjectStorage $objectLabels,
        int &$nextObjectLabel,
        int $depth
    ): string {
        $nativeObjectId = spl_object_id($object);

        /*
         * Assign a stable serializer-local label.
         *
         * offsetExists() and offsetSet() replace the deprecated
         * contains() and attach() methods.
         */
        if (!$objectLabels->offsetExists($object)) {
            $objectLabels->offsetSet(
                $object,
                $nextObjectLabel
            );

            ++$nextObjectLabel;
        }

        $objectLabel = $objectLabels->offsetGet($object);

        /*
         * This exact object is already being serialized higher on
         * the current recursion path.
         */
        if ($activeObjects->offsetExists($object)) {
            return '{'
                . '"$ref":'
                . $objectLabel
                . ','
                . '"$objectId":'
                . $nativeObjectId
                . '}';
        }

        /*
         * offsetSet() replaces deprecated attach().
         */
        $activeObjects->offsetSet(
            $object,
            true
        );

        try {
            $parts = [];

            $parts[] = '"$id":'
                . $objectLabel;

            $parts[] = '"$objectId":'
                . $nativeObjectId;

            $parts[] = '"$class":"'
                . $this->escapeText(
                    get_class($object)
                )
                . '"';

            /*
             * Casting exposes initialized stored properties.
             *
             * Private and protected property names may contain NUL
             * delimiters, which normalizePropertyName() handles.
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
             * offsetUnset() replaces deprecated detach().
             *
             * Removing the object here makes this active-path
             * tracking, not global duplicate tracking.
             */
            $activeObjects->offsetUnset($object);
        }
    }

    /**
     * PHP 8.0-compatible equivalent of array_is_list().
     */
    private function isSequentialArray(array $array): bool
    {
        $expectedKey = 0;

        foreach ($array as $key => $unused) {
            if ($key !== $expectedKey) {
                return false;
            }

            ++$expectedKey;
        }

        return true;
    }

    /**
     * Normalize PHP's object-to-array property names.
     *
     * Protected:
     *     "\0*\0property"
     *
     * Private:
     *     "\0ClassName\0property"
     */
    private function normalizePropertyName(string $key): string
    {
        if (strpos($key, "\0") === false) {
            return $key;
        }

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
     * Escape manually constructed quoted text.
     *
     * No JSON encoder is used.
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
                    /*
                     * Escape C0 control bytes manually.
                     */
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

        /*
         * Restore the original stream position when possible.
         */
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

        /*
         * Binary-to-text conversion only. This does not use a JSON
         * encoder or general PHP serializer.
         */
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
     * Return one complete top-level JSON-compatible array.
     *
     * Root objects are preserved and sent through deformObject(),
     * exactly like nested objects.
     */
    public function __toString(): string
    {
        try {
            $output = [];

            $this->thread->rewind();

            while ($this->thread->valid()) {
                $element = $this->thread->current();
                $information = $this->thread->getInfo();

                /*
                 * $element remains an object. It is not flattened
                 * with get_object_vars() or array_values().
                 */
                $informed = ($this->inform)(
                    $element,
                    $information
                );

                $output[] = $informed;

                $this->thread->next();
            }

            return ($this->deform)($output);
        } catch (Throwable $throwable) {
            /*
             * __toString() must always return a string.
             */
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
 * Tests
 * ============================================================
 */

/*
 * Recursive objects:
 *
 * Alpha -> Beta -> Alpha
 */
$alpha = new stdClass();
$beta = new stdClass();

$alpha->name = 'Alpha';
$beta->name = 'Beta';

$alpha->child = $beta;
$beta->parent = $alpha;


/*
 * Recursive associative array:
 *
 * metadata.self -> metadata
 */
$metadata = [
    'enabled' => true,
    'count' => 10
];

$metadata['self'] =& $metadata;


/*
 * Recursive sequential array:
 *
 * list[2] -> list
 */
$list = [
    'one',
    'two'
];

$list[] =& $list;


/*
 * Indirect recursive arrays:
 *
 * left.right -> right
 * right.left -> left
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
 * Attach recursive arrays to the object graph.
 */
$alpha->metadata =& $metadata;
$alpha->list =& $list;
$alpha->left =& $left;


/*
 * Store and output.
 */
$root = new Root(512);

$root->attach(
    $alpha,
    [
        'position' => 1,
        'active' => true
    ]
);

header('Content-Type: application/json; charset=UTF-8');

echo $root;
