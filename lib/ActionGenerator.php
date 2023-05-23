<?php

namespace futuretek\gii\openapi\server\lib;

use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Operation;
use cebe\openapi\spec\Parameter;
use cebe\openapi\spec\PathItem;
use cebe\openapi\spec\Reference;
use cebe\openapi\spec\Schema;
use futuretek\shared\Date;
use futuretek\shared\ObjectMapper;
use Laminas\Code\Generator\ClassGenerator;
use Laminas\Code\Generator\DocBlock\Tag\GenericTag;
use Laminas\Code\Generator\DocBlock\Tag\ReturnTag;
use Laminas\Code\Generator\DocBlock\Tag\TagInterface;
use Laminas\Code\Generator\DocBlock\Tag\ThrowsTag;
use Laminas\Code\Generator\DocBlockGenerator;
use Laminas\Code\Generator\FileGenerator;
use Laminas\Code\Generator\MethodGenerator;
use Laminas\Code\Generator\ParameterGenerator;
use Laminas\Code\Generator\PropertyGenerator;
use Laminas\Code\Generator\TypeGenerator;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\gii\CodeFile;
use yii\helpers\ArrayHelper;
use yii\helpers\Inflector;
use yii\web\BadRequestHttpException;
use yii\web\ConflictHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\GoneHttpException;
use yii\web\HttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotAcceptableHttpException;
use yii\web\NotFoundHttpException;
use yii\web\RangeNotSatisfiableHttpException;
use yii\web\ServerErrorHttpException;
use yii\web\TooManyRequestsHttpException;
use yii\web\UnauthorizedHttpException;
use yii\web\UnprocessableEntityHttpException;
use yii\web\UnsupportedMediaTypeHttpException;

class ActionGenerator
{
    public OpenApi $openApi;
    public string $schemaNamespace;
    public string $urlPrefix;

    public function __construct(OpenApi $openApi, string $schemaNamespace, string $urlPrefix)
    {
        $this->openApi = $openApi;
        $this->schemaNamespace = $schemaNamespace;
        $this->urlPrefix = $urlPrefix;
    }

    public function generate(): array
    {
        ///api/issue/{id}/assign -> modules/api/controllers/IssueController::actionAssign($id)
        ///api/codelist/developers -> modules/api/CodelistController::actionDevelopers()
        ///api/plan/sprint/{id}/current -> modules/api/plan/controllers/SprintController::actionCurrent($id)
        ///api/plan/sprint/{id}/issue/{issueid} -> modules/api/plan/controllers/SprintController::actionIssueDelete($id, $issueid)

        /** @var CodeFile[] $files */
        $files = [];

        /** @var ControllerData[] $controllers */
        $controllers = [];

        $routes = [];

        foreach ($this->openApi->paths as $path => $pathItem) {
            $pathPrefixed = rtrim($this->urlPrefix, '/') . '/' . ltrim($path, '/');
            if ($pathItem === null) {
                continue;
            }
            if ($pathItem instanceof Reference) {
                $pathItem = $pathItem->resolve();
            }

            $parameters = $pathItem->parameters;

            foreach (['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'] as $methodName) {
                if (!empty($pathItem->{$methodName})) {
                    $actionData = $this->parsePath($pathPrefixed, $pathItem);
                    $controllerName = $actionData->controllerNs . '\\' . $actionData->controllerClass;
                    if (!array_key_exists($controllerName, $controllers)) {
                        $controllers[$controllerName] = new ControllerData([
                            'data' => $actionData,
                            'methods' => [],
                        ]);
                    }

                    $actionData->actionName .= ucfirst($methodName);
                    $controllers[$controllerName]->methods[] = $this->generateAction($pathPrefixed, $pathItem->{$methodName}, $actionData, $parameters);

                    $fnName = $pathItem->{$methodName}->operationId ?? $actionData->actionName;
                    $routes[strtoupper($methodName) . ' ' . $this->convertPathVariables($path)] = $this->parseControllerUri($actionData) . '/' . Inflector::camel2id($fnName);
                }
            }
        }

        foreach ($controllers as $controllerId => $controller) {
            $classGenerator = new ClassGenerator(
                $controller->data->controllerClass,
                $controller->data->controllerNs,
                null,
                'yii\rest\Controller'
            );
            $classGenerator->addUse('futuretek\shared\ObjectMapper');

            $docBlockGenerator = new DocBlockGenerator();
            $docBlockGenerator->setTags([new GenericTag('package', $this->schemaNamespace)]);
            $classGenerator->setDocBlock($docBlockGenerator);

            foreach ($controller->methods as $method) {
                $classGenerator->addMethodFromGenerator($method);
            }

            $fileGenerator = new FileGenerator();
            $fileGenerator->setDocBlock($this->getFileDocBlock());
            $fileGenerator->setClass($classGenerator);

            $basePath = Utils::getPathFromNamespace($controller->data->controllerNs);

            $files[] = new CodeFile(
                "$basePath/" . $controller->data->controllerClass . ".php",
                $fileGenerator->generate()
            );
        }

        $rfDbGen = new DocBlockGenerator();
        $rfDbGen->setShortDescription('WARNING: This file has been generated');
        $rfDbGen->setLongDescription('Do not edit this file directly. All changes will be overwritten!');

        $rfGen = new FileGenerator();
        $rfGen->setDocBlock($rfDbGen);
        $rfGen->setBody('return ' . var_export($routes, true) . ';');

        $files[] = new CodeFile(\Yii::getAlias(Config::$routeFile), $rfGen->generate());

        return $files;
    }

    protected function generateAction($path, Operation $operation, ActionData $actionData, array $parameters = []): MethodGenerator
    {
        $generator = new MethodGenerator('action' . ucfirst($operation->operationId ?? $actionData->actionName));
        $docBlockGenerator = new DocBlockGenerator($operation->summary);
        $tags = [];

        $parameters = $this->mergeParams($parameters, $operation->parameters);
        $opParams = [];
        foreach ($parameters as $parameter) {
            if ($parameter->in !== 'path' && $parameter->in !== 'query') {
                continue;
            }
            $isRequired = in_array($parameter->name, $parameter->schema->required ?? []) || $parameter->required;
            $pGen = new ParameterGenerator($parameter->name, Utils::convertType($parameter->schema, $isRequired));
            if (!$isRequired) {
                $pGen->setDefaultValue(null);
                $pGen->omitDefaultValue(false);
            }

            $opParams[] = $pGen;
        }

        $generator->setParameters($opParams);

        //Request body
        $requestType = null;
        if ($operation->requestBody) {
            if (!isset($operation->requestBody->content)) {
                throw new InvalidConfigException('Request body without content is not supported.');
            }
            if (!isset($operation->requestBody->content['application/json'])) {
                throw new InvalidConfigException('Only application/json media type is supported.');
            }

            $requestType = Utils::convertType($operation->requestBody->content['application/json']->schema, $operation->requestBody->required ?? false);
        }

        //Response
        $responseType = null;
        $exceptions = [];
        foreach ($operation->responses ?? [] as $responseCode => $response) {
            $responseCodeType = (int)floor($responseCode / 100);
            if (count($response->content) === 0) {
                continue;
            }
            if (!isset($response->content['application/json'])) {
                throw new InvalidConfigException('Only application/json media type is supported.');
            }

            $convertType = Utils::convertType($response->content['application/json']->schema, true);
            if ($responseCodeType === 2) {
                $responseType = $convertType;
                $tags[] = new ReturnTag($responseType, $response->description ?? null);
            } elseif (in_array($responseCodeType, [4, 5], true)) {
                $exceptions[$responseCode] = $convertType;
                $tags[] = new ThrowsTag($convertType, $response->description ?? null);
            }
        }

        //Body
        $body = '';
        //$hasExeptions = count($exceptions) > 0 ? 1 : 0;
        $hasExeptions = 0;
        if ($hasExeptions) {
            $body .= $this->bodyLine("try {");
        }
        if ($requestType) {
            $body .= $this->bodyLine("\$request = ObjectMapper::configureRecursive(new {$requestType}(), \Yii::\$app->request->getRawBody());\n", $hasExeptions);
        }
        $body .= $this->bodyLine('//todo: implement method', $hasExeptions);

        if ($responseType) {
            $body .= $this->bodyLine("\$response = [];\n", $hasExeptions);
            $body .= $this->bodyLine("return \$response;", $hasExeptions);
        } else {
            $body .= $this->bodyLine("return null;", $hasExeptions);
        }

        if ($hasExeptions) {
            foreach ($exceptions as $exCode => $exception) {
                $exceptionClass = $this->exceptionByCode($exCode);
                $body .= $this->bodyLine("} catch ($exceptionClass \$e) {");
                $body .= $this->bodyLine("return new $exception([", 1);
                $body .= $this->bodyLine("]);", 1);
            }
            $body .= $this->bodyLine("}");
        }

        $docBlockGenerator->setTags($tags);
        $generator->setDocBlock($docBlockGenerator);

        $generator->setBody($body);

        return $generator;
    }

    protected function exceptionByCode($code)
    {
        if ($code / 100 === 5) {
            return ServerErrorHttpException::class;
        }

        return '\\' . match ($code) {
                400 => BadRequestHttpException::class,
                401 => UnauthorizedHttpException::class,
                403 => ForbiddenHttpException::class,
                404 => NotFoundHttpException::class,
                405 => MethodNotAllowedHttpException::class,
                406 => NotAcceptableHttpException::class,
                409 => ConflictHttpException::class,
                410 => GoneHttpException::class,
                415 => UnsupportedMediaTypeHttpException::class,
                416 => RangeNotSatisfiableHttpException::class,
                422 => UnprocessableEntityHttpException::class,
                429 => TooManyRequestsHttpException::class,
                default => HttpException::class,
            };
    }

    protected function bodyLine($code = "\n", $padding = 0)
    {
        return str_repeat("\t", $padding) . $code . "\n";
    }

    protected function convertPathVariables(string $path): string
    {
        $parts = explode('/', $path);
        foreach ($parts as &$part) {
            if (preg_match('/^\{.*\}$/', $part)) {
                $variable = trim($part, '{}');
                $type = '\S+';
                $part = '<' . $variable . ':' . $type . '>';
            }
        }

        return implode('/', $parts);
    }

    protected function parseControllerUri(ActionData $data): string
    {
        $prefix = 'app\\' . Config::$urlPrefix;

        $parts = explode('\\', str_replace($prefix, '', $data->controllerNs));
        $parts[] = str_replace('Controller', '', $data->controllerClass);

        $result = [];
        foreach ($parts as $part) {
            if (!$part || $part === 'controllers') {
                continue;
            }
            $result[] = Inflector::camel2id($part);
        }

        return implode('/', $result);
    }

    protected function parsePath(string $path, $pathItem): ActionData
    {
        $parts = explode('/', $path);
        $variables = [];
        $controller = [];
        $action = [];
        $cPart = true;
        $customControllerClass = $pathItem->{'x-controller'} ?? null;
        $customNs = $pathItem->{'x-ns'} ?? null;

        foreach ($parts as $part) {
            if (empty($part)) {
                continue;
            }
            if (preg_match('/^\{.*\}$/', $part)) {
                $variables[] = trim($part, '{}');
                $cPart = false;
            } else {
                if ($cPart) {
                    $controller[] = $part;
                } else {
                    $action[] = $part;
                }
            }
        }

        if (count($action) === 0 && count($controller) > 1) {
            $action[] = array_pop($controller);
        }

        if (count($controller) === 0 || count($action) === 0) {
            throw new InvalidConfigException("Path $path cannot be parsed.");
        }

        $result = new ActionData();

        if ($customControllerClass) {
            if (!str_contains($customControllerClass, 'Controller')) {
                $customControllerClass .= 'Controller';
            }
            $result->controllerClass = $customControllerClass;
        } else {
            $result->controllerClass = Inflector::id2camel(array_pop($controller)) . 'Controller';
        }

        $controller[] = 'controllers';
        $result->controllerNs = $customNs ? $customNs : 'app\\' . implode('\\', $controller);
        $result->actionName = 'action' . Inflector::id2camel(implode('-', $action));

        return $result;
    }

    protected function parseActions()
    {
        $actions = [];

        $actions = [];
        foreach ($this->openApi->paths as $path => $pathItem) {
            if ($path[0] !== '/') {
                throw new InvalidConfigException('Path must begin with /');
            }
            if ($pathItem === null) {
                continue;
            }
            if ($pathItem instanceof Reference) {
                $pathItem = $pathItem->resolve();
            }

            $actions[] = $this->resolvePath($path, $pathItem);
        }

        return $actions;
    }


    /**
     * @param Parameter[] $parentParams
     * @param Parameter[] $params
     * @return Parameter[]
     */
    protected function mergeParams(array $parentParams, array $params)
    {
        foreach ($parentParams as &$param) {
            if ($param instanceof Reference) {
                $param = $param->resolve();
            }
        }
        foreach ($params as &$param) {
            if ($param instanceof Reference) {
                $param = $param->resolve();
            }
        }

        $parentParams = ArrayHelper::index($parentParams, 'name');
        $params = ArrayHelper::index($params, 'name');

        return array_replace($parentParams, $params);
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
}
