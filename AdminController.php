<?php
// Пример кода.
// Контроллер админки. 
// Список пользователей, редактирование личных данных.
// Список новостей. Добавление новости.
// Список семинаров. Добавление семинара.

namespace backend\controllers;

use Yii;
use yii\web\Controller;
use yii\data\Pagination;
use yii\filters\VerbFilter;
use yii\filters\AccessControl;
use yii\web\BadRequestHttpException;
use yii\base\InvalidArgumentException;
use common\models\User;
use common\models\News;
use common\models\Seminar;
use backend\models\UserEditForm;
use backend\models\AddNewsForm;
use backend\models\AddSeminarForm;

class AdminController extends Controller
{

    public $layout = 'admin';

    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::className(),
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
        ];
    }

    public function actionIndex()
    {
        return $this->render('index');
    }

    // список пользователей
    public function actionUserList()
    {
        $search = Yii::$app->request->get('search');
        if (!$search=='') {
            $query = User::find()
                ->where   (['ilike', 'first_name', $search])
                ->orWhere (['ilike', 'last_name' , $search]);
        } else {
            $query = User::find()
                ->where(['status' => 10]);
        }
        $pagination = new Pagination([
            'defaultPageSize' => 10,
            'totalCount'      => $query->count(),
            'params'          => array_merge($_GET, ['search' => $search]),
        ]);
        $users = $query->orderBy('id')
            ->offset($pagination->offset)
            ->limit($pagination->limit)
            ->all();
        return $this->render('investors', [
            'users'      => $users,
            'pagination' => $pagination,
        ]);
    }

    // редаткирование личных данных
    public function actionUserEdit()
    {
        $id    = Yii::$app->request->get('id');
        $user  = User::find()->where(['id' => $id])->one();
        $form  = new UserEditForm();
        if ( $model->load(Yii::$app->request->post()) && $model->validate()) {
            $model->save($user, $pers);
        }
        return $this->render('user-edit',[
            'user'  => $user,
            'form' => $form
        ]);
    }

    // список новостей
    public function actionNews()
    {
        $query = News::find();
        $pagination = new Pagination([
            'defaultPageSize' => 10,
            'totalCount'      => $query->count(),
        ]);
        $news = $query->orderBy('created_at DESC')
            ->offset($pagination->offset)
            ->limit($pagination->limit)
            ->all();
        return $this->render('news', [
            'news'       => $news,
            'pagination' => $pagination,
        ]);
    }

    // добавление новости
    public function actionNewsAdd()
    {
        $model = new AddNewsForm();
        if ( $model->load(Yii::$app->request->post()) && $model->validate()) {
            $model->save();
            $model = new AddNewsForm();
        }
        return $this->render('news-add',['model' => $model]);
    }

    // список семинаров
    public function actionSeminar()
    {
        $query = Seminar::find();
        $pagination = new Pagination([
            'defaultPageSize' => 10,
            'totalCount'      => $query->count(),
        ]);
        $seminar = $query->orderBy('created_at DESC')
            ->offset($pagination->offset)
            ->limit($pagination->limit)
            ->all();
        return $this->render('seminar', [
            'rows'       => $seminar,
            'pagination' => $pagination,
        ]);

    }

    // добавление семинара
    public function actionSeminarAdd()
    {
        $model = new AddSeminarForm();
        if ( $model->load(Yii::$app->request->post()) && $model->validate()) {
            $model->save();
            $model = new AddSeminarForm();
        }
        return $this->render('seminar-add',['model' => $model]);
    }
}