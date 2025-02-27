<?php
namespace app\commands;

use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\ArrayHelper;
use Docker\DockerClientFactory;
use app\modules\activity\models\SpinQueue;
use app\modules\activity\models\Notification;
use app\modules\activity\models\SpinHistory;
use app\modules\gameplay\models\Target;
use app\modules\gameplay\models\Finding;
use app\modules\settings\models\Sysconfig;
use app\components\Pf;
use Docker\Docker;
use Http\Client\Socket\Exception\ConnectionException;
use yii\console\Exception as ConsoleException;
/**
 * @method docker_connect()
 * @method containers_list()
 */
class CronController extends Controller {

  /*
   * Perofrm all needed cron operations for the VPN
   */
  public function actionIndex()
  {
    $this->actionSpinQueue();
    $this->actionHealthcheck(true);
    $this->actionPowerups();
    $this->actionPowerdowns();
    $this->actionOndemand();
    $this->actionOfflines();
    $this->actionPf(true);
  }

  /**
   * Check container health status and merge with spin queue
   */
  public function actionHealthcheck($spin=false)
  {
    $unhealthy=$this->unhealthy_dockers();

    foreach($unhealthy as $target)
    {
      printf("Processing [%s] on docker [%s]", $target->name, $target->server);
      if($target->healthcheck==0)
      {
        printf("... skipping by healthcheck flag\n");
      }
      echo "\n";
      if($spin !== false)
      {
        $target->spin();
        $sh=new SpinHistory;
        $sh->target_id=$target->id;
        $sh->created_at=new \yii\db\Expression('NOW()');
        $sh->updated_at=new \yii\db\Expression('NOW()');
        $sh->player_id=1;
        $sh->save();
      }
    }
  }

  /**
   *  Process Pending Spin Queue entries
   */
  public function actionSpinQueue()
  {

    $transaction=\Yii::$app->db->beginTransaction();
    try
    {
      $query=SpinQueue::find();
      foreach($query->all() as $t)
      {
        printf("Processing [%s] on docker [%s]", $t->target->name, $t->target->server);
        printf(" by [%s] on %s", $t->player->username, $t->created_at);

        try
        {
          $t->target->spin();
          if($t->target->ondemand && $t->target->ondemand->state<0)
          {
            $t->target->ondemand->state=1;
            $t->target->ondemand->heartbeat=new \yii\db\Expression('NOW()');
            $t->target->ondemand->player_id=$t->player_id;
            $t->target->ondemand->save();
            $notifTitle=sprintf("Target [%s] powered up", $t->target->name);
          }
          else
          {
            $notifTitle=sprintf("Target [%s] restart request completed", $t->target->name);
          }

          $notif=new Notification;
          $notif->player_id=$t->player_id;
          $notif->title=$notifTitle;
          $notif->body=sprintf("<p>The restart you requested, of [<b><code>%s</code></b>] is complete.<br/>Have fun</p>", $t->target->name);
          $notif->archived=0;
          $notif->created_at=new \yii\db\Expression('NOW()');
          $notif->updated_at=new \yii\db\Expression('NOW()');
          $notif->save();

          $t->delete();
          echo " OK\n";
        }
        catch(\Exception $ce)
        {
          printf(" NOT OK (%s)\n", $ce->getMessage());
        }
      }
      $transaction->commit();
    }
    catch(\Exception $e)
    {
        $transaction->rollBack();
        throw $e;
    }
    catch(\Throwable $e)
    {
        $transaction->rollBack();
        throw $e;
    }

  }


  public function actionPowerups()
  {
    $targets=Target::find()->powerup();
    foreach($targets->all() as $target)
    {
      printf("Target %s ", $target->fqdn);
      $target->pull();
      printf("scheduled for [%s] at %s, spin: %s\n", $target->status, $target->scheduled_at, $target->spin() ? "success" : "fail");
      $target->status='online';
      $target->scheduled_at=null;
      $target->active=1;
      $target->save();
    }
  }

  public function actionOndemand()
  {
    $targets=\app\modules\gameplay\models\TargetOndemand::find()
    ->andWhere(['and',['state'=>1], ['<=','heartbeat',new \yii\db\Expression('NOW() - INTERVAL 1 HOUR')]])
    ->orWhere(['state'=>-1]);
    foreach($targets->all() as $target)
    {
      printf("Destroying ondemand target %s\n", $target->target->fqdn);
      $target->target->destroy();
      $target->state=-1;
      $target->heartbeat=null;
      $target->save();

    }
  }

  public function actionOfflines()
  {
    $targets=Target::find()->offline();
    foreach($targets->all() as $target)
    {
      printf("Target %s ", $target->fqdn);
      printf("scheduled for [%s] at [%s]", $target->status, $target->scheduled_at);
      $target->powerdown();
      printf(", destroyed: %s\n", $requirePF ? "success" : "fail");
    }
  }

  public function actionPowerdowns()
  {
    $targets=Target::find()->powerdown();
    foreach($targets->all() as $target)
    {
      printf("Target %s ", $target->fqdn);
      printf("scheduled for [%s] at [%s]", $target->status, $target->scheduled_at);
      $target->powerdown();
      printf(", destroyed: %s\n", $requirePF ? "success" : "fail");
    }

  }

  /**
   * Populate pf related tables and rules for targets
   */
  public function actionPf($load=false,$base="/etc")
  {
    $this->active_targets_pf($base);
    $this->match_findings($load,$base);
  }

  /*
   * Geneate match rules for target findings and load them
   */
  private function match_findings($load,$base="/etc")
  {
    $networks=$rules=$frules=array();
    $targets=Target::find()->active()->online()->poweredup()->all();
    foreach($targets as $target)
    {
      foreach($target->findings as $finding)
      {
        if($target->network)
        {
          $networks[$target->network->codename]=$target->network;
        }
        $frules[]=$finding->matchRule;
      }
    }
    Pf::store($base.'/match-findings-pf.conf',ArrayHelper::merge($frules,$rules));

    if($load)
      Pf::load_anchor_file("offense/findings","$base/match-findings-pf.conf");
  }

  /*
   * Find and store active targets IP addresses on their PF table
   */
  private function active_targets_pf($base="/etc")
  {
    $ips=$networks=$rules=array();
    $targets=Target::find()->active()->online()->poweredup()->all();
    foreach($targets as $target)
    {
      if($target->networkTarget === NULL)
      {
        $ips[]=$target->ipoctet;
      }
      else
      {
        $networks[$target->network->codename][]=$target->ipoctet;
        $rules[]=Pf::allowToNetwork($target);
        $rules[]=Pf::allowToClient($target);
      }
    }
    Pf::store($base.'/targets.conf',$ips);
    Pf::load_table_file('targets',$base.'/targets.conf');
    foreach($networks as $key => $val) {
      Pf::store($base.'/'.$key.'.conf', $val);
      Pf::load_table_file($key,$base.'/'.$key.'.conf');
      $rules[]=sprintf("pass inet proto udp from <%s> to (targets:0) port 53",$key);
    }

    Pf::store("$base/targets_networks.conf",$rules);
    Pf::load_anchor_file("networks","$base/targets_networks.conf");
  }

  /*
   * Return an array of unhealthy containers or null
   */
  private function unhealthy_dockers()
  {
    $unhealthy=[];
    foreach(Target::find()->select(['server'])->distinct()->all() as $target)
    {
      $docker=$this->docker_connect($target->server);

      $containers=$this->containers_list($docker);
      foreach($containers as $container)
      {
          if(strstr($container->getStatus(), 'unhealthy'))
          {
            $name=str_replace('/', '', $container->getNames()[0]);
            if(($unhealthyTarget=Target::findOne(['name'=>$name])) !== NULL)
              $unhealthy[$name]=$unhealthyTarget;
          }
      }
    }
    return $unhealthy;
  }

  /**
   * Connect to a docker server API and return docker client object
   */
  private function docker_connect($remote_socket)
  {
      $client=DockerClientFactory::create([
        'remote_socket' => $remote_socket,
        'ssl' => false,
      ]);
    try
    {
      $docker=Docker::create($client);
    }
    catch(\Exception $e)
    {
      return false;
    }
    return $docker;
  }

  /**
   * Get a list of containers from a connected docker
   */
  private function containers_list($docker)
  {
    if($docker === false) return [];
    try
    {
      $containerList=$docker->containerList();
    }
    catch(\Exception $e)
    {
      return [];
    }
    return $containerList;
  }

}
