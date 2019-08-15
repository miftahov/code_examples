<?php
// Пример кода.
// Модель.
// Добавление новости.

namespace backend\models;

use Yii;
use yii\base\Model;
use common\models\News;

class AddNewsForm extends Model
{
    public $text;
    public $head;

    public function rules()
    {
        return 
        [
            [
                [
                    'head',
                    'text',
                ], 
                'safe',
            ],
        ];
    }

    public function save()
    {
        $model = new News();
        $model->head = $this->head;
        $model->text = $this->text;
        $model->save();
        return true;
    }
}