<?php

namespace app\model;

class Admin extends BaseModel
{
    protected $name = 'admins';

    protected $hidden = ['password_hash'];
}
