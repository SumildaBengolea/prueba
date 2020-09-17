<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class restaurantPagos extends Model {

  protected $table = 'restaurantPagos';
  protected $primaryKey = 'id_restaurant';
  protected $fillable = ['id_restaurant', 'pasarelas'];

}
