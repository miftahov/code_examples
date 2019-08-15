<?php
// Пример кода.
// Парсер данных.
// Парсинг и сортировка данных из файла csv

namespace common\models;

use Yii;
use yii\base\Model;

class Data extends Model
{
    public  $list;
    private $file_name;

    function __construct($file_name) 
    {
        $this->file_name = $file_name;
        $this->readData();
        $this->sort();
    }

    // читаем данные из файла и парсим в массив
    private function readData()
    {
        $array = [];
        $data  = file($this->file_name);
        foreach ($data as $line_num => $line) {
            $value = explode(';', $line);
            $array_line['date']    = $value[0];
            $array_line['kbk']     = $value[1];
            $array_line['address'] = $value[2];
            $array_line['percent'] = $value[3];
            array_push($array, $array_line);
        }
        $this->list = $array;
    }

    // сортируем массив по дате, КБК и складываем проценты по записям на одну дату с одинаковым КБК
    private function sort()
    {
        usort($this->list, array($this, 'sortDate'));     
        usort($this->list, array($this, 'sortKbk'));
        $this->foldDuplicate();
    }

    // сортируем массив по дате
    private function sortDate($a, $b)
    {
        return strtotime($a['date']) - strtotime($b['date']);
    }

    // сортируем массив по КБК
    private function sortKbk($a, $b)
    {
        if ($a['date'] <> $b['date']) {
            return 0;
        }
        return strcmp($a['kbk'], $b['kbk']);
    }

    // Складываем проценты по записям на одну дату с одинаковым КБК
    private function foldDuplicate()
    {
        $count    = 1;
        $array2   = [];
        $previous = $this->list[0];
        $array    = array_slice($this->list, 1);
        foreach ($array as $num => $line) {
            if (($line['date'] == $previous['date']) && ($line['kbk'] == $previous['kbk'])) {
                $line['percent'] = intval($line['percent']) + intval($previous['percent']);
                $count++;
            } else {
                $previous['percent'] = round(intval($previous['percent']) / $count);
                $previous['count']   = $count;
                $count = 1;
                array_push($array2, $previous);          
            }
            $previous = $line;
        }
        $previous['percent'] = round(intval($previous['percent']) / $count);
        $previous['count']   = $count;
        array_push($array2, $previous);
        $this->list = $array2;
    }
}
            