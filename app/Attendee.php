<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Attendee extends Model
{
    //

    static function exist($matricNo){
        $r= Attendee::where('matricNo',$matricNo)->get();
        return !$r->isEmpty();
    }


    public function sections(){
        return $this->belongsToMany(Section::class);
    }
}
