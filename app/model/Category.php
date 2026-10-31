<?php

namespace app\model;

class Category extends BaseModel
{
    protected $name = 'categories';

    public function documents()
    {
        return $this->hasMany(Document::class, 'category_id');
    }
}
