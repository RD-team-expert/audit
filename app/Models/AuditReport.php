<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditReport extends Model
{
    protected $fillable = [
        'restaurant_id',
        'form_name',
        'form_type',
        'start_date',
        'end_date',
        'upload_date',
        'auditor',
        'overall_score',
    ];

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function sections()
    {
        return $this->hasMany(AuditSection::class);
    }
}
