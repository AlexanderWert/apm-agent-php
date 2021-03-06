<?php

declare(strict_types=1);

namespace ElasticApmTests\Util\Deserialization;

use Elastic\Apm\Impl\ProcessData;
use ElasticApmTests\Util\ValidationUtil;

/**
 * Code in this file is part of implementation internals and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
final class ProcessDataDeserializer extends DataDeserializer
{
    /** @var ProcessData */
    private $result;

    private function __construct(ProcessData $result)
    {
        $this->result = $result;
    }

    /**
     *
     * @param array<string, mixed> $deserializedRawData
     *
     * @return ProcessData
     */
    public static function deserialize(array $deserializedRawData): ProcessData
    {
        $result = new ProcessData();
        (new self($result))->doDeserialize($deserializedRawData);
        ValidationUtil::assertValidProcessData($result);
        return $result;
    }

    /**
     * @param string $key
     * @param mixed  $value
     *
     * @return bool
     */
    protected function deserializeKeyValue(string $key, $value): bool
    {
        switch ($key) {
            case 'pid':
                $this->result->pid = ValidationUtil::assertValidProcessId($value);
                return true;

            default:
                return false;
        }
    }
}
