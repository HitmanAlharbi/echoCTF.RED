<?php
use yii\helpers\Html;
use yii\widgets\ListView;
use app\widgets\stream\StreamWidget as Stream;
$this->title=Yii::$app->sys->event_name.' Target: '.$target->name. ' / '.long2ip($target->ip). ' #'.$target->id;
$this->_description=$target->purpose;
$this->_image=\yii\helpers\Url::to($target->fullLogo, 'https');
$this->_url=\yii\helpers\Url::to(['view', 'id'=>$target->id], 'https');
$this->_fluid='-fluid';
?>

<div class="target-index">
  <div class="body-content">
<?php if($target->ondemand && $target->ondemand->state<0 && !Yii::$app->user->isGuest):?>
  <div><p class="text-danger">This target is currently powered off. <sub>Feel free to spin it up after you connect to the VPN.</sub></p></div>
<?php endif;?>
<?php if($target->ondemand && $target->ondemand->state>0 && !Yii::$app->user->isGuest):?>
  <div><p class="text-danger">The target will shutdown in <code id="countdown" data="<?=$target->ondemand->expired?>"></code></p></div>
<?php endif;?>
<?php if($target->status !== 'online'):?>
    <div><p class="text-warning"><code class="text-warning">Target <?php if ($target->scheduled_at!==null):?>scheduled for<?php endif;?> <b><?=$target->status?></b> <?php if ($target->scheduled_at!==null):?> <abbr title="<?=\Yii::$app->formatter->asDatetime($target->scheduled_at,'long')?>"><?=\Yii::$app->formatter->asRelativeTime($target->scheduled_at)?></abbr><?php endif;?></code></p></div>
<?php endif;?>
<?php if($target->network):?>
    <div><p class="text-info">Target from: <b><?=$target->network->name?></b></p></div>
<?php endif;?>

    <div class="watermarked img-fluid">
    <?=sprintf('<img src="%s" width="100px"/>', $target->logo)?>
    </div>
    <?php
    if(Yii::$app->user->isGuest)
      echo $this->render('_guest', ['target'=>$target, 'playerPoints'=>$playerPoints]);
    else
      echo $this->render('_versus', ['target'=>$target, 'playerPoints'=>$playerPoints, 'identity'=>Yii::$app->user->identity->profile]);
      ?>

        <?php \yii\widgets\Pjax::begin(['id'=>'stream-listing', 'enablePushState'=>false, 'linkSelector'=>'#stream-pager a', 'formSelector'=>false]);?>
        <?php echo Stream::widget(['divID'=>'target-activity', 'dataProvider' => $streamProvider, 'pagerID'=>'stream-pager', 'title'=>'Target activity', 'category'=>'Latest activity on the target']);?>
        <?php \yii\widgets\Pjax::end();?>
  </div>
</div>
<?php
if($target->ondemand && $target->ondemand->state>0 && !Yii::$app->user->isGuest) $this->registerJs(
    'var distance = $("#countdown").attr("data");
    var countdown = setInterval(function() {
      var minutes = Math.floor((distance % (60 * 60)) / ( 60));
      var seconds = Math.floor((distance % (60)));
      if (distance < 0) {
        clearInterval(countdown);
        document.getElementById("countdown").innerHTML = "system will shutdown soon!";
      }
      else {
        document.getElementById("countdown").innerHTML = minutes + "m " + seconds + "s ";
        $("#countdown").attr("data",distance--);
      }
    }, 1000);',
    4
);
