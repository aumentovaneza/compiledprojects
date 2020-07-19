<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Request, Redirect, Response, Session, Auth;
use App\Models\Drugs;
use App\Models\PrescriptionsDrugs;
use App\Models\DrugsKeywords;
use Illuminate\Support\Facades\Validator;

class DrugsController extends Controller
{
	/*
    |--------------------------------------------------------------------------
    | Drugs Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles the drugs. 
    |
    */

    /**
     * Get Drugs of prescription
     *
     * @param  prescription_id
     * @return Durgs
     */
    public function getPrescriptionDrugs($prescription_id){
		$drugs = PrescriptionsDrugs::where('prescriptions_id', $prescription_id)->join('drugs','prescriptions_drugs.drugs_id', '=', 'drugs.id')->select('drugs.name')->get();
		return $drugs;
    }
	/**
     * Create custom Drugs based on existing or new
     *
     * @param  Request in json format
     * @return drug id
     */
	 public function createCustomDrugs(){
		 try{
			 $all = Request::all();
			$validator = Validator::make($all, [
					'name' => 'required',
					'formulation' => 'required',
					'no_of_hormones' => 'required',
					'ingredients' => 'required',
					'dose_quantity' => 'required'
				]
			);
			if ($validator->fails()) {
				return Response::json(['status' => false, 'errors' => $validator->errors()]);
			}
			$drug_name = Request::get('name');
			$drug_formulation = Request::get('formulation');
			$dose = Request::get('dose_quantity');
			$ingredients = Request::get('ingredients');
			$hormones = Request::get('no_of_hormones');
			
			$drug = new Drugs();
			$drug->name = $drug_name;
			$drug->dose = $dose;
			$drug->formulation = $drug_formulation;
			$drug->hormones = $hormones;
			$drug->ingredients = json_encode($ingredients);
			$drug->price = Request::get('price');
			$drug->save();
			$id = $drug->id;
			$insert_keyword_array = [];
			foreach($ingredients as $keyword){
				$insert_keyword_array[] = array(
					'drugs_id' => $id,
					'keyword' => $keyword['name'],
					'created_at' => date('Y-m-d H:i:s'),
					'updated_at' => date('Y-m-d H:i:s'),
				);
			}
			DrugsKeywords::insert($insert_keyword_array);
			return Response::json(['status' => true, 'message' => 'Medication has been added.', 'drug_id' => $id]);
		 }catch(\Exception $e){
			 return Response::json(['status' => false, 'message' => $e->getMessage()]);
		 }
	 }
	 /*
	 * Get all drugs keywords
	 * @param  Request in json format
	 * @return array keywords
	 */
	 public function getDrugsKeywords(){
		$keywords = DrugsKeywords::select('keyword')->orderby('keyword', 'asc')->distinct()->get()->toArray();
		return Response::json(['status' => true, 'keywords' => array_column($keywords, 'keyword')]);
	 }
	
}	