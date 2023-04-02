<?php

namespace Storyfeed\Prototype\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Storyfeed\Prototype\Factories\UserFactory;

class User extends Model implements AuthorizableContract, AuthenticatableContract
{
    use Authorizable, Authenticatable, HasFactory;

    protected $guarded = [];

    protected $table = 'users';

    protected static function newFactory()
    {
        return UserFactory::new();
    }
}
