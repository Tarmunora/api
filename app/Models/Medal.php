<?php

namespace App\Models;

use EloquentFilter\Filterable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Medal extends Model
{
    use HasFactory, Filterable;

    protected $fillable = [
        'title',
        'description',
        'hidden',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    // public function players()
    // {
    //     return $this->hasManyThrough(Player::class, PlayerMedal::class);
    // }

    public function earned()
    {
        return $this->hasMany(PlayerMedal::class);
    }
}
