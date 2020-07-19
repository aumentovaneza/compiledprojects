<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use GuzzleHttp\Client;
/**
 * This controller handles the API connection to EWS
 */
class APIController extends Controller
{
    public static function submitGetRequest($method,$url)
    {
        $client = new Client();
        $response = $client->request($method,$url,[
            'headers' =>[
                'Accept' => 'application/json',
                'Authorization' => env('EWS_TOKEN')
            ]
        ]);

        return json_decode($response->getBody()->getContents());
    }
}
