<?php

use yii\helpers\Html;
use yii\grid\GridView;

/* @var $this yii\web\View */
/* @var $searchModel app\modules\gameplay\models\TargetSearch */
/* @var $dataProvider yii\data\ActiveDataProvider */

$this->title=ucfirst(Yii::$app->controller->module->id).' / '.ucfirst(Yii::$app->controller->id);
$this->params['breadcrumbs'][]=$this->title;
?>
<div class="target-index">

    <h1><?= Html::encode($this->title) ?></h1>

    <p>
        <?= Html::a('Create', ['create'], ['class' => 'btn btn-success']) ?>
        <?= Html::a('Spin All', ['spin', 'id' => 'all'], ['class' => 'btn btn-primary']) ?>
        <?= Html::a('Pull All', ['pull', 'id' => 'all'], ['class' => 'btn btn-warning']) ?>
        <?= Html::a('Statistics', ['statistics'], ['class' => 'btn btn-info']) ?>
        <?= Html::a('Container Status', ['status'], ['class' => 'btn btn-info']) ?>
    </p>

    <?php // echo $this->render('_search', ['model' => $searchModel]); ?>

    <?= GridView::widget([
        'dataProvider' => $dataProvider,
        'filterModel' => $searchModel,
        'columns' => [
            'id',
            'name',
            'ipoctet',
            'server',
            [
              'label'=>'Network',
              'value'=>'network.name'
            ],
  //          [
  //              'label'=>'Finding pts',
  //              'value'=>'findingPoints'
  //          ],
  //          [
  //              'label'=>'Treasure pts',
  //              'value'=>'treasurePoints'
  //          ],
            [
              'attribute'=>'status',
              'filter'=>$searchModel->statuses,
            ],
            'scheduled_at:dateTime',
            'rootable:boolean',
            'active:boolean',
            'timer:boolean',
            'difficulty',
            [
              'label' => 'Headshots',
              'attribute' => 'headshot',
              'value' => function ($model) { return count($model->headshots);}
            ],
//            'required_xp',
//            'suggested_xp',
            'weight',
            [
              'class' => 'yii\grid\ActionColumn',
              'template' => '{spin} {view} {update} {delete}',
              'buttons' => [
                  'spin' => function($url) {
                      return Html::a(
                          '<span class="glyphicon glyphicon glyphicon-off"></span>',
                          $url,
                          [
                              'title' => 'Spin container',
                              'data-pjax' => '0',
                              'data-method' => 'POST',
                          ]
                      );
                  },
              ],
            ],
        ],
    ]);?>


</div>
