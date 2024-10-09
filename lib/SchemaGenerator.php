<?php

namespace futuretek\gii\openapi\server\lib;

use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Reference;
use cebe\openapi\spec\Schema;
use futuretek\shared\Tools;
use Laminas\Code\Generator\ClassGenerator;
use Laminas\Code\Generator\DocBlock\Tag\GenericTag;
use Laminas\Code\Generator\DocBlock\Tag\VarTag;
use Laminas\Code\Generator\DocBlockGenerator;
use Laminas\Code\Generator\FileGenerator;
use Laminas\Code\Generator\PropertyGenerator;
use Laminas\Code\Generator\TypeGenerator;
use yii\base\NotSupportedException;
use yii\gii\CodeFile;
use yii\helpers\Inflector;

class SchemaGenerator
{
    public OpenApi $openApi;
    public string $schemaNamespace;
    public string $enumNamespace;

    public function __construct(OpenApi $openApi, string $schemaNamespace, string $enumNamespace)
    {
        $this->openApi = $openApi;
        $this->schemaNamespace = $schemaNamespace;
        $this->enumNamespace = $enumNamespace;
    }

    public function generate(): array
    {
        /** @var CodeFile[] $files */
        $files = [];
        $enums = [];

        $basePath = Utils::getPathFromNamespace($this->schemaNamespace);

        foreach ($this->openApi->components->schemas as $schemaName => $schema) {
            if ($schema->type === 'object') {
                $classGenerator = new ClassGenerator(
                    $schemaName,
                    $this->schemaNamespace,
                    null,
                    'yii\base\BaseObject'
                );

                $docBlockGenerator = new DocBlockGenerator($schema->description);
                $tags = [];
                $tags[] = new GenericTag('package', $this->schemaNamespace);
                if ($schema->deprecated) {
                    $tags[] = new GenericTag('deprecated', 'This scheme has been deprecated');
                }

                $docBlockGenerator->setTags($tags);
                $classGenerator->setDocBlock($docBlockGenerator);

                foreach ($schema->properties as $propName => $property) {
                    if ($property instanceof Reference) {
                        $propertyResolved = $property->resolve();
                    } else {
                        $propertyResolved = $property;
                    }
                    $enumName = null;
                    if (!empty($propertyResolved->enum)) {
                        $enumName = $propertyResolved->{'x-enum'} ?? ucfirst($schemaName) . Inflector::camelize($propName);
                        $enums[$enumName] = $propertyResolved->enum;
                    }
                    $classGenerator->addPropertyFromGenerator($this->generateProperty($schema, $propName, $property, $enumName));
                }

                $fileGenerator = new FileGenerator();
                $fileGenerator->setDocBlock($this->getFileDocBlock());
                $fileGenerator->setClass($classGenerator);

                $files[] = new CodeFile(
                    "$basePath/$schemaName.php",
                    $fileGenerator->generate()
                );
            } elseif (!empty($schema->enum)) {
                $enumName = $schema->{'x-enum'} ?? ucfirst($schemaName);
                $enums[$enumName] = $schema->enum;
            } else {
                throw new NotSupportedException("Schema type {$schema->type} not supported.");
            }
        }

        foreach ($enums as $enumName => $enumValues) {
            $files[] = $this->generateEnum($enumName, $enumValues);
        }

        return $files;
    }

    protected function generateProperty(Schema $schema, string $name, Schema|Reference $property, string|null $enumName): PropertyGenerator
    {
        $isRequired = in_array($name, $schema->required ?? []) && ($property instanceof Schema && !$property->nullable);
        $type = Utils::convertType($property, $isRequired, $enumName);
        $isArray = str_contains($type, '[]');
        $defaultValue = $property->default ?? ($isArray ? [] : null);

        $propGen = new PropertyGenerator($name);
        $docGen = new DocBlockGenerator();
        $tags = [];

        $propGen->setVisibility(PropertyGenerator::VISIBILITY_PUBLIC);
        $propGen->setType(TypeGenerator::fromTypeString($isArray ? 'array' : $type));
        $propGen->omitDefaultValue($isRequired && empty($defaultValue));
        $propGen->setDefaultValue($defaultValue);
        if ($property instanceof Schema) {
            $tags[] = new VarTag(null, $type, $property->description);
        }

        if ($enumName) {
            $tags[] = new GenericTag('see', '\\' . Config::$enumNamespace . '\\' . $enumName . ' for allowed vaules');
        }

        $docGen->setTags($tags);
        $propGen->setDocBlock($docGen);

        return $propGen;
    }

    protected function getFileDocBlock(): DocBlockGenerator
    {
        $gen = new DocBlockGenerator();
        $gen->setShortDescription('WARNING: This file has been generated');
        $gen->setLongDescription('Do not edit this file directly. All changes will be overwritten!');

        $tags = [];
        $tags[] = new GenericTag('generated', 'FTS OpenAPI Generator');
        $gen->setTags($tags);

        return $gen;
    }

    protected function removeAccents($value)
    {
        $remove = '{}[]()/\\.!@#$%^&*+|\'"<>:`;?';
        $factory = \Transliterator::createFromRules(':: Any-Latin; :: Latin-ASCII; :: NFD; :: [:Nonspacing Mark:] Remove; :: Lower(); :: NFC;', \Transliterator::FORWARD);
        $value = $factory->transliterate($value);
        $value = str_replace(str_split($remove, 1), '', $value);
        $value = str_replace(['-', ' '], '_', $value);

        return $value;
    }

    protected function generateEnum(string $className, array $values): CodeFile
    {
        $basePath = Utils::getPathFromNamespace($this->enumNamespace);

        $classGenerator = new ClassGenerator(
            $className,
            $this->enumNamespace
        );

        $docBlockGenerator = new DocBlockGenerator('Enum ' . $className);
        $tags = [];
        $tags[] = new GenericTag('package', $this->enumNamespace);

        $docBlockGenerator->setTags($tags);
        $classGenerator->setDocBlock($docBlockGenerator);

        foreach ($values as $value) {
            $classGenerator->addConstant(strtoupper($this->removeAccents($value)), $value);
        }

        $fileGenerator = new FileGenerator();
        $fileGenerator->setDocBlock($this->getFileDocBlock());
        $fileGenerator->setClass($classGenerator);

        return new CodeFile(
            "$basePath/$className.php",
            $fileGenerator->generate()
        );
    }
}
