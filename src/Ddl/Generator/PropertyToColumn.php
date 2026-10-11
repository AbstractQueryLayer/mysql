<?php

declare(strict_types=1);

namespace IfCastle\AQL\MySql\Ddl\Generator;

use IfCastle\AQL\Entity\Property\PropertyInterface;
use IfCastle\AQL\Generator\Ddl\PropertyToColumnAbstract;

class PropertyToColumn extends PropertyToColumnAbstract
{
    #[\Override]
    protected function defineColumnType(): array
    {
        return match ($this->property->getType()) {
            PropertyInterface::T_BOOLEAN   => ['TINYINT', 3, null],
            PropertyInterface::T_STRING    => ['VARCHAR', $this->property->getMaxLength() ?? 255, null],
            PropertyInterface::T_ENUM      => ['ENUM', null, null],
            PropertyInterface::T_INT       => ['INTEGER', $this->property->getMaxLength(), null],
            PropertyInterface::T_BIG_INT   => ['BIGINT', $this->property->getMaxLength(), null],
            PropertyInterface::T_FLOAT     => ['FLOAT', $this->property->getMaxLength(), null],
            PropertyInterface::T_UUID      => ['CHAR', 36, null],
            PropertyInterface::T_ULID      => ['CHAR', 26, null],
            PropertyInterface::T_BINARY    => ['BINARY', $this->property->getMaxLength(), null],
            PropertyInterface::T_DATE      => ['DATE', null, null],
            PropertyInterface::T_YEAR      => ['YEAR', null, null],
            PropertyInterface::T_DATETIME  => ['DATETIME', null, null],
            PropertyInterface::T_TIME      => ['TIME', null, null],
            PropertyInterface::T_TIMESTAMP => ['TIMESTAMP', null, null],
            PropertyInterface::T_JSON,
            PropertyInterface::T_LIST,
            PropertyInterface::T_TEXT,
            PropertyInterface::T_OBJECT    => ['TEXT', null, null],

            default                        => throw new \ErrorException(
                'Unknown property type: ' . $this->property->getType()
            ),
        };
    }
}
