<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditQuestion extends Model
{
    protected $fillable = [
        'section_id',
        'report_category',
        'question',
        'answer',
        'points_current',
        'points_total',
        'percent',
        'comments',
        'order',
    ];

    public function section()
    {
        return $this->belongsTo(AuditSection::class);
    }
}
