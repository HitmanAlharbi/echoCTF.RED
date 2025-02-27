<?php

namespace app\modules\gameplay\controllers;

use Yii;
use app\modules\gameplay\models\Target;
use app\modules\gameplay\models\TargetSearch;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\filters\VerbFilter;
use Docker\DockerClientFactory;
use Docker\Docker;
use Http\Client\Socket\Exception\ConnectionException;
use yii\data\ArrayDataProvider;

/**
 * TargetController implements the CRUD actions for Target model.
 */
class TargetController extends Controller
{
    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
          'access' => [
                'class' => \yii\filters\AccessControl::class,
                'only' => ['index', 'create', 'update', 'view', 'generate'],
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'delete' => ['POST'],
                    'destroy' => ['POST'],
                ],
            ],
        ];
    }

    /**
     * Lists all Target models.
     * @return mixed
     */
    public function actionIndex()
    {
        $searchModel=new TargetSearch();
        $dataProvider=$searchModel->search(Yii::$app->request->queryParams);

        return $this->render('index', [
            'searchModel' => $searchModel,
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * Lists all Target Statuses.
     * @return mixed
     */
    public function actionStatus()
    {
        $dataProvider = new ArrayDataProvider([
            'allModels' => $this->docker_statuses(),
        ]);
        return $this->render('status', [
            'dataProvider' => $dataProvider,
        ]);
    }

    /**
     * Lists all Target Statistics.
     * @return mixed
     */
    public function actionStatistics()
    {
      $stats = Yii::$app->db->createCommand('select name,difficulty,target_started_count(id) as "startedBy",count(distinct t2.player_id) as "solvedBy", FORMAT(target_solved_percentage(id),2) as "solvedPct",min(t2.timer) as "fastestSolve",avg(t2.timer) as "avgSolve" FROM target as t1 left join headshot as t2 on t2.target_id=t1.id group by t1.id')
            ->queryAll();

      $dataProvider = new ArrayDataProvider([
          'allModels' => $stats,
          'sort' => [
            'attributes' => ['name', 'difficulty', 'startedBy','solvedBy','solvedPct','fastestSolve','avgSolve'],
          ],
      ]);
      return $this->render('stats', [
          'dataProvider' => $dataProvider,
      ]);
    }

    /**
     * Generate a single Target build files.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionGenerate($id)
    {
        return $this->render('generate', [
            'model' => $this->findModel($id),
        ]);
    }


    /**
     * Displays a single Target model.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionView($id)
    {
        return $this->render('view', [
            'model' => $this->findModel($id),
        ]);
    }

    /**
     * Creates a new Target model.
     * If creation is successful, the browser will be redirected to the 'view' page.
     * @return mixed
     */
    public function actionCreate()
    {
        $model=new Target();

        if($model->load(Yii::$app->request->post()) && $model->save())
        {
            return $this->redirect(['view', 'id' => $model->id]);
        }

        return $this->render('create', [
            'model' => $model,
        ]);
    }

    /**
     * Updates an existing Target model.
     * If update is successful, the browser will be redirected to the 'view' page.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionUpdate($id)
    {
        $model=$this->findModel($id);
        $modelOrig=$this->findModel($id);
        $msg="Server updated succesfully";
        if($model->load(Yii::$app->request->post()))
        {

          // if the target has changed server destroy from old one
          if($modelOrig->server != $model->server || array_key_exists('destroy', Yii::$app->request->post()))
          {
            $modelOrig->destroy();
            $msg="Server destroyed and updated succesfully";
          }
          if($model->save())
          {
            Yii::$app->session->setFlash('success', $msg);
            return $this->redirect(['view', 'id' => $model->id]);
          }
          Yii::$app->session->setFlash('error', 'Server failed to be updated ['.implode(", ", $model->getErrors()).']');
        }

        return $this->render('update', [
            'model' => $model,
        ]);
    }

    /**
     * Deletes an existing Target model.
     * If deletion is successful, the browser will be redirected to the 'index' page.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionDelete($id)
    {
        $this->findModel($id)->delete();

        return $this->redirect(['index']);
    }

    /**
     * Destroys the Container of an existing Target model.
     * If destruction is successful, the browser will be redirected to the 'index' page.
     * @param integer $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionDestroy($id)
    {
      $model=$this->findModel($id);
        if($model->destroy())
          Yii::$app->session->setFlash('success', 'Container destroyed from docker server [<code>'.$model->server.'</code>]');
        else
          Yii::$app->session->setFlash('error', 'Failed to destroy container from docker server [<code>'.$model->server.'</code>]');

        return $this->goBack(Yii::$app->request->referrer);
    }

    /**
     * Spin an existing Target model.
     * If spin is successful, the browser will be redirected to the 'index' page.
     * @param mixed $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionSpin($id)
    {
      try
      {
        if($id === 'all')
        {
          $models=Target::find()->all();
          foreach($models as $model)
            $model->spin();
          \Yii::$app->getSession()->setFlash('success', 'Containers succesfuly restarted');
        }
        else
        {
          $this->findModel($id)->spin();
          \Yii::$app->getSession()->setFlash('success', 'Container succesfuly restarted');
        }
      }
      catch(\Exception $e)
      {
        \Yii::$app->getSession()->setFlash('error', 'Failed to restart container. '.$e->getMessage());
      }
      return $this->goBack(Yii::$app->request->referrer);
    }

    /**
     * Pull an image based of existing Target model.
     * If pull is successful, the browser will be redirected to the 'index' page.
     * @param mixed $id
     * @return mixed
     * @throws NotFoundHttpException if the model cannot be found
     */
    public function actionPull($id)
    {
        if($id === 'all')
        {
          $models=Target::find()->all();
          foreach($models as $model)
            $model->pull();
          \Yii::$app->getSession()->setFlash('success', 'Images pulled succesfuly');
          return $this->redirect(['index']);

        }
        else
        {
          if($this->findModel($id)->pull())
            \Yii::$app->getSession()->setFlash('success', 'Image succesfuly pulled');
          else
          {
            \Yii::$app->getSession()->setFlash('error', 'Failed to pull container image');
          }
          return $this->goBack(Yii::$app->request->referrer);
        }

    }

    /**
     * Finds the Target model based on its primary key value.
     * If the model is not found, a 404 HTTP exception will be thrown.
     * @param integer $id
     * @return Target the loaded model
     * @throws NotFoundHttpException if the model cannot be found
     */
    protected function findModel($id)
    {
        if(($model=Target::findOne($id)) !== null)
        {
            return $model;
        }

        throw new NotFoundHttpException('The requested page does not exist.');
    }

    /*
     * Return an array of docker container statuses or null
     */
    private function docker_statuses()
    {
      $containers=[];
      foreach(Target::find()->select(['server'])->distinct()->all() as $target)
      {
        if($target->server{0}==='/')
          $client=DockerClientFactory::create([
            'ssl' => false,
          ]);
        else
          $client=DockerClientFactory::create([
            'remote_socket' => $target->server,
            'ssl' => false,
          ]);

        try
        {
          $docker=Docker::create($client);
          $tmp=$docker->containerList(['all'=>true]);
        }
        catch(\Exception $e)
        {
          continue;
        }
        $containers=array_merge($containers,$tmp);

      }
      return $containers;
    }


}
