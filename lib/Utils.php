<?php

namespace futuretek\gii\openapi\server\lib;

use cebe\openapi\spec\Reference;
use cebe\openapi\spec\Schema;
use futuretek\shared\Date;
use Laminas\Code\Generator\TypeGenerator;
use yii\base\InvalidArgumentException;

class Utils
{
    public static function getPathFromNamespace(string $namespace): string
    {
        return \Yii::getAlias('@' . str_replace('\\', '/', $namespace));
    }

    public static function schemaToType(Schema $schema): string
    {
        switch ($schema?->type) {
            case 'string':
                switch ($schema?->format) {
                    case 'date':
                        return '\\' . Date::class;
                    case 'date-time':
                        return '\\' . \DateTime::class;
                    default:
                        return 'string';
                }
            case 'number':
                switch ($schema?->format) {
                    case 'double':
                        return 'double';
                    default:
                        return 'float';
                }
            case 'integer':
                return 'int';
            case 'boolean':
                return 'bool';
            case 'array':
                if ($schema?->items) {
                    if ($schema->items instanceof Reference) {
                        return self::schemaFromRef($schema->items) . '[]';
                    }
                    if ($schema->items instanceof Schema) {
                        $schemaToType = self::schemaToType($schema->items);
                        if (in_array($schemaToType, ['int', 'bool', 'string', 'float'])) {
                            return 'array';
                        }

                        return $schemaToType . '[]';
                    }
                    throw new InvalidArgumentException("Unsupported array configuration.");
                }

                return 'array';
            case 'object':
                if ($schema?->additionalProperties) {
                    if ($schema->additionalProperties instanceof Reference) {
                        return self::schemaFromRef($schema->additionalProperties) . '[]';
                    }
                    if ($schema->additionalProperties instanceof Schema) {
                        $schemaToType = self::schemaToType($schema->additionalProperties);
                        if (in_array($schemaToType, ['int', 'bool', 'string', 'float'])) {
                            return 'array';
                        }

                        return $schemaToType . '[]';
                    }
                    throw new InvalidArgumentException("Unsupported associative array configuration.");
                }
            default:
                throw new InvalidArgumentException("Unsupported property type {$schema?->type}");
        }
    }

    public static function schemaTypeToRegex(string $type): string
    {
        switch ($type) {
            case 'number':
                return '[\d\.]+';
            case 'integer':
                return '\d+';
            case 'boolean':
                return '(true|false|1|0)';
            default:
                return '\S+';
        }
    }

    public static function convertType(Schema|Reference $property, bool $isRequired): string
    {
        $types = [];

        if ($property instanceof Reference) {
            $types[] = self::schemaFromRef($property);
            if (!$isRequired) {
                $types[] = 'null';
            }
        } else {
            $typeDefs = [];
            if (!empty($property->type)) {
                $types[] = self::schemaToType($property);
            }

            foreach ($property->oneOf ?? [] as $oneOf) {
                if (!empty($oneOf->type)) {
                    $types[] = self::schemaToType($oneOf);
                }
            }

            if (!$isRequired || $property?->nullable) {
                $types[] = 'null';
            }
        }

        return implode('|', $types);
    }

    public static function convertTypeGen(Schema|Reference $property, bool $isRequired): TypeGenerator
    {
        return TypeGenerator::fromTypeString(self::convertType($property, $isRequired));
    }

    public static function schemaFromRef(Reference $ref): string
    {
        $reference = $ref->getReference();

        return '\\' . Config::$schemaNamespace . '\\' . substr($reference, strrpos($reference, '/') + 1);
    }
}
