<?php

namespace app\model;

class Document extends BaseModel
{
    protected $name = 'documents';

    protected $type = [
        'status'    => 'integer',
        'show_toc'  => 'integer',
        'copy_protected' => 'integer',
        'is_top'    => 'integer',
        'views'     => 'integer',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'document_tag', 'tag_id', 'document_id');
    }

    public function versions()
    {
        return $this->hasMany(DocumentVersion::class, 'document_id')->order('version_no', 'desc');
    }
}
