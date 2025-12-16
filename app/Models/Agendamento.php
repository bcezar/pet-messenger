<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Agendamento extends Model
{
    protected $table = 'agendamentos';

    protected $fillable = [
        'company_id',
        'phone',
        'nome_pet',
        'raca_pet',
        'porte_pet',
        'data_banho',
        'primeira_vez',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}

