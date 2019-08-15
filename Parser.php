<?php
// Пример кода.
// Парсер данных.
// Модель для консольного парсера данных из XML файлов. 
// Парсит XML файл и заносит данные в базу MySQL пакетами по 100 значений для оптимизации.

namespace common\models;

use Yii;
use yii\base\Model;
use common\models\Variables;
use common\models\Category;

class Parser extends Model
{
    const   PACKAGE = 100;
    private $table;
    private $variable_name;
    private $type;
    private $items;
    private $values;
    
    // начало парсинга
    public function parsing()
    {
        $this->table = Variables::tableName();
        $this->parsingVariables();
        $this->parsingCategories();
        $this->save();
    }

    // парсим категории
    private function parsingCategories()
    {
        $file=\Yii::getAlias('@app/../backend/imports/').\Yii::$app->params['unitsFile'];
        $categories = simplexml_load_file($file);
        $category   = $categories->item;
        $this->parsingCategory($category);
    }
    
    // рекурсивный обход дерева данных
    private function parsingCategory($category, $parent_id = 0)
    {
        $id = $category['id'];
        if ( isset($category['catId'])) {
            $cat_id = $category['catId'];
        } else {
            $cat_id = 0;
        }
        $cat_name = $unit['catName'];
        $owner_name  = $unit['ownerName'];
        $row = new Category();
        $row->id         = $id;
        $row->parent_id  = $parent_id;
        $row->cat_id     = $cat_id;
        $row->cat_name   = $cat_name;
        $row->owner_name = $owner_name; 
        $row->save();
        foreach ($category->item as $item) {
            $this->parsingCategory($item, $id); // рекурсия
        }

    } 

    // парсинг переменных
    private function parsingVariables()    
    {
        $this->layer = '';
        $file=\Yii::getAlias('@app/../backend/imports/').\Yii::$app->params['FileName'];
        $variables   = simplexml_load_file($file);
        $this->items = $variables->items;
        foreach ($variables->content->variable as $variable) {
            $this->ParsingVariable($variable);
        }
    }

    // парсинг переменной
    private function parsingVariable($variable)
    {
        $this->variable_name = $variable['name'];
        $this->parsingType($variable->typeA, 'A');
        $this->parsingType($variable->typeB, 'B');
        $this->parsingType($variable->typeC, 'C');
        $this->parsingType($variable->typeD, 'D');         
    }
    
    // парсинг типа
    private function parsingType($variable, $type)   
    {     
        $this->type = $type;
        foreach ($variable->value as $value) {
            $this->parsingValue($value);
        }
    }
    
    // парсинг значения
    private function parsingValue($value)   
    {

        foreach ($this->items->item as $item) {
            $id     = $item['id'];
            $keyid  = $item['keyid']; 
            $val    = $value[$key];
            $date   = $value['date'];
            $date   = date('Y-m-d G:i:s', strtotime($date));
            $varid  = $this->variable_name;
            $type   = $this->type;
            $values = '( '$varid', '$type', $val, '$date', $id ),';
            $this->values[] = $values;
        }
    }

    // сохраняем данные в базу пакетами
    private function save()  
    {
        $i = 0;
        $q = '';
        foreach ($this->values as $values) {
            $i++;
            $q .= $values;
            if ($i == $this->PACKAGE) {
                $this->query($q);
                $q = '';
                $i = 0;
            }
        }
        if ($i > 0) {
            $this->query($q);
        }
    }

    // формируем запрос в базу данных
    private function query($q)
    {
        $query = 
            'INSERT INTO ' . 
            $this->table . ' (
                `layer`, 
                `variable_id`, 
                `type`, 
                `value`, 
                `created`, 
                `rest_id`,  
                `role1`, 
                `role2`, 
                `role3`, 
                `role4`, 
                `role5` 
            ) VALUES ';
        $query .= $q;
        Yii::$app->db->createCommand($query)->execute();
    }
}
