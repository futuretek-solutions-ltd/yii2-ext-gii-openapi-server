OpenAPI generator
=================

Currently, only generating schema, enums and controller/action templates are done.

Installation
------------

add to require-dev section of composer.json:

```
"futuretek/yii2-gii-openapi-server": "^1.0.0"
```

Usage
-----
Extension has automatic configuration via `futuretek/yii2-composer` package. 
See `composer.json` - `extra:yii-config:web`

Or you can add the configuration manually:

```php
'modules' => [
    'gii' => [
        'class' => 'yii\gii\Module',
        'allowedIPs' => ['127.0.0.1', '::1'],
        'generators' => [
            'openapi' => [
                'class' => 'futuretek\gii\openapi\server\Generator',
            ],
        ]
    ],
]     
```
