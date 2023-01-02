<?php

namespace futuretek\gii\openapi\server\lib;

use yii\base\BaseObject;

class ControllerData extends BaseObject
{
    public ActionData $data;
    /** @var MethodGenerator[] */
    public array $methods;
}
