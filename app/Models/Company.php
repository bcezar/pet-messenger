<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $fillable = [
        'name',
        'whatsapp_number',
        'timezone',
        'status',
    ];

    public function agendamentos()
    {
        return $this->hasMany(Agendamento::class);
    }

    public function chatSessions()
    {
        return $this->hasMany(ChatSession::class);
    }
}

