<?php

class Root
{
    private(set) SplObjectStorage $thread;
    public Closure $inform {
        set (Closure $ation) => $this->inform = fn(object $at, ...$ion) : array|object => [[$ionical] = array_values([[$ic] = array_values(get_object_vars($at)), [$al] = array_values((array) ($ion))])];
    }
    public Closure $deform {
        set (Closure $ation) => $this->deform = fn($ation) : string => trim(array_reduce($ation, function (mixed $item): string {
                return strval(match(get_debug_type($item)) {
                    "bool" => $item ? 'true' : 'false', // strtolower
                    "int" => (string) $item,
                    "float" => $item % 2 ? (int)$item : (string)$item,
                    "string" => $item,
                    "array" => '[' . ($this->deform)($item) . ']',
                    // "resource (closed)" => false, // blob
                    default => is_resource($item) ? (match(gettype($item)) {
                        'resource' => function ($item) {
                                if(is_resource($item) && ftell($item) > 0) {
                                    rewind($item);
                                }
                                rewind($item);
                                $data = stream_get_contents($item);
                                $status = 'success';
                                return ($this->deform)([
                                    'blob_data' => match ($blob=substr(get_debug_type($item), 10, -1)) {
                                        'closed' => 'The resource has been closed.',
                                        'stream' => base64_encode($data),
                                        'gd' => function ($item) {
                                            ob_start();
                                            imagepng($item); 
                                            $data = ob_get_contents();
                                            ob_end_clean();
                                            return $data;
                                        },
                                        'curl'   => function($item) {
                                            curl_setopt($item, CURLOPT_RETURNTRANSFER, true);
                                            $data = curl_exec($item);
                                            if ($data === false) {
                                                $data = curl_error($item);
                                                $status = 'failure';
                                            }
                                        },
                                        default  => "Processing unhandled type: {$blob}",
                                    },
                                    'status' => $status,
                                    'mime_type' => 'application/octet-stream'
                                ]);
                        },
                        default => false // closed
                    }) : (fn($a) => '{' . implode(',', array_map(fn($k, $v) => '"' . $k . '":"' . $v . '"', array_keys($a), array_values($a))) . '}')((array)$item) // object
                }) . ",";
            }), ",");
    }

    public function __toString()
    {
        $strung = "";
        $inform = fn($ion, $info) => $info;
        $this->thread->rewind();
        while($this->thread->valid()) {
            $index = $this->thread->key();
            $element = $this->thread->current();
            $id = spl_object_id($element);
            $info = $this->thread->getInfo();
            $information = ($this->inform)($element, $info);
            $identity = ($this->deform)($information);
            $strung .= $identity;
            $this->thread->next();
        }
        return $strung;
        throw new \Exception('Not implemented');
    }
}
