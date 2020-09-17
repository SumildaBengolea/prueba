<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class PromoSlider extends Model
{
    /**
     * @return mixed
     */
    public function slides()
    {
        return $this->hasMany('App\Slide');
    }

    /**
     * @return mixed
     */
    public function location()
    {
        return $this->belongsTo('App\Location');
    }

    /**
     * @return mixed
     */
    public function toggleActive()
    {
        $this->is_active = !$this->is_active;
        return $this;
    }
}
