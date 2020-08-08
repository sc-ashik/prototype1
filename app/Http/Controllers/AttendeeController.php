<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Attendee;
use Storage;
class AttendeeController extends Controller
{


    function createStudent(Request $req){
        $matricNo=$req->matricNo;
        if(!Attendee::exist($matricNo)){
            $user=new Attendee;
            $user->fullName=$req->fullName;
            $user->callName=$req->callName;
            $user->matricNo=$req->matricNo;
            $user->save();
            return $user;
        }
        return "exists";
        
    }
}
