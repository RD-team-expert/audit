<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditSection extends Model
{
    protected $fillable = [
        'audit_report_id',
        'section_type',
        'category',
        'points',
        'total_points',
        'score',
        'order',
    ];

    public function auditReport()
    {
        return $this->belongsTo(AuditReport::class);
    }

    public function questions()
    {
        return $this->hasMany(AuditQuestion::class);
    }
}
