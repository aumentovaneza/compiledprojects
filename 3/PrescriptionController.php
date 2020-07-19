<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Drugs;
use App\Models\DrugsKeywords;
use Auth, Request, Response, Redirect, Session, Hash, Cart, DB, PDF, File, Log;
use Illuminate\Support\Facades\Validator;
use App\Models\Patients;
use App\Services\EmailService;
use App\User;
use App\Models\Prescriptions;
use App\Models\PrescriptionsDrugs;
use App\Services\SMSService;
use App\Services\FileMakerService;
use App\Models\PrescribersPatients;
use App\Models\Prescribers;
use App\Models\DrugsDosage;
use App\Models\PrescriptionDrugsReminderDates;
use App\Models\UsersSettings;
use App\Models\SpedPrescriptionsTemp;

class PrescriptionController extends Controller
{
	public function __construct(EmailService $emailservice) {
		$this->emailservice = $emailservice;
	}
	/*
    |--------------------------------------------------------------------------
    | Prescription Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles the Prescription activities after authenticate.
    | Such as search for drugs, create prescription, view prescriptions etc.
    */

    /**
     * Loads a prescription view.
     *
     * @param  NULL
     * @return NULL
     */
    public function getPrescription(){
		$practitioner_expiry_date = Auth::guard('prescriber')->user()->practitioner_expiry_date;
		if(strtotime($practitioner_expiry_date) < strtotime(date('Y-m-d'))){
			Session::put('message' , 'Your practitioner date has been expired, so you are not allowed to create prescription.');
			Session::put('alert-type' , 'alert-warning');
			return Redirect::to('/prescribers/dashboard');
		}
		return view('prescription.index');
    }
	/**
     * Search for drugs from database.
     *
     * @param  search parameters
     * @return search results in json format
     */
	public function getPrescriptionSearch(){
		try{
			$filter_hormones = 0;
			$query = Drugs::orderBy('drugs.name', 'ASC');
			if(Request::has('drug_name') && Request::get('drug_name')){
				$name = Request::get('drug_name');
				$result_array = preg_split("/( and | or )/i", $name, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
				array_unshift($result_array, "AND");
				$keys = [];
				$values = [];
				for($i=0; $i<count($result_array); $i++){
					$a = trim($result_array[$i]);
					if(!strcasecmp('and', $a) || !strcasecmp('or', $a)){
						$keys[] = strtoupper($a);
					}else{
						$values[] = $a;
					}
				}
				if(count($keys) == count($values)){
					$query->where(function($query) use ($keys, $values){
						for($i = 0; $i<count($keys); $i++){
							if($keys[$i] == 'AND'){
								$query->whereRaw("(rx_name LIKE '%{$values[$i]}%' or drugs.name LIKE '%{$values[$i]}%')");
							}else if($keys[$i] == 'OR'){
								$query->orWhereRaw("(rx_name LIKE '%{$values[$i]}%' or drugs.name LIKE '%{$values[$i]}%')");
							}
						}
					});
				}else{
					$name = Request::get('drug_name');
					$query = $query->where('rx_name','LIKE','%'.$name.'%')->orWhere('drugs.name','LIKE','%'.$name.'%');
				}
			}
			if(Request::has('dose')){
				$dose = Request::get('dose');
				$query = $query->where('dose', $dose);
			}
			if(Request::has('formulation')){
				$formulation = Request::get('formulation');
				if($formulation == 'vitamins'){
					$query = $query->where('type', 'V');
				}else if($formulation == 'poms'){
					$query = $query->where('type', 'M');
				}else if($formulation == 'hair-tonic'){
					$query = $query->where('formulation','LIKE','%Hair Tonic%');
				}else{
					$query = $query->where('formulation', $formulation);
				}
			}
			$hormones_array = [];
			if(Request::has('hormones')){
				$hormones = Request::get('hormones');
				foreach($hormones as $hormone){
					$query->where(function($query) use ($hormone){
						$a = "%{$hormone['hormone']} {$hormone['qty']}%";
						$b = "%{$hormone['hormone']}{$hormone['qty']}%";
						$query->where('rx_name','LIKE', $a)->orWhere('rx_name','LIKE', $b);
					});
					if($hormone['hormone'] == 'Biest'){
						$filter_hormones = $filter_hormones + 2;
					}else{
						$filter_hormones = $filter_hormones + 1;
					}
					$hormones_array [] = $hormone['hormone'];
				}
			}
			$check_for_hormones = true;
			if(in_array('Biest', $hormones_array) && $filter_hormones > 0 && count($hormones_array) == 1){
				//$query = $query->where('hormones', '<=', $filter_hormones);
				$check_for_hormones = false;
			}else if($filter_hormones > 0){
				$query = $query->where('hormones', $filter_hormones);
			}
			$results = $query->select('drugs.id', 'drugs.name', 'dose', 'rx_name', 'formulation', 'hormones', 'price','sped_pk')->orderBy('hormones','asc')->get();
			if(count($results) <= 0){
				throw new \Exception('No records found.');
			}
			$drugs = array();
			foreach($results as $drug){
				if($filter_hormones > 0 && $check_for_hormones){
					$ingredients = explode('/', $drug->rx_name);
					if(count($ingredients) == $filter_hormones){
						$a_name = array(
							'drugs_id' => $drug->sped_pk,
							'name' => $drug->rx_name ? $drug->rx_name : $drug->name,
							'price'=> $drug->price,
							'direction_for_user' => $drug->direction_for_user,
							'hormones'=> $drug->hormones
						);
					}else{
						continue;
					}
				}else{
					$a_name = array(
						'drugs_id' => $drug->sped_pk,
						'name' => $drug->rx_name ? $drug->rx_name : $drug->name,
						'price'=> $drug->price,
						'direction_for_user' => $drug->direction_for_user,
						'hormones'=> $drug->hormones
					);
				}
				$drugs[$drug->id] = $a_name;
			}

			array_multisort(array_column($drugs, 'hormones'), SORT_ASC, $drugs);
			$drugs_html = '';
			foreach($drugs as $d){
				$drugs_html .="<li><a data-drugid='".$d['drugs_id']."' id='drug_".$d['drugs_id']."' href='#' data-title='".$d['name']."' draggable='true' ondragstart='drag(event)'>".$d['name']."</a></li>";
			}
			//removed this
			//<span class='plus'>Add to prescription</span>
			return Response::json(['status' => true, 'drugs' => $drugs, 'total_result' => count($drugs), 'drugs_html' => $drugs_html]);
		}catch(\Exception $e){
			return Response::json(['status' => false, 'message' => $e->getMessage()]);
		}
	}
	/**
     * Add drugs item to cart.
     *
     * @param  drug id
     * @return JSON response
     */
	 public function addItemToCart($drug_id){
		$drug = Drugs::where('sped_pk', $drug_id)->first();
		try{
			if(!$drug){
				throw new \Exception('Invalid medications id');
			}
			$drug_id = $drug->id;
			$item_exist_in_cart = Cart::search(function ($cartItem, $rowId) use($drug_id) {
				return $cartItem->id == $drug_id;
			});
			if(count($item_exist_in_cart) > 0){
				throw new \Exception('Medication already added to list.');
			}
			$item = array(
				'id' => $drug->id,
				'name' => $drug->name,
				'price' => $drug->price,
				'options' => array(
					'dose' => $drug->dose,
					'dose_description' => '',
					'dose_duration' => '',
					'no_of_repeats' => 0,
					'direction_for_user' => $drug->direction_for_user,
					'review_date' => '',
					'formulation' => $drug->formulation,
					'description' => $drug->rx_name,
					'type' => $drug->type,
					'sped_pk' => $drug->sped_pk,
					'reminder_dates' => [],
					'dose_description_other' => '',
					'flavour' => '',
					'bases' => '',
				),
				'qty' => 1
			);
			Cart::add($item);
			return Response::json(['status' => true, 'message' => 'Medication has been added successfully.']);
		}catch(\Exception $e){
			return Response::json(['status' => false, 'message' => $e->getMessage()]);
		}
	 }
	/**
     * Add multiple drugs to cart.
     *
     * @param  csv id
     * @return JSON response
     */
    public function addMultipleItemsToCart(){
		try {
            Cart::destroy();
			$drug_ids = Request::get('drugs_ids');
            $drugs = json_decode($drug_ids);
			$invalid_medication_id = 0;
			$items = array();
            foreach ($drugs as $drug_r) {
                $drug = Drugs::where('sped_pk', $drug_r->key)->first();
                if ($drug) {
					$drug_id = $drug->id;
					$flavour = '';
					$items[] = array(
						'id' => $drug->id,
						'name' => $drug->name,
						'price' => $drug->price,
						'options' => array(
							'dose' => $drug_r->quantity ? $drug_r->quantity : $drug->dose,
							'dose_description' => $drug_r->dose_instructions,
							'dose_duration' => '',
							'no_of_repeats' => 0,
							'direction_for_user' => $drug->direction_for_user,
							'review_date' => '',
							'formulation' => $drug->formulation,
							'description' => $drug->rx_name,
							'type' => $drug->type,
							'sped_pk' => $drug->sped_pk,
							'reminder_dates' => [],
							'dose_description_other' => '',
							'flavour' => '',
							'bases' => '',
						),
						'qty' => 1
					);
				}else{
					 $invalid_medication_id++;
				}
            }
			Cart::add($items);
            $response = ['status' => true, 'message' => 'Medication(s) has been added successfully.'];
            if ($invalid_medication_id) {
                $response['message2'] = "{$invalid_medication_id} invalid medication id";
            }
            return Response::json($response);
		} catch(\Exception $e) {
			return Response::json(['status' => false, 'message' => $e->getMessage()]);
		}
    }
	 /**
     * Get drugs items from cart.
     *
     * @param  NULL
     * @return items
     */
	 public function getCartItems(){
		$cart_items = Cart::content();
		$items = array();
		foreach($cart_items as $cart_item){
			$items [] = array(
				'drugs_id' => $cart_item->id,
				'name' => $cart_item->name,
				'price' => $cart_item->price,
				'quantity' => $cart_item->qty,
				'description' => $cart_item->options->description,
			);
		}
		$total_drugs = count($items);
		$total = Cart::subTotal();
		return Response::json(['status' => true, 'items' => $items, 'total_drugs' => $total_drugs, 'total_amount' => $total]);
	}
	/**
     * Delete drugs items from cart.
     *
     * @param  $drug_id
     * @return true on success, otherwise false
     */
	public function deleteItemsFromCart(){
		try{
			if(Request::has('drugs_id')){
				$drugs_id = Request::get('drugs_id');
				for($i=0;$i<count($drugs_id);$i++){
					$drug_id = $drugs_id[$i];
					$item_exist_in_cart = Cart::search(function ($cartItem, $rowId) use($drug_id) {
						return $cartItem->id == $drug_id;
					});
					if(count($item_exist_in_cart) > 0){
						foreach($item_exist_in_cart as $item){
							$row_id = $item->rowId;
							Cart::remove($row_id);
						}
					}
				}
				$total_drugs = count(Cart::content());
				if($total_drugs <= 0){
					Cart::destroy();
				}
				$total = Cart::subTotal();
				return Response::json(['status' => true, 'message' => 'Medication has been removed from list.', 'total_drugs' => $total_drugs, 'total_amount' => $total]);
			} else{
				throw new \Exception('Please select the medications you want to delete.');
			}
		}catch(\Exception $e){
			return Response::json(['status' => false, 'message' => $e->getMessage()]);
		}
	}

	/**
     * Loads a prescription view.
     *
     * @param  NULL
     * @return NULL
     */
	public function getCreateRx(){
		$drugs_dosage = DrugsDosage::select('type', 'dosage', 'id')->get();
		$cream_dosage = [];
		$lozenges_dosage = [];
		$capsules_dosage = [];
		$vitamins_dosage = [];
		foreach($drugs_dosage as $dosage){
			if($dosage->type == 'Cream'){
				$cream_dosage[] = $dosage;
			}
			if($dosage->type == 'Capsules'){
				$capsules_dosage[] = $dosage;
			}
			if($dosage->type == 'Lozenges'){
				$lozenges_dosage[] = $dosage;
			}
			if($dosage->type == 'Vitamins'){
				$vitamins_dosage[] = $dosage;
			}
		}
		return view('prescription.create-rx')
			->with('cream_dosage', $cream_dosage)
			->with('capsules_dosage', $capsules_dosage)
			->with('vitamins_dosage', $vitamins_dosage)
			->with('lozenges_dosage', $lozenges_dosage);
	}
	/**
     * Saves Does description to cart.
     *
     * @param  NULL
     * @return json
     */
	public function saveCreateRxDoseDescription(){
		$dose_quantity = Request::get('dose_quantity');
		$doses_description = Request::get('dose_description');
		$dose_description_other = Request::get('dose_description_other');
		$doses_duration = Request::get('dose_duration');
		$no_of_repeats = Request::get('no_of_repeats');
		$duration_between_repeats = Request::get('duration_between_repeats');
		$review_date = Request::get('review_date');
		$reminder_dates = Request::get('reminder_date');
		if($dose_quantity){
			foreach($dose_quantity as $key => $dose_qty){
				$drug_id = $key;
				$item_exist_in_cart = Cart::search(function ($cartItem, $rowId) use($drug_id) {
					return $cartItem->id == $drug_id;
				});
				if(count($item_exist_in_cart) > 0){
					foreach($item_exist_in_cart as $item){
						$row_id = $item->rowId;
						$options = array(
							'dose' => $dose_qty,
							'dose_description' => '',
							'dose_duration' => '',
							'no_of_repeats' => 0,
							'direction_for_user' => $item->options->direction_for_user,
							'review_date' => '',
							'formulation' => $item->options->formulation,
							'description' => $item->options->description,
							'type' => $item->options->type,
							'sped_pk' => $item->options->sped_pk,
							'reminder_dates' => [],
							'dose_description_other' => '',
							'flavour' => '',
							'bases' => '',
						);
						Cart::update($row_id, ['options' => $options] );
					}
				}
			}
		}
		if($doses_description){
			foreach($doses_description as $key => $dose_description){
				$drug_id = $key;
				$item_exist_in_cart = Cart::search(function ($cartItem, $rowId) use($drug_id) {
					return $cartItem->id == $drug_id;
				});
				if(count($item_exist_in_cart) > 0){
					foreach($item_exist_in_cart as $item){
						$row_id = $item->rowId;
						$options = array(
							'dose' => $item->options->dose,
							'dose_description' => $dose_description,
							'dose_duration' => '',
							'no_of_repeats' => 0,
							'direction_for_user' => $item->options->direction_for_user,
							'review_date' => '',
							'formulation' => $item->options->formulation,
							'description' => $item->options->description,
							'type' => $item->options->type,
							'sped_pk' => $item->options->sped_pk,
							'reminder_dates' => [],
							'dose_description_other' => '',
							'flavour' => '',
							'bases' => '',
						);
						Cart::update($row_id, ['options' => $options] );
					}
				}
			}
		}
		if($dose_description_other){
			foreach($dose_description_other as $key => $dose_description){
				$drug_id = $key;
				$item_exist_in_cart = Cart::search(function ($cartItem, $rowId) use($drug_id) {
					return $cartItem->id == $drug_id;
				});
				if(count($item_exist_in_cart) > 0){
					foreach($item_exist_in_cart as $item){
						$row_id = $item->rowId;
						$options = array(
							'dose' => $item->options->dose,
							'dose_description' => $item->options->dose_description,
							'dose_duration' => '',
							'no_of_repeats' => 0,
							'direction_for_user' => $item->options->direction_for_user,
							'review_date' => '',
							'formulation' => $item->options->formulation,
							'description' => $item->options->description,
							'type' => $item->options->type,
							'sped_pk' => $item->options->sped_pk,
							'reminder_dates' => [],
							'dose_description_other' => $dose_description,
							'flavour' => '',
							'bases' => '',
						);
						Cart::update($row_id, ['options' => $options] );
					}
				}
			}
		}
		if($doses_duration){
			foreach($doses_duration as $key => $duration){
				$drug_id = $key;
				$item_exist_in_cart = Cart::search(function ($cartItem, $rowId) use($drug_id) {
					return $cartItem->id == $drug_id;
				});
				if(count($item_exist_in_cart) > 0){
					foreach($item_exist_in_cart as $item){
						$row_id = $item->rowId;
						$options = array(
							'dose' => $item->options->dose,
							'dose_description' => $item->options->dose_description,
							'dose_duration' => $duration,
							'no_of_repeats' => 0,
							'direction_for_user' => $item->options->direction_for_user,
							'review_date' => '',
							'formulation' => $item->options->formulation,
							'description' => $item->options->description,
							'type' => $item->options->type,
							'sped_pk' => $item->options->sped_pk,
							'reminder_dates' => [],
							'dose_description_other' => $item->options->dose_description_other,
							'flavour' => '',
							'bases' => '',
						);
						Cart::update($row_id, ['options' => $options] );

					}
				}
			}
		}
		if($no_of_repeats){
			foreach($no_of_repeats as $key => $repeat){
				$drug_id = $key;
				$item_exist_in_cart = Cart::search(function ($cartItem, $rowId) use($drug_id) {
					return $cartItem->id == $drug_id;
				});
				if(count($item_exist_in_cart) > 0){
					foreach($item_exist_in_cart as $item){
						$row_id = $item->rowId;
						$options = array(
							'dose' => $item->options->dose,
							'dose_description' => $item->options->dose_description,
							'dose_duration' => $item->options->dose_duration,
							'no_of_repeats' => $repeat,
							'direction_for_user' => $item->options->direction_for_user,
							'review_date' => '',
							'formulation' => $item->options->formulation,
							'description' => $item->options->description,
							'type' => $item->options->type,
							'sped_pk' => $item->options->sped_pk,
							'reminder_dates' => [],
							'dose_description_other' => $item->options->dose_description_other,
							'flavour' => '',
							'bases' => '',
						);
						Cart::update($row_id, ['options' => $options] );
					}
				}
			}
		}
		if($review_date){
			foreach($review_date as $key => $date){
				$drug_id = $key;
				$item_exist_in_cart = Cart::search(function ($cartItem, $rowId) use($drug_id) {
					return $cartItem->id == $drug_id;
				});
				if(count($item_exist_in_cart) > 0){
					foreach($item_exist_in_cart as $item){
						$row_id = $item->rowId;
						$options = array(
							'dose' => $item->options->dose,
							'dose_description' => $item->options->dose_description,
							'dose_duration' => $item->options->dose_duration,
							'no_of_repeats' => $item->options->no_of_repeats,
							'direction_for_user' => $item->options->direction_for_user,
							'review_date' => $date,
							'formulation' => $item->options->formulation,
							'description' => $item->options->description,
							'type' => $item->options->type,
							'sped_pk' => $item->options->sped_pk,
							'reminder_dates' => [],
							'dose_description_other' => $item->options->dose_description_other,
							'flavour' =>'',
							'bases' => '',
						);
						Cart::update($row_id, ['options' => $options] );

					}
				}
			}
		}
		if($reminder_dates){
			foreach($reminder_dates as $key => $dates){
				$drug_id = $key;
				$item_exist_in_cart = Cart::search(function ($cartItem, $rowId) use($drug_id) {
					return $cartItem->id == $drug_id;
				});
				if(count($item_exist_in_cart) > 0){
					foreach($item_exist_in_cart as $item){
						$row_id = $item->rowId;
						$options = array(
							'dose' => $item->options->dose,
							'dose_description' => $item->options->dose_description,
							'dose_duration' => $item->options->dose_duration,
							'no_of_repeats' => $item->options->no_of_repeats,
							'direction_for_user' => $item->options->direction_for_user,
							'review_date' => $item->options->review_date,
							'formulation' => $item->options->formulation,
							'description' => $item->options->description,
							'type' => $item->options->type,
							'sped_pk' => $item->options->sped_pk,
							'reminder_dates' => $dates,
							'dose_description_other' => $item->options->dose_description_other,
							'flavour' => '',
							'bases' => '',
						);
						Cart::update($row_id, ['options' => $options] );

					}
				}
			}
		}
		if(Request::has('flavour')){
			$flavours = Request::get('flavour');
			foreach($flavours as $key => $flavour){
				$drug_id = $key;
				$item_exist_in_cart = Cart::search(function ($cartItem, $rowId) use($drug_id) {
					return $cartItem->id == $drug_id;
				});
				if(count($item_exist_in_cart) > 0){
					foreach($item_exist_in_cart as $item){
						$row_id = $item->rowId;
						$options = array(
							'dose' => $item->options->dose,
							'dose_description' => $item->options->dose_description,
							'dose_duration' => $item->options->dose_duration,
							'no_of_repeats' => $item->options->no_of_repeats,
							'direction_for_user' => $item->options->direction_for_user,
							'review_date' => $item->options->review_date,
							'formulation' => $item->options->formulation,
							'description' => $item->options->description,
							'type' => $item->options->type,
							'sped_pk' => $item->options->sped_pk,
							'reminder_dates' => $item->options->reminder_dates,
							'dose_description_other' => $item->options->dose_description_other,
							'flavour' => '',
							'bases' => '',
						);
						Cart::update($row_id, ['options' => $options] );

					}
				}
			}
		}
		if(Request::has('bases')){
			$bases = Request::get('bases');
			foreach($bases as $key => $base){
				$drug_id = $key;
				$item_exist_in_cart = Cart::search(function ($cartItem, $rowId) use($drug_id) {
					return $cartItem->id == $drug_id;
				});
				if(count($item_exist_in_cart) > 0){
					foreach($item_exist_in_cart as $item){
						$row_id = $item->rowId;
						$options = array(
							'dose' => $item->options->dose,
							'dose_description' => $item->options->dose_description,
							'dose_duration' => $item->options->dose_duration,
							'no_of_repeats' => $item->options->no_of_repeats,
							'direction_for_user' => $item->options->direction_for_user,
							'review_date' => $item->options->review_date,
							'formulation' => $item->options->formulation,
							'description' => $item->options->description,
							'type' => $item->options->type,
							'sped_pk' => $item->options->sped_pk,
							'reminder_dates' => $item->options->reminder_dates,
							'dose_description_other' => $item->options->dose_description_other,
							'flavour' => '',
							'bases' => '',
						);
						Cart::update($row_id, ['options' => $options] );

					}
				}
			}
		}
		return Response::json(['status' => true, 'message' => 'Cart has been updated successfully.']);
	}
	/**
     * Loads a prescription view.
     *
     * @param  NULL
     * @return NULL
     */
	public function getCreateRxPatientInfo(){
		if(Request::has('patient_id')){
			$id = Request::get('patient_id');
			$patient = Patients::find($id);
			if($patient && $patient->prescribers && Cart::count() > 0){
				return view('prescription.create-rx-patient-info-edit')->with('patient', $patient);
			}
		}
		if(Cart::count() > 0){
			return view('prescription.create-rx-patient-info');
		}else{
			return Redirect::to('/prescription/create-rx');
		}
	}
	/**
     * Saves Patient information.
     *
     * @param  NULL
     * @return Redirect to PDF review page
     */
	public function saveCreateRxPatientInfo(){
		if(Request::has('patient_id') && Request::get('patient_id')){
			$id = Request::get('patient_id');
			$patient = Patients::find($id);
			if($patient){
				if(Session::has('existing-patient') && Session::get('existing-patient') == true){
					$patient->message = Request::get('message');
					$patient->save();
				}else{
					$patient->title = Request::get('title');
					$patient->name = Request::get('name');
					if(Request::has('phone_number')){
						$patient->phone_number = Request::get('phone_number');
					}else{
						$patient->phone_number = Request::get('full_phone');
					}
					$patient->address_line_1 = Request::get('address_line_1');
					$patient->address_line_2 = Request::get('address_line_2');
					$patient->town = Request::get('town');
					$patient->country = Request::get('country');
					$patient->postcode = Request::get('postcode');
					$patient->date_of_birth = date('Y-m-d', strtotime(Request::get('date_of_birth')));
					$patient->message = Request::get('message');
					$patient->save();
					$user_id = $patient->user_id;
					$user = User::find($user_id);
					if($user){
						$name = $this->split_name($patient->name);
						$user->first_name = $name[0];
						$user->last_name = $name[1];
						$user->save();
					}
				}
				Session::put('message' , 'Patient information has been updated.');
				Session::put('alert-type' , 'alert-success');
				return Redirect::to('/prescribers/create-rx/preview?patient_id='.$patient->id);
			}else{
				Session::put('message' , 'Patient information not found.');
				Session::put('alert-type' , 'alert-warning');
				return Redirect::to('/prescribers/create-rx/patient-info?patient_id='.$id);
			}
		}
		$all = Request::all();
		$validator = Validator::make($all, [
				'name' => 'required|max:100',
				'email' => 'required|max:200|unique:patients|unique:users|email',
				'date_of_birth' => 'nullable|date_format:d-m-Y',
			],
			[
				'name.required' => 'Please enter patient name',
				'email.required' => "Please enter patient's email",
				'email.email' => "Please enter valid patient's email",
				'email.unique' => 'Email already exists',
			]
		);
		if ($validator->fails()) {
            return redirect('/prescribers/create-rx/patient-info')->withErrors($validator)->withInput();
        }
		try{
			$patient_id = DB::transaction(function (){
				$prescrier_id = Auth::guard('prescriber')->user()->id;
				$name = $this->split_name(Request::get('name'));
				$random_password = str_random(8);
				$user = new User();
				$user->first_name = $name[0];
				$user->last_name = $name[1];
				$user->email = Request::get('email');
				$user->password = Hash::make($random_password);
				$user->role_id = 3;
				$user->is_active = true;
				$user->save();
				$patient = new Patients();
				$patient->user_id = $user->id;
				//$patient->prescriber_id = $prescrier_id;
				$patient->title = Request::get('title');
				$patient->name = Request::get('name');
				$patient->email = Request::get('email');
				if(Request::has('phone_number')){
					$patient->phone_number = Request::get('phone_number');
				}else{
					$patient->phone_number = Request::get('full_phone');
				}
				$patient->address_line_1 = Request::get('address_line_1');
				$patient->address_line_2 = Request::get('address_line_2');
				$patient->town = Request::get('town');
				$patient->country = Request::get('country');
				$patient->postcode = Request::get('postcode');
				$patient->date_of_birth = date('Y-m-d', strtotime(Request::get('date_of_birth')));
				$patient->message = Request::get('message');
				$patient->save();
				$presciber_patient = new PrescribersPatients();
				$presciber_patient->patients_id = $patient->id;
				$presciber_patient->prescribers_id = $prescrier_id;
				$presciber_patient->save();
				$insert_array = [];
				$insert_array[] = array(
					'user_id' => $user->id,
					'name' => 'remind_by_sms',
					'value' => 'true',
					'created_at' => date('Y-m-d H:i:s'),
					'updated_at' => date('Y-m-d H:i:s'),
				);
				$insert_array[] = array(
					'user_id' => $user->id,
					'name' => 'remind_by_email',
					'value' => 'true',
					'created_at' => date('Y-m-d H:i:s'),
					'updated_at' => date('Y-m-d H:i:s'),
				);
				if(count($insert_array)){
					UsersSettings::insert($insert_array);
				}
				return $patient->id;
			});
			if($patient_id){
				Session::put('message' , 'Patient information has been saved.');
				Session::put('alert-type' , 'alert-success');
				return Redirect::to('/prescribers/create-rx/preview?patient_id='.$patient_id);
			}else{
				throw new \Exception('Something went wrong with your submission request.');
			}

		}catch(\Exception $e){
			Session::put('message' , $e->getMessage());
			Session::put('alert-type' , 'alert-warning');
			return Redirect::to('/prescribers/create-rx/patient-info');
		}
	}
	/**
     * Loads a prescription preview of a patient.
     *
     * @param  patient_id
     * @return Preview Screen
     */
	public function getCreateRxPreview(){
		if(Request::has('patient_id') && Cart::count() > 0){
			$prescrier_id = Auth::guard('prescriber')->user()->id;
			$id = Request::get('patient_id');
			$patient = Patients::find($id);
			$prescriber = Prescribers::find($prescrier_id);
			if($patient && $patient->prescribers){
				$prescription_uid = strtoupper(substr(md5(microtime()),rand(0,26),5));
				return view('prescription.rx-preview')->with('patient', $patient)->with('prescription_uid', $prescription_uid)->with('prescriber', $prescriber);
			}
		}
		return Redirect::to('404');
	}

	/**
     * create patient when prescriber create presciption from dashboard
     *
     * @param  patient []
     * @return patient_id
     */
	private function createPatientForDashboard($selected_patient){
		$patient = DB::transaction(function () use($selected_patient){
			$prescrier_id = Auth::guard('prescriber')->user()->id;
			$random_password = str_random(8);
			$user = new User();
			$user->first_name = $selected_patient['first_name'];
			$user->last_name = $selected_patient['last_name'];
			$user->email = $selected_patient['email'];
			$user->password = Hash::make($random_password);
			$user->role_id = 3;
			$user->is_active = true;
			$user->save();
			$patient = new Patients();
			$patient->user_id = $user->id;
			$patient->title = $selected_patient['title'];
			$patient->name = $selected_patient['full_name'];
			$patient->email = $selected_patient['email'];
			$patient->phone_number = $selected_patient['phone_number'];
			$patient->address_line_1 = $selected_patient['address_line_1'];
			$patient->address_line_2 = $selected_patient['address_line_2'];
			$patient->town = $selected_patient['city'];
			$patient->county = $selected_patient['county'];
			$patient->country = $selected_patient['country'];
			$patient->postcode = $selected_patient['postcode'];

			$patient->date_of_birth = date('Y-m-d', strtotime($selected_patient['dob']));
			$patient->save();
			$presciber_patient = new PrescribersPatients();
			$presciber_patient->patients_id = $patient->id;
			$presciber_patient->prescribers_id = $prescrier_id;
			$presciber_patient->save();
			$insert_array = [];
			$insert_array[] = array(
				'user_id' => $user->id,
				'name' => 'remind_by_sms',
				'value' => 'true',
				'created_at' => date('Y-m-d H:i:s'),
				'updated_at' => date('Y-m-d H:i:s'),
			);
			$insert_array[] = array(
				'user_id' => $user->id,
				'name' => 'remind_by_email',
				'value' => 'true',
				'created_at' => date('Y-m-d H:i:s'),
				'updated_at' => date('Y-m-d H:i:s'),
			);
			if(count($insert_array)){
				UsersSettings::insert($insert_array);
			}
			$reference_key = 'PP'.time().$patient->id;
			$patient_record_set['fieldData'] = array(
				'prescriptionRefKey' => $reference_key,
				'title' => $patient->title ? $patient->title : '',
				'nameFirst' => $user->first_name,
				'nameLast' => $user->last_name,
				'dateOfBirth' => date('m/d/Y', strtotime($patient->date_of_birth)),
				'notes' => $patient->message ? $patient->message : '',
				'patientIdFromWebPortal' => $patient->id,
				'homeAddressStreet1' => $patient->address_line_1 ? $patient->address_line_1 : '',
				'homeAddressStreet2' => $patient->address_line_2 ? $patient->address_line_2 : '',
				'homeAddressStreet3' => '',
				'homeAddressCity' => $patient->town ? $patient->town : '',
				'homeAddressCounty' => $patient->county ? $patient->county: '',
				'homeAddressCountry' => $patient->country ? $patient->country: '',
				'homeAddressPostcode' => $patient->postcode ? $patient->postcode : '',
				'contactNumber1' => $patient->phone_number ? $patient->phone_number: '',
				'contactEmail' => $patient->email,
			);
			$fm = new FileMakerService();
			$access_token = $fm->getFMAccessTokenFromSession();
			if($access_token){
				$patient_fm_status = $fm->insertPatientRecord($access_token, $patient_record_set);
				if($patient_fm_status){
					/* Process all records from temp FM tables to FM Tables*/
					$data['fieldData'] = array(
						'prescriptionRefKey' => $reference_key,
						'patientTempTableImport' => 'SUCCESS',
						'tempTableImport' => 'SUCCESS'
					);
					$data['script'] = "RestDataAPIAddNewPatient";
					$data['script.param'] = $reference_key;
					$res = $fm->processAllTempRecords($access_token, $data);
					if($res->messages && $res->messages[0]->message == 'OK') {
						$patient_p = Patients::find($patient->id);
						if($patient_p && $patient_p->patient_fm_pk == ''){
							$p = json_decode($res->response->scriptResult);
							if($p->Patient_Pk){
								$patient_p->patient_fm_id = $p->Patient_Id;
								$patient_p->patient_fm_pk = $p->Patient_Pk;
								$patient_p->save();
							}
						}
					}
				}
			}
			return $patient;
		});
		return $patient;
	}
	/**
     * Saves prescription information to db.
     *
     * @param  patient_id
     * @return Preview Screen
     */
	public function approve(){
		try{
			if(Auth::guard('prescriber')->user()->role_id == 4){
				throw new \Exception('You are not authorised to perform this action.');
			}
			if(Cart::count() > 0 && Session::has('selected_patient_info')){
				$prescription_id = DB::transaction(function(){
					$prescrier_id = Auth::guard('prescriber')->user()->id;
					$sess_patient = Session::get('selected_patient_info');
					$patient_email = $sess_patient['email'];
					$patient = Patients::where('email', $patient_email)->first();
					if($patient_email == '' || ($patient && $patient->email == $patient_email)){
						$precription_temp = new SpedPrescriptionsTemp();
						$precription_temp->uid = strtoupper(substr(md5(microtime()),rand(0,26),9));
						if(Request::has('notes')){
							$precription_temp->notes = Request::get('notes');
						}
						$precription_temp->patient_fm_id = $sess_patient['patient_id'];
						$precription_temp->prescriber_id = $prescrier_id;
						$precription_temp->save();
						$sped_response = $this->insertRecordsToFileMakerIfNotEmail($precription_temp);
						Session::put('sped_response', $sped_response);
						Session::put('created_prescription', false);
						Session::put('sped_patient', $sess_patient);
						Session::put('exist_in_sped', $sess_patient['sped_existing_patient']);
						return 'PT'.$precription_temp->id;
						
						//throw new \Exception('Email address is missing, patient must have a valid email address to create a prescription.');
					}
					if(!$patient){
						Session::put('portal_exist_patient', false);
						$patient = $this->createPatientForDashboard($sess_patient);
						if($patient->patient_fm_pk){
							$sess_patient['sped_existing_patient'] = true;
						}
					}else{
						Session::put('portal_exist_patient', true);
					}

					if($patient && $patient->prescribers){
						/*if($sess_patient['patient_key'] && $patient->patient_fm_pk == ''){
							$patient->patient_fm_id = $sess_patient['patient_id'];
							$patient->patient_fm_pk = $sess_patient['patient_key'];
							$patient->save();
						}*/

						$prescription_uid = strtoupper(substr(md5(microtime()),rand(0,26),9));
						$prescription = new Prescriptions();
						$prescription->uid = $prescription_uid;
						if(Request::has('notes')){
							$prescription->notes = Request::get('notes');
						}
						$prescription->patient_id = $patient->id;
						$prescription->prescriber_id = $prescrier_id;
						$prescription->date = date('Y-m-d');
						$prescription->save();
						$prescription_id = $prescription->id;
						$cart_items = Cart::content();
						foreach($cart_items as $cart_item){
							if($cart_item->options->type == 'F' && $cart_item->options->formulation == 'Lozenges'){
								if($cart_item->options->dose == 15){
									$devider = 2;
									$repeats = $cart_item->options->no_of_repeats;
									$original_repeats_value = $repeats;
									$repeats = ($repeats/$devider) - 0.5;
								}else if($cart_item->options->dose == 30){
									$repeats = $cart_item->options->no_of_repeats;
									$original_repeats_value = $repeats;
								}
							}else{
								$repeats = $cart_item->options->no_of_repeats ? $cart_item->options->no_of_repeats : 0;
								$original_repeats_value = $repeats;
							}
							$p_drugs = new PrescriptionsDrugs();
							$p_drugs->prescriptions_id = $prescription_id;
							$p_drugs->drugs_id = $cart_item->id;
							$p_drugs->duration = $cart_item->options->dose_duration;
							$p_drugs->dose_description = $cart_item->options->dose_description;
							$p_drugs->no_of_repeats = $repeats;
							$p_drugs->repeats_original = $original_repeats_value;
							$p_drugs->review_date = date('Y-m-d', strtotime($cart_item->options->review_date));
							$p_drugs->doses = $cart_item->options->dose;
							$p_drugs->flavour = $cart_item->options->flavour;
							$p_drugs->bases = $cart_item->options->bases;
							$p_drugs->save();
							if($cart_item->options->reminder_dates){
								$insert_array = [];
								foreach($cart_item->options->reminder_dates as $date){
									$insert_array[] = array(
										'patient_id' => $patient->id,
										'prescriptions_drugs_id' => $p_drugs->id,
										'date' => date('Y-m-d', strtotime($date)),
										'created_at' => date('Y-m-d H:i:s'),
										'updated_at' => date('Y-m-d H:i:s'),
									);
								}
								if(count($insert_array)){
									PrescriptionDrugsReminderDates::insert($insert_array);
								}
							}
						}
						$sped_response = $this->insertRecordsToFileMaker($prescription_id);
						Session::put('sped_response', $sped_response);
						Session::put('created_prescription', false);
						Session::put('sped_patient', $patient);
						Session::put('exist_in_sped', $sess_patient['sped_existing_patient']);
						return $prescription_id;
					}
				});
				if($prescription_id){
					return Response::json(['status' => true, 'message' => 'Prescription has been approved successfully.', 'prescription_id' => $prescription_id]);
				}
			}else{
				throw new \Exception('Patient is not selected or medications are missing to select.');
			}
			throw new \Exception('Something went wrong with processing requests.');
		}catch(\Exception $e){
			return Response::json(['status' => false, 'message' => $e->getMessage()]);
		}
	}

	/**
     * Loads a prescription thank you.
     *
     * @param  prescription_id
     * @return thank you view
     */
	public function getPrescriptionThankyou(){
		if(Request::has('prescription_id') && Request::get('prescription_id')){
			$prescription_id = Request::get('prescription_id');
			if(Session::has('fm-called') && !Session::get('fm-called')){
				$this->insertRecordsToFileMaker($prescription_id);
				Session::put('fm-called', true);
			}
			return view('prescription.thank-you')->with('prescription_id', $prescription_id );
		}
		return Redirect::to('404');
	}
	/**
     * Loads a prescription thank you.
     *
     * @param  prescription_id
     * @return thank you view
     */
	public function getRxThankyouView(){
		$prescription_id = Request::get('prescription_id');
		$sped_response = Session::get('sped_response');
		$sped_patient = Session::get('sped_patient');
		$prescription_uid = '';
		$notes = '';
		if(Session::has('created_prescription') && !Session::get('created_prescription')){
			if($sped_response->messages[0]->message == 'OK'){
				$sped_response = json_decode($sped_response->response->scriptResult);
				$prescription_uid = $sped_response->prescriptionId;
				if(substr( $prescription_id, 0, 2 ) === "PT"){
					$prescription_tmp_id = (int) filter_var($prescription_id, FILTER_SANITIZE_NUMBER_INT);
					$prescription = SpedPrescriptionsTemp::find($prescription_tmp_id);
				}else{
					$prescription = Prescriptions::find($prescription_id);
				}
				if($prescription){
					$notes = $prescription->notes;
				}
				$this->generateAndSavePdfToSped($sped_response, $prescription_id, $sped_patient, $notes);
				Session::put('created_prescription', true);
				Session::forget('sped_patient');
				Session::forget('sped_response');
				Session::forget('sped_access_token');
				Session::forget('reference_key');
				Session::forget('portal_exist_patient');
				
			}
		}
		return response()->view('prescribers.partials.thank-you', ['prescription_id' => $prescription_id, 'prescription_uid' => $prescription_uid, 'notes' => $notes], 200)->header('Content-Type', 'text/html');
	}
	/**
     * Insert patient, prescription and prescription items information to File Maker
     *
     * @param  prescription_id
     * @return NULL
     */
	private function insertRecordsToFileMaker($presciption_id){
		$prescription = Prescriptions::where('prescriptions.id', $presciption_id)
			->join('patients', 'prescriptions.patient_id', '=', 'patients.id')
			->join('prescribers', 'prescriptions.prescriber_id', '=', 'prescribers.id')
			->select('patients.id as patient_id', 'prescriptions.id', 'prescriptions.uid', 'patients.title', 'patients.name', 'patients.email', 'patients.phone_number', 'patients.address_line_1', 'patients.address_line_2', 'patients.town', 'patients.country', 'patients.postcode', 'patients.date_of_birth', 'patients.message', 'prescribers.first_name as prescribers_first_name', 'prescribers.last_name as prescribers_last_name', 'name_of_clinic','prescriptions.id as prescription_id', 'patients.patient_fm_pk', 'notes')->get();

		$sped_existing_patient = Session::get('exist_in_sped');
		$sped_response = false;
		if($prescription){
			$fm = new FileMakerService();
			$access_token = $fm->getFMAccessTokenFromSession();
			if($access_token){
				Session::put('sped_access_token', $access_token);
				Log::useFiles(storage_path().'/logs/fmApis.log');
				Log::info('Fm called for prescription id', ['id' => $presciption_id]);
				$patient_record_set = [];
				$prescription_record_set = [];
				$reference_key = '';
				foreach($prescription as $record){
					$reference_key = $record->uid.$record->id;
					$name = $record->name;
					$parts = explode(" ", $name);
					$patient_lastname = array_pop($parts);
					$patient_firstname = implode(" ", $parts);
					if($sped_existing_patient){
						$patient_record_set['fieldData'] = array(
							'prescriptionRefKey' => $reference_key,
							'existInSPED' => 'YES',
							'patientFk' => $record->patient_fm_pk,
						);
					}else{
						$patient_record_set['fieldData'] = array(
							'prescriptionRefKey' => $reference_key,
							'title' => $record->title ? $record->title : '',
							'nameFirst' => $patient_firstname,
							'nameLast' => $patient_lastname,
							'dateOfBirth' => date('m/d/Y', strtotime($record->date_of_birth)),
							'notes' => $record->message ? $record->message : '',
							'patientIdFromWebPortal' => $record->patient_id,
							'homeAddressStreet1' => $record->address_line_1 ? $record->address_line_1 : '',
							'homeAddressStreet2' => $record->address_line_2 ? $record->address_line_2 : '',
							'homeAddressStreet3' => '',
							'homeAddressCity' => $record->town ? $record->town : '',
							'homeAddressCountry' => $record->country ? $record->country: '',
							'homeAddressPostcode' => $record->postcode ? $record->postcode : '',
							'contactNumber1' => $record->phone_number ? $record->phone_number: '',
							'contactEmail' => $record->email,
						);
					}
					$prescription_record_set['fieldData'] = array(
						'prescriptionRefKey' => $reference_key,
						'clinicName' => $record->name_of_clinic,
						'notes' => $record->notes ? $record->notes : '',
						'patientNameFirst' => $patient_firstname,
						'patientNameLast' => $patient_lastname,
						'patientTitle' => $record->title ? $record->title : '',
						'prescriber' => $record->prescribers_first_name.' '.$record->prescribers_last_name,
						'prescriptionIdFromWebPortal' => $record->prescription_id,
						'prescriptionNumberFromWebPortal' => $record->uid,
					);
				}
				$patient_fm_status = $fm->insertPatientRecord($access_token, $patient_record_set);
				$prescription_fm_status = $fm->insertPrescriptionRecord($access_token, $prescription_record_set);
				$presciption_drugs = PrescriptionsDrugs::where('prescriptions_id', $presciption_id)
					->join('drugs', 'prescriptions_drugs.drugs_id', '=', 'drugs.id')
					->select('name', 'formulation', 'dose_description', 'no_of_repeats', 'prescriptions_drugs.id as presciption_drugs_id', 'type', 'sped_pk', 'prescriptions_drugs.doses as dose', 'flavour', 'bases', 'repeats_original')
					->get();
				$prescription_items_status = [];
				if($presciption_drugs){
					foreach($presciption_drugs as $drug){
						
						$prescription_item_record_set['fieldData'] = array(
							'prescriptionRefKey' => $reference_key,
							'prescriptionItemIdFromWebPortal' => $drug->presciption_drugs_id,
							'formulaMedicineKf' => $drug->sped_pk,
							'formulaOrMedicine' => $drug->type,
							'itemName' => $drug->name,
							'doseInstructions' => $drug->dose_description,
							'quantity' => $drug->dose,
							'repeats' => $drug->no_of_repeats ? $drug->no_of_repeats : 0,
							'repeatsLeft' => $drug->no_of_repeats + 1,
							//'repeatsOriginal' => $drug->repeats_original,
							//'flavour' => $drug->flavour? $drug->flavour : '',
							//'base' => $drug->bases? $drug->bases : '',
						);
						$prescription_items_status[] = $fm->insertPrescriptionDrugsRecord($access_token, $prescription_item_record_set);
					}
				}
				$false_exist = false;
				if(in_array(false, $prescription_items_status, true)){
					$false_exist = true;
				}
				$patient_fm_import = 'FAIL';
				$prescription_fm_import = 'FAIL';
				$prescription_items_import = 'FAIL';
				if($patient_fm_status){
					$patient_fm_import = 'SUCCESS';
				}
				if($prescription_fm_status){
					$prescription_fm_import = 'SUCCESS';
				}
				if(!$false_exist){
					$prescription_items_import = 'SUCCESS';
				}
				$temp_table_import = 'SUCCESS';
				if($patient_fm_import == 'FAIL' || $prescription_fm_import == 'FAIL' || $prescription_items_import == 'FAIL'){
					$temp_table_import = 'FAIL';
				}
				if(!$false_exist && $patient_fm_status && $prescription_fm_status){
					/* Process all records from temp FM tables to FM Tables*/
					$data['fieldData'] = array(
						'prescriptionRefKey' => $reference_key,
						'prescriptionIdFromWebPortal' => $presciption_id,
						'patientTempTableImport' => $patient_fm_import,
						'prescriptionTempTableImport' => $prescription_fm_import,
						'prescriptionItemsTempTableImport' => $prescription_items_import,
						'tempTableImport' => $temp_table_import
					);
					$data['script'] = "RestDataAPIProcessAllTempRecords";
					$data['script.param'] = $reference_key;
					$sped_response = $fm->processAllTempRecords($access_token, $data);
				}else{
					/* Clear all records from temp FM tables */
					$data['fieldData'] = array(
						'prescriptionRefKey' => $reference_key,
						'prescriptionIdFromWebPortal' => $presciption_id,
						'patientTempTableImport' => $patient_fm_import,
						'prescriptionTempTableImport' => $prescription_fm_import,
						'prescriptionItemsTempTableImport' => $prescription_items_import,
						'tempTableImport' => $temp_table_import
					);
					$data['script'] = "RestDataAPIFlushAllTempRecords";
					$data['script.param'] = $reference_key;
					$res = $fm->clearAllTempRecords($access_token, $data);
					$sped_response = false;
				}
				Session::put('reference_key', $reference_key);
			}
		}
		return $sped_response;

	}
	/**
     * generate and Save PDF to Sped
     *
     * @param  response, prescription object, patient id
     * @return array
     */
	private function generateAndSavePdfToSped($response, $prescription_id, $patient, $notes){
		$access_token = Session::get('sped_access_token');
		$reference_key = 'UP'.$prescription_id.time();
		$prescription_uid = $response->prescriptionId;
		$temp_prescription = false;
		if(substr( $prescription_id, 0, 2 ) === "PT"){
			$temp_prescription = true;
			$patient['name'] = $patient['full_name'];
			$patient['date_of_birth'] = $patient['dob'];
			$patient['town'] = $patient['city'];
			$patient = (object) $patient;
		}
		$pdf = PDF::loadView('prescription.pdf', ['patient' => $patient, 'prescription_uid' => $prescription_uid, 'notes' => $notes])->setPaper('a4', 'portrait')->setWarnings(false);
		if (!file_exists(storage_path('prescriptions'))) {
			File::makeDirectory(storage_path('prescriptions'), $mode = 0777, true, true);
		}
		$sped_pdf = PDF::loadView('prescription.sped-pdf', ['patient' => $patient, 'prescription_uid' => $prescription_uid, 'notes' => $notes])->setPaper('a4', 'portrait')->setWarnings(false);
		
		if(substr( $prescription_id, 0, 2 ) === "PT"){
			$prescription_tmp_id = (int) filter_var($prescription_id, FILTER_SANITIZE_NUMBER_INT);
			$prescription = SpedPrescriptionsTemp::find($prescription_tmp_id);
		}else{
			$prescription = Prescriptions::find($prescription_id);
		}
		if($prescription){
			$prescription->uid = $prescription_uid;
			$prescription->save();
		}
		$pdf->save(storage_path('prescriptions/prescription-'.$prescription_id.'.pdf'));
		$sped_pdf->save(storage_path('prescriptions/prescription-sped-'.$prescription_id.'.pdf'));
		$to = $patient->email;
		$random_password = str_random(8);
		$existing_patient = Session::get('portal_exist_patient');
		if(!$temp_prescription){
			if($existing_patient){
				$random_password = '';
			}else{
				$user = User::find($patient->user_id);
				$user->password = Hash::make($random_password);
				$user->save();
			}
		}
		/*Save PDF back to sped */
		$fm = new FileMakerService();
		$access_token = $fm->getFMAccessTokenFromSession();
		//$pdf_path = storage_path('prescriptions/prescription-sped-'.$prescription_id.'.pdf');
		$prescription_record_set['fieldData'] = array(
			'prescriptionRefKey' => $reference_key,
			'prescriptionPrimaryKey' => $response->prescriptionPrimaryKey,
			/* 'clinicName' => Auth::guard('prescriber')->user()->name_of_clinic,
			'patientNameFirst' => $patient_firstname,
			'patientNameLast' => $patient_lastname,
			'patientTitle' => $patient->title ? $patient->title : '',
			'prescriber' => Auth::guard('prescriber')->user()->first_name.' '.Auth::guard('prescriber')->user()->last_name,
			'prescriptionIdFromWebPortal' => $prescription_id, */
			'prescriptionOriginalDocumentBase64' => base64_encode($sped_pdf->output()),
		);
		$update_response = $fm->insertPrescriptionRecord($access_token, $prescription_record_set);
		if($update_response) {
			$data['fieldData'] = array(
				'prescriptionRefKey' => $reference_key,
				'prescriptionTempTableImport' => 'SUCCESS',
				'tempTableImport' => 'SUCCESS',
			);
			$data['script'] = "RestDataAPIUpdatePrescriptionRecord";
			$data['script.param'] = $reference_key;
			$res = $fm->processAllTempRecords($access_token, $data);
		}
		$prescriber_name = 'Dr. '.Auth::guard('prescriber')->user()->first_name.' '.Auth::guard('prescriber')->user()->last_name;

		//Split string to form this format: (ex) Miss V.Aumento
		$name = $patient->name;
		list($fname, $lname) = explode(' ', $name,2);
		$firstChar = $fname[0];
		$n_name = $firstChar.".".$lname;

		$result = [
			'patient_email' => $patient->email,
			'title' => $patient->title,
			'patient_name' => $patient->name,
			'password' => $random_password,
			'prescriber_name' => $prescriber_name,
			'new_patient_name' => $n_name,
			'clinic_name' => Auth::guard('prescriber')->user()->name_of_clinic,
			'existing_patient' => $existing_patient,
			'prescription_id' => $prescription_id,
			'dob' => date('d/m/Y', strtotime($patient->date_of_birth))
		];
		/* $this->emailservice->sendMail('emails.send-patient-prescription', $result, $to, 'Your Prescription is here');*/
		/*if(env('ENABLE_SMS')){
			$sms = new SMSService();
			$phone_number = $patient->phone_number;
			if(substr( $patient->phone_number, 0, 1 ) != "+"){
				$address = '';
				if($patient->address_line_1){
					$address .= $patient->address_line_1;
				}
				if($patient->address_line_2){
					$address .= ','.$patient->address_line_2;
				}
				if($patient->town){
					$address .= ','.$patient->town;
				}
				if($patient->country){
					$address .= ','.$patient->country;
				}
				if($patient->postcode){
					$address .= ','.$patient->postcode;
				}
				$phone_number = $sms->convertLocalNumberToInternational($address, $patient->phone_number, $patient->country);
				if($phone_number != ''){
					$patient->phone_number = $phone_number;
					$patient->save();
				}
			}
			$message = $prescriber_name.' has created a prescription for you. You can view the prescription here '.url('/prescription/view',[$prescription_id]);
			$sms->send($phone_number, $message);
		}*/

		$send_prescriber_email = Auth::guard('prescriber')->user()->email;
		if(Auth::guard('prescriber')->user()->clinic_email){
			$send_prescriber_email = Auth::guard('prescriber')->user()->clinic_email;
			$dob = date('d/m/Y', strtotime($patient->date_of_birth));
			$subject = "Prescription {$prescription_uid} for {$n_name}";
			
			$this->emailservice->sendMail('emails.send-prescriber-prescription', $result, $send_prescriber_email, $subject);
		}
		

		//$this->emailservice->sendMailPatientCareAdmin('emails.send-patient-care-prescription', $result, 'Prescription created for '. $name, storage_path('prescriptions/prescription-'.$prescription_id.'.pdf'), 'PrP_'.$prescription_uid.'_'.date('d-m-Y').'.pdf');
		return true;
	}
	
	/**
     * Insert patient, prescription and prescription items information to File Maker, this function only be called when patient don't have email address
     *
     * @param  temp prescription object
     * @return NULL
     */
	 private function insertRecordsToFileMakerIfNotEmail($precription_temp){
		$fm = new FileMakerService();
		$access_token = $fm->getFMAccessTokenFromSession();
		$sped_response = false;
		if($access_token){
			$patient_record_set = [];
			$prescription_record_set = [];
			$sess_patient = Session::get('selected_patient_info');
			$reference_key = $precription_temp->uid.$precription_temp->id;
			$patient_lastname = $sess_patient['last_name'];
			$patient_firstname = $sess_patient['first_name'];
			if($sess_patient['sped_existing_patient']){
				$patient_record_set['fieldData'] = array(
					'prescriptionRefKey' => $reference_key,
					'existInSPED' => 'YES',
					'patientFk' => $sess_patient['patient_key'],
				);
				$prescription_record_set['fieldData'] = array(
					'prescriptionRefKey' => $reference_key,
					'clinicName' => Auth::guard('prescriber')->user()->name_of_clinic,
					'notes' => $precription_temp->notes ? $precription_temp->notes : '',
					'patientNameFirst' => $patient_firstname,
					'patientNameLast' => $patient_lastname,
					'patientTitle' => $sess_patient['title'] ? $sess_patient['title'] : '',
					'prescriber' => Auth::guard('prescriber')->user()->first_name.' '.Auth::guard('prescriber')->user()->last_name,
					'prescriptionIdFromWebPortal' => $precription_temp->id,
					'prescriptionNumberFromWebPortal' => $precription_temp->uid,
				);
				$patient_fm_status = $fm->insertPatientRecord($access_token, $patient_record_set);
				$prescription_fm_status = $fm->insertPrescriptionRecord($access_token, $prescription_record_set);
				$cart_items = Cart::content();
				foreach($cart_items as $cart_item){
					if($cart_item->options->type == 'F' && $cart_item->options->formulation == 'Lozenges'){
						if($cart_item->options->dose == 15){
							$devider = 2;
							$repeats = $cart_item->options->no_of_repeats;
							$original_repeats_value = $repeats;
							$repeats = ($repeats/$devider) - 0.5;
							$repeats_left = $original_repeats_value + 1;
						}else if($cart_item->options->dose == 30){
							$repeats = $cart_item->options->no_of_repeats;
							$original_repeats_value = $repeats;
							$repeats_left = $original_repeats_value + 1;
						}
					}else{
						$repeats = $cart_item->options->no_of_repeats ? $cart_item->options->no_of_repeats : 0;
						$original_repeats_value = $repeats;
						$repeats_left = $original_repeats_value + 1;
					}
					$prescription_item_record_set['fieldData'] = array(
						'prescriptionRefKey' => $reference_key,
						'prescriptionItemIdFromWebPortal' => $cart_item->id,
						'formulaMedicineKf' => $cart_item->options->sped_pk,
						'formulaOrMedicine' => $cart_item->options->type,
						'itemName' => $cart_item->name,
						'doseInstructions' =>  $cart_item->options->dose_description,
						'quantity' => $cart_item->options->dose,
						'repeats' => $repeats,
						//'repeatsOriginal' => $original_repeats_value,
						'repeatsLeft' => $repeats_left,
					);
					$prescription_items_status[] = $fm->insertPrescriptionDrugsRecord($access_token, $prescription_item_record_set);
				}
				$false_exist = false;
				if(in_array(false, $prescription_items_status, true)){
					$false_exist = true;
				}
				$patient_fm_import = 'FAIL';
				$prescription_fm_import = 'FAIL';
				$prescription_items_import = 'FAIL';
				if($patient_fm_status){
					$patient_fm_import = 'SUCCESS';
				}
				if($prescription_fm_status){
					$prescription_fm_import = 'SUCCESS';
				}
				if(!$false_exist){
					$prescription_items_import = 'SUCCESS';
				}
				$temp_table_import = 'SUCCESS';
				if($patient_fm_import == 'FAIL' || $prescription_fm_import == 'FAIL' || $prescription_items_import == 'FAIL'){
					$temp_table_import = 'FAIL';
				}
				if(!$false_exist && $patient_fm_status && $prescription_fm_status){
					/* Process all records from temp FM tables to FM Tables*/
					$data['fieldData'] = array(
						'prescriptionRefKey' => $reference_key,
						'prescriptionIdFromWebPortal' => $precription_temp->id,
						'patientTempTableImport' => $patient_fm_import,
						'prescriptionTempTableImport' => $prescription_fm_import,
						'prescriptionItemsTempTableImport' => $prescription_items_import,
						'tempTableImport' => $temp_table_import
					);
					$data['script'] = "RestDataAPIProcessAllTempRecords";
					$data['script.param'] = $reference_key;
					$sped_response = $fm->processAllTempRecords($access_token, $data);
				}else{
					/* Clear all records from temp FM tables */
					$data['fieldData'] = array(
						'prescriptionRefKey' => $reference_key,
						'prescriptionIdFromWebPortal' => $precription_temp->id,
						'patientTempTableImport' => $patient_fm_import,
						'prescriptionTempTableImport' => $prescription_fm_import,
						'prescriptionItemsTempTableImport' => $prescription_items_import,
						'tempTableImport' => $temp_table_import
					);
					$data['script'] = "RestDataAPIFlushAllTempRecords";
					$data['script.param'] = $reference_key;
					$res = $fm->clearAllTempRecords($access_token, $data);
					$sped_response = false;
				}
				Session::put('reference_key', $reference_key);
			}
		}
		return $sped_response;
	 }
	 
	/**
     * Get reminder dates
     *
     * @param  Request
     * @return array
     */
	public function getReminderDates(){
		$dosage_multipliers = Request::get('dosage_multipliers');
		$duration = Request::get('duration');
		$quantity = Request::get('quantity');
		$repeats = Request::get('repeats');
		$last_date = date('Y-m-d');
		$response = $this->calculateDrugsReminderDates($dosage_multipliers, $quantity, $repeats, $last_date );
		return Response::json($response);
	}
	/**
     * Save patient to session
     *
     * @param  patient id
     * @return add drugs screen
     */
	public function createPatientPrescription($id){
		try{
			$patient = Patients::find($id);
			if(!$patient){
				throw new \Exception("Patient's information is not found.");
			}
			$prescriber_ids = [];
			if($patient->prescribers){
				foreach($patient->prescribers as $presciber){
					$prescriber_ids[] = $presciber->id;
				}
			}
			if(!in_array(Auth::guard('prescriber')->user()->id, $prescriber_ids)){
				throw new \Exception("You can't create prescription for this patient until you have request consent from this patient.");
			}
			Session::put('add-more-patient-id', $id);
			Session::put('existing-patient', true);
			return Redirect::to('/prescribers/prescription');
		}catch(\Exception $e){
			Session::put('message' , $e->getMessage());
			Session::put('alert-type' , 'alert-warning');
			return back()->withInput();
		}
	}
	/**
     * Resend Prescription to patient via email
     *
     * @param  prescription id
     * @return NULL
     */
	public function resendPrescriptionToPatient($prescription_id){
		try{
			$prescription = Prescriptions::find($prescription_id);
			if(!$prescription){
				throw new \Exception('Your desired prescription has not been found.');
			}
			else if(Auth::user()->role_id == 2 && $prescription->prescriber_id != Auth::guard('prescriber')->user()->id){
				throw new \Exception('You are not authorised to access this prescription.');
			}
			$prescriber_name = Auth::guard('prescriber')->user()->first_name.' '.Auth::guard('prescriber')->user()->last_name;
			$patient = $prescription->patients;
			$result = [
				'patient_name' => $patient->name,
				'prescriber_name' => $prescriber_name,
				'clinic_name' => Auth::guard('prescriber')->user()->name_of_clinic
			];
			$this->emailservice->sendMail('emails.resend-patient-prescription', $result, $patient->email, 'Resending your prescription #'.$prescription->uid, storage_path('prescriptions/prescription-'.$prescription_id.'.pdf'));
			Session::put('message' ,'Prescription has been resent to patient.');
			Session::put('alert-type' , 'alert-success');
			return back()->withInput();
		}catch(\Exception $e){
			Session::put('message' , $e->getMessage());
			Session::put('alert-type' , 'alert-warning');
			return back()->withInput();
		}
	}
	/**
     * Regenerate prescription based on existing one for existin patient
     *
     * @param  prescription id
     * @return NULL
     */
	public function regeneratePrescription($prescription_id){
		try{
			$prescription = Prescriptions::find($prescription_id);
			if(!$prescription){
				throw new \Exception('Your desired prescription has not been found.');
			}
			else if($prescription->prescriber_id != Auth::guard('prescriber')->user()->id){
				throw new \Exception('You are not authorised to access this prescription.');
			}
			$prescribed_drugs = $prescription->drugs;
			foreach($prescribed_drugs as $drug){
				$this->addItemToCart($drug->drugs_id);
			}
			Session::put('add-more-patient-id', $prescription->patient_id);
			Session::put('existing-patient', true);
			return Redirect::to('/prescription/create-rx');
		}catch(\Exception $e){
			Session::put('message' , $e->getMessage());
			Session::put('alert-type' , 'alert-warning');
			return back()->withInput();
		}
	}
	public function getSend(){
		//$this->insertRecordsToFileMaker(25);
		$sms = new SMSService();
		$sender_name = Auth::guard('prescriber')->user()->name_of_clinic;
		$sender_name = preg_replace('/\s+/', ' ', $sender_name);
		$sender_name = strtoupper(substr($sender_name, 0, 10));
		$sms->send('919662080288', 'test', $sender_name);
	}
}
