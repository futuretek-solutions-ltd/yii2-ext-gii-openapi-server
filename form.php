<?php

/**
 * @var $this yii\web\View
 * @var $form yii\widgets\ActiveForm
 * @var $generator \futuretek\gii\openapi\server\Generator
 */

?>

<?= $form->field($generator, 'openApiFile')->error(['encode' => false]) ?>

<?= $form->field($generator, 'ignoreSpecErrors')->checkbox() ?>

<?= $form->field($generator, 'routeFile') ?>

<?= $form->field($generator, 'schemaNamespace') ?>

<?= $form->field($generator, 'enumNamespace') ?>
