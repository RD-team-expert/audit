<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Restaurant extends Model
{
    protected $fillable = [
        'restaurant_id',
        'name',
        'address',
        'city',
        'state',
        'zip',
        'phone',
        'contact_name',
        'contact_email',
    ];

    public function auditReports()
    {
        return $this->hasMany(AuditReport::class);
    }
}
