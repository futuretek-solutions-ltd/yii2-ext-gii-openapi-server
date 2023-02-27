<?php

namespace futuretek\gii\openapi\server;

use cebe\openapi\Reader;
use \cebe\openapi\spec\OpenApi;
use futuretek\gii\openapi\server\lib\ActionGenerator;
use futuretek\gii\openapi\server\lib\Config;
use futuretek\gii\openapi\server\lib\SchemaGenerator;
use Yii;
use yii\helpers\StringHelper;

class Generator extends \yii\gii\Generator
{
    /**
     * @var string OpenAPI specification file. Can be an absolute path or a path alias.
     */
    public $openApiFile;

    /**
     * @var bool this flag controls whether files should be generated even if the spec contains errors.
     * If this is true, the spec will not be validated. Defaults to false.
     */
    public $ignoreSpecErrors = false;

    /**
     * @var string file name for URL rules.
     */
    public $routeFile = '@app/config/routes.api.php';

    /**
     * @var string url prefix for actions namespace guess
     */
    public $urlPrefix;

    /**
     * @var bool namespace to create schemas in. Defaults to `app\schema`.
     */
    public $schemaNamespace = 'app\\schema';

    /**
     * @var bool namespace to create enums in. Defaults to `app\enums`.
     */
    public $enumNamespace = 'app\\enums';

    /**
     * @var OpenApi
     */
    private $_openApi;

    /**
     * @return string name of the code generator
     */
    public function getName()
    {
        return 'OpenAPI Server Generator';
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription()
    {
        return 'This generator generates OpenAPI server from OpenAPI 3 specification.';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return array_merge(parent::rules(), [
                [['openApiFile', 'routeFile', 'urlPrefix', 'schemaNamespace', 'enumNamespace'], 'filter', 'filter' => 'trim'],
                [['openApiFile', 'schemaNamespace', 'enumNamespace'], 'required'],
                [['ignoreSpecErrors'], 'boolean'],
                ['openApiFile', 'validateSpec'],
            ]
        );
    }

    /**
     * Validate OpenAPI spec file
     * @param string $attribute
     */
    public function validateSpec($attribute): void
    {
        if ($this->ignoreSpecErrors) {
            return;
        }
        $openApi = $this->getApiSpec();
        if (!$openApi->validate()) {
            $this->addError($attribute, 'Failed to validate OpenAPI spec:' . Html::ul($openApi->getErrors()));
        }
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return array_merge(parent::attributeLabels(), [
            'openApiFile' => 'OpenAPI 3 Spec file',
            'ignoreSpecErrors' => 'Ignore errors',
            'routeFile' => 'URL route rules file',
            'urlPrefix' => 'URL prefix',
            'schemaNamespace' => 'Schema namespace',
            'enumNamespace' => 'Enum namespace',
        ]);
    }

    /**
     * @inheritdoc
     */
    public function hints()
    {
        return array_merge(parent::hints(), [
            'openApiFile' => 'Path to the OpenAPI 3 Spec file.',
            'ignoreSpecErrors' => 'Ignore errors in OpenAPI spec file.',
            'routeFile' => 'URL rules will be generated to this file. If empty no rules will be generated.',
            'urlPrefix' => 'URL prefix for action endpoints. Can be empty.',
            'schemaNamespace' => 'Schema classes will be generated to this directory/namespace.',
            'enumNamespace' => 'Enum classes will be generated to this directory/namespace.',
        ]);
    }

    /**
     * @inheritdoc
     */
    public function stickyAttributes()
    {
        return array_merge(parent::stickyAttributes(), [
            'openApiFile',
            'ignoreSpecErrors',
            'routeFile',
            'urlPrefix',
            'schemaNamespace',
            'enumNamespace',
        ]);
    }

    /**
     * Generates the code based on the current user input and the specified code template files.
     * This is the main method that child classes should implement.
     * Please refer to [[\yii\gii\generators\controller\Generator::generate()]] as an example
     * on how to implement this method.
     * @return CodeFile[] a list of code files to be created.
     * @throws \Exception
     */
    public function generate(): array
    {
        Config::$schemaNamespace = $this->schemaNamespace;
        Config::$enumNamespace = $this->enumNamespace;
        Config::$urlPrefix = $this->urlPrefix;
        Config::$routeFile = $this->routeFile;

        $schemas = Yii::createObject(SchemaGenerator::class, [
            'openApi' => $this->_openApi,
            'schemaNamespace' => $this->schemaNamespace,
            'enumNamespace' => $this->enumNamespace,
        ])->generate();

        $actions = Yii::createObject(ActionGenerator::class, [
            'openApi' => $this->_openApi,
            'schemaNamespace' => $this->schemaNamespace,
            'urlPrefix' => $this->urlPrefix,
        ])->generate();

        //$controllersGenerator = Yii::createObject(ControllersGenerator::class, [$config, $actions]);
        //$files->merge($controllersGenerator->generate());

        //$urlRulesGenerator = Yii::createObject(UrlRulesGenerator::class, [$config, $actions]);
        //$files = $urlRulesGenerator->generate();

        return array_merge($schemas, $actions);
    }

    protected function getApiSpec(): OpenApi
    {
        if ($this->_openApi === null) {
            $file = Yii::getAlias($this->openApiFile);
            if (str_ends_with($this->openApiFile, '.json')) {
                $this->_openApi = Reader::readFromJsonFile($file, OpenApi::class, false);
            } else {
                $this->_openApi = Reader::readFromYamlFile($file, OpenApi::class, false);
            }
        }

        return $this->_openApi;
    }
}
