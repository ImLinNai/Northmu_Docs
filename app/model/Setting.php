<?php

namespace app\model;

class Setting extends BaseModel
{
    protected $name = 'settings';
    protected $pk = 'name';
    protected $autoWriteTimestamp = false;
}
