<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Section;
use App\Attendee;
use GuzzleHttp\Client;
use Storage;
use Carbon\Carbon;
use DB;
use Illuminate\Support\Str;
class SectionController extends Controller
{
    private $uriBase;
    private $ocpApimSubscriptionKey;
    private $headers;


    function __construct() {
        $this->ocpApimSubscriptionKey = '4a42b67c93404e6ab1f3e63c1b3238e8';
        
        $this->uriBase = 'https://murad01.cognitiveservices.azure.com/face/v1.0/persongroups/';

        $this->headers = array(
            'Content-Type' => 'application/json',
            'Ocp-Apim-Subscription-Key' => $this->ocpApimSubscriptionKey
        );
        
        
    }
    
    public function createSection(Request $r){
        $courseCode=str_replace(' ', '', strtolower($r->courseCode));
        $section=$r->section;
        $sc=Section::where('courseCode',$courseCode)->where('section',$section)->get();
        $s=null;
        if($sc->isEmpty()){
            $s=new Section;
            $s->courseCode=$courseCode;
            $s->courseName=$r->courseName;
            $s->section=$section;
            $s->lecturer=$r->lecturer;
            $s->save();
            $groupId=$courseCode.$section;
            $client = new Client();
            $res = $client->request('PUT',$this->uriBase.$groupId, [
                'json' => ['name'=>$groupId],
                'headers'=>$this->headers
            ]);
            echo $res->getStatusCode();
            echo $res->getBody();


            return "created";
        }

        return "exists";
    }


    public function addFace($url, $matricNo){
        $directory='attendee/'.$matricNo;
        return collect(Storage::files($directory))->map(function($file) use ($url,$matricNo){
            // echo Storage::url($file);
            $img_url=asset("store/".$file);
            // echo $img_url;
            $client = new Client();
            $res = $client->request('POST',$url, [
                'json' => ['url'=>$img_url],
                'headers'=>$this->headers
            ]);
            echo (Carbon::now()->toDateTimeString())." Added ".$file.'<br>';
            return $matricNo."=>".$res->getBody();
        });
    }

    public function train($url){
        $client = new Client();
        $res = $client->request('POST',$url, [

            'headers'=>$this->headers
        ]);
        return "train => ".$res->getBody();
    }

    public function addStudents(Request $r){
        $students=$r->students;
        $courseCode=str_replace(' ', '', strtolower($r->courseCode));
        $section=$r->section;
        $secRow=Section::where('courseCode',$courseCode)->where("section",$section)->first();
        if($secRow==null) return "invalid section";
        $url=$this->uriBase.$courseCode.$section.'/persons';
        $tr=array();
        foreach ($students as $matricNo){
            echo (Carbon::now()->toDateTimeString())." Adding ".$matricNo.'<br>';
            $studentRow=Attendee::where('matricNo',$matricNo)->first();
            if($secRow->attendees->isNotEmpty() && $secRow->attendees()->find($studentRow->id))
                continue;
            
            $client = new Client();
            $res = $client->request('POST',$url, [
                'json' => ['name'=>$studentRow->callName],
                'headers'=>$this->headers
            ]);
            echo $matricNo.'=>'.$res->getBody().'<br>';
            $person_id=json_decode($res->getBody())->personId;
            $secRow->attendees()->attach($studentRow,array("person_id"=>$person_id));

            array_push($tr, $this->addFace($url.'/'.$person_id.'/persistedFaces',$matricNo));
            echo (Carbon::now()->toDateTimeString())." Added".$matricNo.'<br>';
        }
        echo (Carbon::now()->toDateTimeString())." Training group ".$courseCode.$section.'<br>';
        array_push($tr,$this->train($this->uriBase.$courseCode.$section.'/train'));
        echo (Carbon::now()->toDateTimeString())." Training finish ".$courseCode.$section.'<br>';
        // return var_dump($tr);
    }
    public function recognize(Request $r){
        $url="https://murad01.cognitiveservices.azure.com/face/v1.0/";
        $path = 'test/'.Carbon::now()->toDateString();
        $courseCode=str_replace(' ', '', strtolower($r->courseCode));
        $section=$r->section;

        $data=$r->img;
        list($type, $data) = explode(';', $data);
        list(, $data)      = explode(',', $data);
        $data = base64_decode($data);

        $fileName=(str_replace(' ', '', Carbon::now()->toDateTimeString())).'.png';
        if(Storage::disk('local')->put($path.'/'.$fileName, $data)){
            $img_url=asset("store/".$path."/".$fileName);
            // echo $img_url;
            $client = new Client();
            $res = $client->request('POST',$url."detect", [
                'json' => ['url'=>$img_url],
                'headers'=>$this->headers
            ]);


            $tr=array();


            $data=json_decode($res->getBody());
            $data_identify=json_decode($res->getBody());
            if(empty($data_identify))
                return $res->getBody();
            $faceIds=array_map(function($rr){return $rr->faceId;},$data);
            $faceRectangles=array_map(function($rr){return $rr->faceRectangle;},$data);

            $client = new Client();
            $res = $client->request('POST',$url."identify", [
                'json' => ["personGroupId"=>$courseCode.$section,"faceIds"=>$faceIds,"maxNumOfCandidatesReturned"=> 1],
                'headers'=>$this->headers
            ]);

            array_push($tr,"identify => ".$res->getBody());   

            
            $data=json_decode($res->getBody());
            if(!empty($data)){
                $personIds=array_map(function($r){return empty($r->candidates[0])? "undefined":$r->candidates[0]->personId;},$data);
                foreach($personIds as $key=>$personId){
                    $callName="undefined";
                    $matricNo=-1;
                    if($personId!='undefined'){
                        $attendee_id=DB::table('attendee_section')->where('person_id',$personId)->first()->attendee_id;
                        $callName=Attendee::find($attendee_id)->callName;
                        $matricNo=Attendee::find($attendee_id)->matricNo;
                    }
                    $faceRectangles[$key]->callName=$callName;
                    $faceRectangles[$key]->fileName=$fileName;
                    $faceRectangles[$key]->matricNo=$matricNo;

                }
            }
            return json_encode($faceRectangles);
        }
        else{
            return "couldn't store";
        }
 

        
    }
    public function deleteGroups(Request $r){
        $client = new Client();
        $res = $client->request('GET',$this->uriBase, [
            'headers'=>$this->headers
        ]);
        
        $groups=json_decode($res->getBody());
        

        foreach($groups as $group){
            $client = new Client();
            $res = $client->request('DELETE',$this->uriBase.$group->personGroupId, [
                'headers'=>$this->headers
            ]);
            echo $group->personGroupId." ".$res->getBody();
        }
        
    }
}


