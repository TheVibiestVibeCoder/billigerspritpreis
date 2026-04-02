<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GasStation extends Model
{
    use HasFactory;

    protected $table = 'gas_stations';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'id',
        'name',
        'street',
        'postal_code',
        'city',
        'latitude',
        'longitude',
        'is_open',
        'price_diesel',
        'price_super',
        'price_tier_diesel',
        'price_tier_super',
        'last_updated',
    ];

    protected $casts = [
        'is_open' => 'boolean',
        'price_diesel' => 'decimal:3',
        'price_super' => 'decimal:3',
        'price_tier_diesel' => 'integer',
        'price_tier_super' => 'integer',
        'last_updated' => 'datetime',
    ];
}
