<?php

namespace App\Http\Controllers;

use App\Jobs\GetEWSData;
use App\Part;
use GuzzleHttp\Client;

/**
 * This controller handles the request actions from the EWS API
 */
class EWSController extends Controller {

    public function __construct() {
        $this->middleware('auth');
    }

    public function processRequest() {
        $service = (isset($_GET['service']) ? $_GET['service'] : "");

        switch ($service) {
            case "GetParts":
                $this->startUpdate();
                break;
            default:
                echo $service . " service not found.";
                break;
        }
    }

    public static function sendRequest($url, $method, $body) {
        $data = [];
        $data['headers'] = [ 
            'Authorization' => 'Bearer ' . env('EWS_TOKEN')
        ];
        if ($method != 'GET')
            $data['form_params'] = $body;

        $client = new Client();
        $request = $client->request($method, $url, $data);

        return $request;
    }

    public function startUpdate() {
        $this->getAllProducts(env('EWS_URL') . '/products');
    }

    public function getAllProducts($url) {
        GetEWSData::dispatch($url)->onConnection('pms_queue');
    }

    public function queueJob($url) {
        $request = self::sendRequest($url, 'GET', null);
        return $request;
    }

    public function testProcessData($result) {

        foreach($result->data as $data){

            $existingPart = Part::where('partsku',$data->sku)->first();

            if ($data->is_active == true && $existingPart != Null) {

                $parts = $this->formatForUpdate($data);
                Part::updateOrCreate(['partsku' => $data->sku], $parts );

            } else if ( $data->is_active == true && $existingPart == Null) {
                // print_r("must be an existing part!");
            }
        }
    }

    public function formatForUpdate($data) {
        $netostat = '';

        if ($data->is_active == true) {
            $netostat = "Active";
        } else {
            $netostat = "Inactive";
        }

        $parts = [
            'partsku' => $data->sku,
            'neto_status' => $netostat,
            'stocksonhand' => $data->sevenhills_quantity,
            'warehouseloc' => $data->pick_zone,
            'part_status' => 'Pending',
            'image' => 'default.png',    
        ];
       
        return $parts;
    }

}
