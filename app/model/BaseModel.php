<?php

namespace app\model;

use think\Model;

abstract class BaseModel extends Model
{
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';
}
