<?php

namespace Swaggest\JsonDiff;

use Swaggest\JsonDiff\JsonPatch\Add;
use Swaggest\JsonDiff\JsonPatch\Copy;
use Swaggest\JsonDiff\JsonPatch\Move;
use Swaggest\JsonDiff\JsonPatch\OpPath;
use Swaggest\JsonDiff\JsonPatch\OpPathFrom;
use Swaggest\JsonDiff\JsonPatch\OpPathValue;
use Swaggest\JsonDiff\JsonPatch\Remove;
use Swaggest\JsonDiff\JsonPatch\Replace;
use Swaggest\JsonDiff\JsonPatch\Test;

/**
 * JSON Patch is specified in [RFC 6902](http://tools.ietf.org/html/rfc6902) from the IETF.
 *
 * Class JsonPatch
 */
class JsonPatch implements \JsonSerializable
{
    /**
     * Disallow converting empty array to object for key creation
     * @see JsonPointer::STRICT_MODE
     */
    const STRICT_MODE = 2;

    /**
     * Allow associative arrays to mimic JSON objects (not recommended)
     */
    const TOLERATE_ASSOCIATIVE_ARRAYS = 8;


    private $flags = 0;

    /**
     * @param int $options
     * @return $this
     */
    public function setFlags($options)
    {
        $this->flags = $options;
        return $this;
    }

    /** @var OpPath[] */
    private $operations = array();

    /**
     * @param array $data
     * @return JsonPatch
     * @throws Exception
     */
    public static function import(array $data)
    {
        $result = new JsonPatch();
        foreach ($data as $operation) {
            /** @var OpPath|OpPathValue|OpPathFrom|array $operation */
            if (is_array($operation)) {
                $operation = (object)$operation;
            }

            if (!is_object($operation)) {
                throw new Exception('Invalid patch operation - should be a JSON object');
            }

            if (!isset($operation->op)) {
                throw new MissingFieldException('op', $operation);
            }
            if (!isset($operation->path)) {
                throw new MissingFieldException('path', $operation);
            }

            $op = null;
            switch ($operation->op) {
                case Add::OP:
                    $op = new Add();
                    break;
                case Copy::OP:
                    $op = new Copy();
                    break;
                case Move::OP:
                    $op = new Move();
                    break;
                case Remove::OP:
                    $op = new Remove();
                    break;
                case Replace::OP:
                    $op = new Replace();
                    break;
                case Test::OP:
                    $op = new Test();
                    break;
                default:
                    throw new UnknownOperationException($operation);
            }
            $op->path = $operation->path;
            if ($op instanceof OpPathValue) {
                if (property_exists($operation, 'value')) {
                    $op->value = $operation->value;
                } else {
                    throw new MissingFieldException('value', $operation);
                }
            } elseif ($op instanceof OpPathFrom) {
                if (!isset($operation->from)) {
                    throw new MissingFieldException('from', $operation);
                }
                $op->from = $operation->from;
            }
            $result->operations[] = $op;
        }
        return $result;
    }

    public static function export(JsonPatch $patch)
    {
        $result = array();
        foreach ($patch->operations as $operation) {
            $result[] = (object)(array)$operation;
        }

        return $result;
    }

    public function op(OpPath $op)
    {
        $this->operations[] = $op;
        return $this;
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return self::export($this);
    }

    /**
     * @param mixed $original
     * @param bool $stopOnError
     * @return Exception[] array of errors
     * @throws Exception
     */
    public function apply(&$original, $stopOnError = true)
    {
        $errors = array();
        foreach ($this->operations as $operation) {
            try {
                $pathItems = $this->splitPath($operation, 'path');
                switch (true) {
                    case $operation instanceof Add:
                        $this->add($operation, $original, $pathItems, $operation->value);
                        break;
                    case $operation instanceof Copy:
                        $fromItems = $this->splitPath($operation, 'from');
                        $value = $this->get($operation, 'from', $original, $fromItems);
                        $this->add($operation, $original, $pathItems, $value);
                        break;
                    case $operation instanceof Move:
                        $fromItems = $this->splitPath($operation, 'from');
                        $value = $this->get($operation, 'from', $original, $fromItems);
                        $this->remove($operation, 'from', $original, $fromItems);
                        $this->add($operation, $original, $pathItems, $value);
                        break;
                    case $operation instanceof Remove:
                        $this->remove($operation, 'path', $original, $pathItems);
                        break;
                    case $operation instanceof Replace:
                        $this->get($operation, 'path', $original, $pathItems);
                        $this->remove($operation, 'path', $original, $pathItems);
                        $this->add($operation, $original, $pathItems, $operation->value);
                        break;
                    case $operation instanceof Test:
                        $value = $this->get($operation, 'path', $original, $pathItems);
                        $diff = new JsonDiff($operation->value, $value,
                            JsonDiff::STOP_ON_DIFF);
                        if ($diff->getDiffCnt() !== 0) {
                            throw new PatchTestOperationFailedException($operation, $value);
                        }
                        break;
                }
            } catch (Exception $exception) {
                if ($stopOnError) {
                    throw $exception;
                } else {
                    $errors[] = $exception;
                }
            }
        }
        return $errors;
    }

    private function splitPath(OpPath $operation, $field)
    {
        try {
            return JsonPointer::splitPath($operation->$field);
        } catch (Exception $exception) {
            throw new PathException($exception->getMessage(), $operation, $field, $exception->getCode());
        }
    }

    private function add(OpPath $operation, &$original, array $pathItems, $value)
    {
        try {
            JsonPointer::add($original, $pathItems, $value, $this->flags);
        } catch (Exception $exception) {
            throw new PathException($exception->getMessage(), $operation, 'path', $exception->getCode());
        }
    }

    private function get(OpPath $operation, $field, $original, array $pathItems)
    {
        try {
            return JsonPointer::get($original, $pathItems);
        } catch (Exception $exception) {
            throw new PathException($exception->getMessage(), $operation, $field, $exception->getCode());
        }
    }

    private function remove($operation, $field, &$original, array $pathItems)
    {
        try {
            JsonPointer::remove($original, $pathItems, $this->flags);
        } catch (Exception $exception) {
            throw new PathException($exception->getMessage(), $operation, $field, $exception->getCode());
        }
    }

}
