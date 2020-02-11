<?php

/* @var $this yii\web\View */
/* @var $form yii\bootstrap\ActiveForm */
/* @var $model \frontend\models\SignupForm */

use yii\helpers\Html;
use yii\bootstrap\ActiveForm;
use yii\captcha\Captcha;
$this->title = Yii::$app->sys->event_name. ' Signup';
?>
<div class="site-signup">
  <div class="body-content">
    <h2><?=Html::encode($this->title)?></h2>
    <p class="text-primary">Please fill out the following fields to register for an <code style="color: red">echoCTF.RED</code> account</p>
    <p class="text-warning">All our email communications come from the following address: <small><code class="text-warning"><?=Html::encode(Yii::$app->sys->mail_from)?></code></small></p>
    <div class="row">
        <div class="col-lg-5">
            <?php $form = ActiveForm::begin(['id' => 'form-signup']); ?>

                <?=$form->field($model, 'username')->textInput(['autofocus' => true]) ?>

                <?=$form->field($model, 'email') ?>

                <?=$form->field($model, 'password')->passwordInput() ?>

                <?php echo $form->field($model, 'captcha')->widget(\yii\captcha\Captcha::classname(), ['options'=>['placeholder'=>'enter captcha code']])->label(false) ?>

                <p><small>By signing up you accept the echoCTF.RED <b><a href="/terms_and_conditions" target="_blank">Terms and Conditions</a></b>
                  and <b><a href="/privacy_policy" target="_blank">Privacy Policy</a></b>.</small>
                </p>
                <div class="form-group">
                    <?=Html::submitButton('Signup', ['class' => 'btn btn-primary', 'name' => 'signup-button']) ?>
                </div>

            <?php ActiveForm::end(); ?>
        </div>
    </div>
  </div>
</div>
