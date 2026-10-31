<?php

namespace app\model;

class Tag extends BaseModel
{
    protected $name = 'tags';
    protected $updateTime = false;

    public function documents()
    {
        return $this->belongsToMany(Document::class, 'document_tag', 'document_id', 'tag_id');
    }
}
