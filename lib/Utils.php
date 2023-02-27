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
                        return self::schemaToType($schema->items) . '[]';
                    }
                    throw new InvalidArgumentException("Unsupported array configuration.");
                }

                return 'array';
            case 'object':
                //todo
            default:
                throw new InvalidArgumentException("Unsupported property type {$property->type}");
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
