<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatSession extends Model
{
    protected $fillable = ['phone', 'state', 'data'];

    protected $casts = [
        'data' => 'array', // permite acesso a $session->data como array
    ];
}

