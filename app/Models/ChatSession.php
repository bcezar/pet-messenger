<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Company;

class ChatSession extends Model
{
    protected $fillable = [
        'company_id',
        'client_phone',
        'state',
        'data',
    ];

    protected $casts = [
        'data' => 'array',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
