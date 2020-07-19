<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Prescriptions;
use App\Models\Patients;
use App\Models\PrescriptionsDrugs;
use App\Models\Orders;
use App\Models\OrderItems;
use App\Models\PharmacyCapacity;
use App\Models\Drugs;
use App\Models\PrescribersPatients;
use Auth, Request, Response, Redirect, Session, DB, Cart;
use App\Services\EmailService;
use App\Models\Prescribers;
use App\User;
use Illuminate\Support\Facades\Validator;
use App\Services\SMSService;
use App\Services\FileMakerService;
use App\Models\PrescriptionTemp;
use App\Models\PrescriptionDrugsTemp;
use App\Models\PrescriptionDrugsReminderDates;
use App\Models\FlavourBases;
use App\Models\DeliveryPostcodes;
use App\Models\Settings;
use App\Models\UsersSettings;
use App\Models\PatientsDeliveryAddress;
use App\Models\MGCClinicNames;
use App\Services\PostcodesService;
use App\Models\SkipDates;
use Carbon\CarbonPeriod;
use Carbon\Carbon;
use App\Services\SpecialistPharmacyAPIService;
use App\Models\SpedPrescriptionsTemp;
use Illuminate\Support\Facades\Cache;
use App\Services\SpeedAPIService;

class PatientsController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Patients Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles the Patients Prescription activities after authenticate.
    | Such as listing prescriptions and view prescriptions etc.
    */

    /**
     * Loads a patient's prescriptions view.
     * It loads data from filemaker sped application and displayed
     *
     * @param  NULL
     * @return prescriptions listing
     */
    public function getPatientsPrescriptions()
    {
		try{
			$settings = Settings::where('name', 'show_rx_pdf_check_from_telephony')->first();
			$show_telephony_pdf = true;
			if($settings && $settings->value == 'true'){
				$show_telephony_pdf = false;
			}
            $prescriptions = array();
            $fm = new FileMakerService();
            $access_token = $fm->getFMAccessToken();
            if($access_token) {
                $patient_fm_pk = Auth::user()->patient->patient_fm_pk;
                PrescriptionTemp::where('patient_key', $patient_fm_pk)->orWhere('patient_id', Auth::user()->patient->id)->delete();
				if($patient_fm_pk){
					$request_params['query'] = [['Patient_kf' => $patient_fm_pk]];
					$request_params['sort'] = [['fieldName' => 'Creation_Timestamp', 'sortOrder' => 'descend']];
				}else{
					$request_params['query'] = [['Patient_Email' => '=='.Auth::user()->patient->email]];
					$request_params['sort'] = [['fieldName' => 'Creation_Timestamp', 'sortOrder' => 'descend']];
				}
                $response = $fm->getAllPrescriptionsForPatient($access_token, $request_params);
				$insert_temp_array = [];
				if($response->messages && $response->messages[0]->message == 'OK') {
					foreach($response->response->data as $res){
						$pdf = '';
						if($show_telephony_pdf){
							$pdf = $res->fieldData->Original_Document_Base64;
						}else if($res->fieldData->Creation_Account == 'portal' || $res->fieldData->Creation_Account == 'rest'){
							$pdf = $res->fieldData->Original_Document_Base64;
						}
						$insert_temp_array[] = array(
							'patient_key' => $res->fieldData->Patient_kf,
							'clinic_name' => $res->fieldData->Clinic_Name,
							'prescription_key' => $res->fieldData->Primary_kp,
							'prescription_fm_id' => $res->fieldData->Prescription_ID,
							'prescriber_name' => $res->fieldData->Prescriber,
							'creation_timestamp' => $res->fieldData->Creation_Timestamp,
							'base64_pdf' => $pdf,
							'patient_id' => Auth::user()->patient->id,
							'has_unpaid_prescription' => $res->fieldData->Allow_Order,
                        );
                        $info = array(
							'prescription_id' => $res->fieldData->Prescription_ID,
							'prescriber_name' => $res->fieldData->Prescriber,
							'prescription_key' => $res->fieldData->Primary_kp,
							'generation_date' => date('d-m-Y', strtotime($res->fieldData->Creation_Timestamp)),
							'total_repeats' => $res->fieldData->Total_Repeats,
							'has_unpaid_prescription' => $res->fieldData->Allow_Order,
							'total_repeats_left' => $res->fieldData->Total_Repeats_Left,
							'base64_pdf' => $pdf,
							'clinical_check_status' => $res->fieldData->clinicalCheckStatus,
							'creation_account' => $res->fieldData->Creation_Account,
							'expiry_date' => date('d-m-Y', strtotime('+12 months', strtotime($res->fieldData->Creation_Timestamp)))
                        );
                        $prescriptions[] = $info;
                    }
					PrescriptionTemp::insert($insert_temp_array);
                }
            }else{
                throw new \Exception("Invalid request or can't get access token.");
            }
			activity()->causedBy(Auth::user())->log('List all prescriptions from SPED');
            return view('patients.prescriptions')->with('prescriptions', $prescriptions);
        }catch(\Exception $e){
            Session::put('message', 'Sorry, we seem to have encountered a problem and we are trying to fix it. In meantime feel free to contact us on: +44 (0)207 637 1055 or leave feedback.');
            Session::put('alert-type', 'alert-warning');
            return Redirect::to('/patients/dashboard');
        }

    }
    /**
     * Loads a prescription view.
     *
     * @param  prescription_id
     * @return prescription view
     */
    public function getPrescriptionView($prescription_id)
    {
        try{
           if(substr( $prescription_id, 0, 2 ) === "PT"){
				$prescription_tmp_id = (int) filter_var($prescription_id, FILTER_SANITIZE_NUMBER_INT);
				$prescription = SpedPrescriptionsTemp::find($prescription_tmp_id);
			}else{
				$prescription = Prescriptions::find($prescription_id);
			}
            if(!$prescription) {
                throw new \Exception('The selected prescription has not been found.');
            }
            else if(Auth::guard('prescriber')->check() && $prescription->prescriber_id != Auth::guard('prescriber')->user()->id) {
                throw new \Exception('You are not authorised to access this prescription.');
            }
            else if(!Auth::guard('prescriber')->check() && Auth::user()->role_id == 3 && $prescription->patient_id != Auth::user()->patient->id) {
                throw new \Exception('You are not authorised to access this prescription.');
            }
            //$prescription_id = $prescription->id;
            $path = storage_path('prescriptions/prescription-'.$prescription_id.'.pdf');
            $headers = [ 'Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename=prescription.pdf' ];
            return Response::make(file_get_contents($path), 200, $headers);
        }catch(\Exception $e){
            Session::put('message', $e->getMessage());
            Session::put('alert-type', 'alert-warning');
            return Redirect::to('/404');
        }
    }
    /**
     * Loads a prescription view from filemaker.
     *
     * @param  prescription_id
     * @return prescription view
     */
    public function getPrescriptionFmView($prescription_id)
    {
        try{
            $prescription = PrescriptionTemp::where('prescription_key', $prescription_id)->first();
            if(!$prescription) {
                throw new \Exception('The selected prescription has not been found.');
            }
            else if(Auth::user()->role_id == 3 && $prescription->patient_id != Auth::user()->patient->id) {
                throw new \Exception('You are not authorized to view this prescription.');
            }
            $base64_pdf = $prescription->base64_pdf;
            if($base64_pdf) {
                $path = storage_path('prescriptions/prescription-'.$prescription_id.'.pdf');
                if(!file_exists($path)) {
                    $decoded_base64_pdf = base64_decode($base64_pdf);
                    file_put_contents($path, $decoded_base64_pdf);
                }
                $headers = [ 'Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename=prescription.pdf' ];
				activity()->causedBy(Auth::user())->withProperties(['prescription_id' => $prescription->prescription_fm_id, 'prescription_key' => $prescription_id])->log('View prescription');
                return Response::make(file_get_contents($path), 200, $headers);
            }else{
                throw new \Exception('Prescription file not found.');
            }
        }catch(\Exception $e){
            Session::put('message', $e->getMessage());
            Session::put('alert-type', 'alert-warning');
            return Redirect::to('/patients/prescriptions');
        }
    }
    /**
     * Downloads a prescription from filemaker.
     *
     * @param  prescription_id
     * @return file
     */
    public function getPrescriptionFmDownload($prescription_id)
    {
        try{
            $prescription = PrescriptionTemp::where('prescription_key', $prescription_id)->first();
            if(!$prescription) {
                throw new \Exception('The selected prescription has not been found.');
            }
            else if(Auth::user()->role_id == 3 && $prescription->patient_id != Auth::user()->patient->id) {
                throw new \Exception('You are not authorised to view this prescription.');
            }
            $base64_pdf = $prescription->base64_pdf;
            if($base64_pdf) {
                $path = storage_path('prescriptions/prescription-'.$prescription_id.'.pdf');
                if(!file_exists($path)) {
                    $decoded_base64_pdf = base64_decode($base64_pdf);
                    file_put_contents($path, $decoded_base64_pdf);
                }
                $headers = [ 'Content-Type' => 'application/pdf' ];
                $filename = 'prescription.pdf';
				activity()->causedBy(Auth::user())->withProperties(['prescription_id' => $prescription->prescription_fm_id, 'prescription_key' => $prescription_id])->log('Download prescription');
                return Response::download($path, $filename, $headers);
            }else{
                throw new \Exception('Prescription file not found.');
            }
        }catch(\Exception $e){
            Session::put('message', $e->getMessage());
            Session::put('alert-type', 'alert-warning');
           return Redirect::to('/patients/prescriptions');
        }
    }
    /**
     * Download a prescription.
     *
     * @param  prescription_id
     * @return throw file to browser
     */
    public function getPrescriptionDownload($prescription_id)
    {
        try{
            if(substr( $prescription_id, 0, 2 ) === "PT"){
				$prescription_tmp_id = (int) filter_var($prescription_id, FILTER_SANITIZE_NUMBER_INT);
				$prescription = SpedPrescriptionsTemp::find($prescription_tmp_id);
			}else{
				$prescription = Prescriptions::find($prescription_id);
			}
            if(!$prescription) {
                throw new \Exception('The selected prescription has not been found.');
            }
            else if(Auth::guard('prescriber')->check() && $prescription->prescriber_id != Auth::guard('prescriber')->user()->id) {
                throw new \Exception('You are not authorized to access this prescription.');
            }
            else if(!Auth::guard('prescriber')->check() && Auth::user()->role_id == 3 && $prescription->patient_id != Auth::user()->patient->id) {
                throw new \Exception('You are not authorized to access this prescription.');
            }
            //$prescription_id = $prescription->id;
            $path = storage_path('prescriptions/prescription-'.$prescription_id.'.pdf');
            $headers = [ 'Content-Type' => 'application/pdf' ];
			$creation_date = date('d-m-Y', strtotime($prescription->created_at));
            $filename = "PrP_{$prescription->uid}_{$creation_date}.pdf";
            return Response::download($path, $filename, $headers);
        }catch(\Exception $e){
            Session::put('message', $e->getMessage());
            Session::put('alert-type', 'alert-warning');
            return Redirect::to('/404');
        }
    }
    /**
     * Gets Patient Information.
     *
     * @param  patient email id
     * @return patient info : json format
     */
    public function getPatientInfo()
    {
        try{
            Session::put('existing-patient', false);
            if(Request::has('email')) {
                $email = Request::get('email');
                $patient = Patients::where('email', $email)->first();
                if(!$patient) {
                    return Response::json(['status' => 2]);
                }
                $prescriber_ids = [];
                if($patient->prescribers) {
                    foreach($patient->prescribers as $presciber){
                         $prescriber_ids[] = $presciber->id;
                    }
                }
                if(!in_array(Auth::guard('prescriber')->user()->id, $prescriber_ids)) {
                    throw new \Exception('This patient information already exists, but does not belongs to you.');
                }
                Session::put('existing-patient', true);
                return Response::json(['status' => 1, 'patient' => $patient]);
            }else{
                throw new \Exception('Invalid request');
            }
        }catch(\Exception $e){
            return Response::json(['status' => 0, 'message' => $e->getMessage()]);
        }
    }
	/**
     * Get to know that prescription is repeat prescription or not.
     *
     * @param  prescription id
     * @return array
     */
	public function isPrescriptionRepeatsAndEnableBtn($prescription){
		$prescription_id = $prescription->id;
		$repeats = PrescriptionsDrugs::where('prescriptions_id', $prescription_id)->select('id','no_of_repeats', 'consumed_repeats', 'duration')->get()->toArray();

		$total_repeats = [];
		$used_repeats = [];
		$duration_between_repeats = [];
		$response = array();
		$response['repeats'] = false;
		$response['no-repeats'] = false;
		$prescription_can_be_ordered = false;
		foreach($repeats as $repeat){
			$total_repeats[] = $repeat['no_of_repeats'];
			$used_repeats[] = $repeat['consumed_repeats'];
		}
		$total_repeats = array_sum($total_repeats);
		$used_repeats = array_sum($used_repeats);
		if($total_repeats > $used_repeats){
			$response['repeats'] = true;
		}else if($total_repeats == 0 || $total_repeats == $used_repeats){
			$response['no-repeats'] = true;
		}
		return $response;
	}
    /**
     * Loads order view of prescription
     *
     * @param  @param  prescription id
     * @return load detail order view
     */
    public function getPrescriptionOrder($prescription_id)
    {
        try{
            $prescription_drugs = array();
			$drugs = [];
            $prescription = PrescriptionTemp::where('prescription_key', $prescription_id)->first();
            if(!$prescription) {
                throw new \Exception('The selected prescription has not been found.');
            }
            else if($prescription->patient_id != Auth::user()->patient->id) {
                throw new \Exception('You are not authorised to access this prescription.');
            }
			else if($prescription->has_unpaid_prescription == 'NO') {
                throw new \Exception('<div class="msgBlock">This prescription has been paid for already.</div>');
            }
            $prescription_expired = false;
            $generation_date = date('Y-m-d', strtotime($prescription->creation_timestamp));
            $expiry_date = date('Y-m-d', strtotime('+12 months', strtotime($generation_date)));
            if(date('Y-m-d') > $expiry_date) {
                $prescription_expired = true;
            }
			PrescriptionDrugsTemp::where('prescription_key', $prescription_id)->delete();
            $fm = new FileMakerService();
            $access_token = $fm->getFMAccessToken();
            if($access_token) {
                $request_params['query'] = [[ 'Prescription_kf' => $prescription_id]];
                $request_params['sort'] = [['fieldName' => 'Creation_Timestamp', 'sortOrder' => 'descend']];
                $response = $fm->getAllPrescriptionsLineItemsForPatient($access_token, $request_params);
				$insert_reminder_options = [];
				$delete_reminder_options = [];
				if($response->messages && $response->messages[0]->message == 'OK') {
					foreach($response->response->data as $res){
						//if($res->fieldData->clinicalCheckStatus == 1){
							$item = json_decode(json_encode($res->fieldData), true);
							$drugs[] = array(
								'dose_instruction' => $item['Dose_Instructions'],
								'item_name' => $item['Item_Name'],
								'drugs_key' => $item['Primary_kp'],
								'prescription_id' => $prescription->prescription_fm_id,
								'prescription_key' => $prescription_id,
								'repeats' => $item['Repeats'],
								'repeats_left' => $item['Repeats_Left'],
								'quantity' => $item['Quantity'],
								'next_reminder_date' => $item['NextReminderDate'] ? date('d-m-Y', strtotime($item['NextReminderDate'])) : '',
								'prescription_date' => $item['Creation_Timestamp'],
								'price' => $item['Price_Per_Dose'],
								'last_reminder_date' => $item['LastReminderDate'] ? date('Y-m-d', strtotime($item['NextReminderDate'])): '',
								'clinical_check_status' => $item['clinicalCheckStatus'],
								'dose_description' => $item['Item_Name_Full'],
								'quantity' => 1,
								'formulation_type' => $item['formulaOrMedicine'],
								'prescriber_name' => $prescription->prescriber_name,
								'formulations' => $item['PrescriptionItem__Formula::Formulation']
							);
							$info = array(
								'dose_instruction' => $res->fieldData->Dose_Instructions,
								'item_name' => $res->fieldData->Item_Name,
								'drugs_key' => $res->fieldData->Primary_kp,
								'prescription_id' => $prescription->prescription_fm_id,
								'prescription_key' => $prescription_id,
								'repeats' => $res->fieldData->Repeats,
								'repeats_left' => $res->fieldData->Repeats_Left,
								'quantity' => $res->fieldData->Quantity,
								'next_reminder_date' => $res->fieldData->NextReminderDate ? date('d-m-Y', strtotime($res->fieldData->NextReminderDate)) : '',
								'prescription_date' => $res->fieldData->Creation_Timestamp,
								'price' => $res->fieldData->Price_Per_Dose,
								'last_reminder_date' => $res->fieldData->LastReminderDate ? date('Y-m-d', strtotime($res->fieldData->NextReminderDate)): '',
								'clinical_check_status' => $res->fieldData->clinicalCheckStatus,
								'dose_description' => $res->fieldData->Item_Name_Full,
								'quantity' => 1,
								'formulation_type' => $res->fieldData->formulaOrMedicine,
								'prescriber_name' => $prescription->prescriber_name,
							);
							$prescription_drugs[] = $info;
							if($info['next_reminder_date']){
								$insert_reminder_options[] = array(
									'patient_id' => Auth::user()->patient->id,
									'prescription_item_key' => $info['drugs_key'],
									'date' => date('Y-m-d', strtotime($info['next_reminder_date'])),
									'notified' => false,
									'created_at' => date('Y-m-d H:i:s'),
									'updated_at' => date('Y-m-d H:i:s'),
								);
							}
							if($info['last_reminder_date']){
								$insert_reminder_options[] = array(
									'patient_id' => Auth::user()->patient->id,
									'prescription_item_key' => $info['drugs_key'],
									'date' => date('Y-m-d', strtotime($info['last_reminder_date'])),
									'notified' => false,
									'created_at' => date('Y-m-d H:i:s'),
									'updated_at' => date('Y-m-d H:i:s'),
								);
							}
							$delete_reminder_options[] = $info['drugs_key'];
						}
                   // }
					PrescriptionDrugsReminderDates::whereIn('prescription_item_key', $delete_reminder_options)->where('patient_id',Auth::user()->patient->id)->delete();
					PrescriptionDrugsReminderDates::insert($insert_reminder_options);
					PrescriptionDrugsTemp::insert($prescription_drugs);
                }
			}else{
                throw new \Exception("Invalid request or can't get access token.");
            }
			$flavours = FlavourBases::all();
			activity()->causedBy(Auth::user())->withProperties(['prescription_id' => $prescription->prescription_fm_id, 'prescription_key' => $prescription_id])->log('Go for checkout');
			return view('patients.order')
                ->with('prescription_expired', $prescription_expired)
                ->with('prescription', $prescription)
				->with('flavours', $flavours)
                ->with('prescription_drugs', $drugs);
        }catch(\Exception $e){
            Session::put('message', $e->getMessage());
            Session::put('alert-type', 'alert-warning');
            return Redirect::to('/patients/prescriptions');
        }
    }
    /**
     * Loads delivery dates view
     *
     * @param  @param  NULL
     * @return load delivery dates view
     */
    public function getPrescriptionCheckout()
    {
        try{
			if(Cart::count() <= 0){
				throw new \Exception('There are no items selected to proceed to order.');
			}
			if(!Session::has('patient_delivery_address') ){
				throw new \Exception('Please confirm your delivery address.');
			}else if(Session::has('patient_delivery_address') ){
				$session_address = Session::get('patient_delivery_address');
                $message_stack = [];
				if ($session_address['address']['address_line_1'] == '') {
                    $message_stack[] = "Your street is missing. Please update your address.";
				}
				if ($session_address['address']['town'] == '') {
                    $message_stack[] = "Your town City is missing. Please update your address.";
				}
				if ($session_address['address']['postcode'] == '') {
                    $message_stack[] = "Your postcode is missing. Please update your address.";
				} else {
                    if ($session_address['address']['country'] == "United Kingdom") {
                        $postcodes = new PostcodesService;
                        if (!$postcodes->isValid($session_address['address']['postcode'])) {
                            $message_stack[] = "Invalid UK postcode. Please update your address.";
                        }
                    }
                }
				if ($session_address['address']['country'] == '') {
					$session_address['address']['country'] = Auth::user()->patient->country;
                }
				if ($session_address['address']['country'] == '') {
					$message_stack[] = "Your country is missing. Please update your address.";
				}
                if (!empty($message_stack)) {
					throw new \Exception(implode('<br>', $message_stack));
                }
			}
			activity()->causedBy(Auth::user())->log('Go for select delivery date.');
			try {
				$current_month = Carbon::now()->month;
				$next_month = Carbon::now()->addMonth(1)->month;
				$skip_dates = SkipDates::whereRaw('MONTH(start_date) = ?',[$current_month])->orWhereRaw('MONTH(end_date) = ?',[$next_month])->select('start_date', 'end_date')->orderBy('created_at', 'desc')->get()->toArray();
				$skip_dates_range_array = [] ;
				foreach($skip_dates as $skip_date){
					$skip_dates_range = CarbonPeriod::create($skip_date['start_date'], $skip_date['end_date'])->toArray();
					foreach($skip_dates_range as $r){
						 $skip_dates_range_array[] = $r->format("Y-m-d");
					}
				}
                // Get medication available dates from web service
                $dates_collection = collect();
                $compounded_drugs = [];
                $payload = [];

                // Get required quantity for classification
                $class_req_quantity = [];
                $classified_drugs = [];
                foreach (Cart::content() as $cart_row_id=>$cart_item) {
                    if (isset($cart_item->options->drug['formula_medicine_kf'])) {
                        $drug = Drugs::where('sped_pk', $cart_item->options->drug['formula_medicine_kf'])->first();
                        if (!$drug) {
                            $drug = Drugs::where('name', $cart_item->options->drug['item_name'])->first();
                        }
                        $payload[] = ["drug_fm_pk" => $drug->sped_pk];
                        $drug_formulation_classification_id = $drug->formulation_classification_id ?: 0;
                        if (!isset($class_req_quantity[$drug_formulation_classification_id])) {
                            $class_req_quantity[$drug_formulation_classification_id] = $cart_item->options->quantity;
                        } else {
                            $class_req_quantity[$drug_formulation_classification_id] += $cart_item->options->quantity;
                        }
                        $drug_info['item_name'] = $cart_item->options->drug['item_name'];
                        $drug_info['quantity'] = $cart_item->options->quantity;
                        $classified_drugs[$drug_formulation_classification_id][$cart_row_id] = $drug_info;
                        $compounded_drugs[$cart_row_id] = $drug_info;
                    }
                }

                if ($payload) {
                    $sp_api = new SpecialistPharmacyAPIService;
					if ($sp_api_response = $sp_api->getMedicationAvailableDates($payload)) {
                        // Check if available slots meets the order quantity
                        foreach ($sp_api_response as $class_id=>$class) {
                            foreach ($class['available_slots'] as $available_date=>$available_date_slots) {
                                $current_available_slot = $available_date_slots;
                                foreach ($classified_drugs[$class_id] as $cart_row_id=>$classified_drug) {
                                    $current_available_slot -= $classified_drug['quantity'];
                                    if ($current_available_slot >= 0) {
                                        $compounded_drugs[$cart_row_id]['earliest_compounding_date'] = $available_date;
                                        unset($classified_drugs[$class_id][$cart_row_id]);
                                    }
                                }
                                if ($available_date_slots < $class_req_quantity[$class_id]) {
                                    $sp_api_response[$class_id]['available_slots'][$available_date] = 0;
                                }
                            }
                        }

                        $dates_collection['dates'] = array_keys(current($sp_api_response)['available_slots']);

                        $available_dates = [];
                        foreach ($sp_api_response as $class_id=>$class) {
                            $available_dates[] = array_keys(array_filter($class['available_slots']));
                        }

                        // Combine available dates when intersects
                        if ($available_dates) {
                            if (count($available_dates) > 1) {
                                $dates_collection['possible_delivery_dates'] = array_intersect(...$available_dates);
                            } else {
                                $dates_collection['possible_delivery_dates'] = current($available_dates);
                            }
                        }
						/* Pick Latest(Greatest compounding date) if there is no unique date found */
						$b = [];
						if(count($dates_collection['possible_delivery_dates']) <= 0){
							$array_count = [];
							foreach($available_dates as $available_date){
								$array_count[] = count($available_date);
							}
							$min = min($array_count);
							for($i = 0; $i<$min; $i++){
								$a = [];
								for($j = 0; $j<count($available_dates); $j++){
									$a[] = $available_dates[$j][$i];
								}
								$b[] = max($a);
							}
							$dates_collection['possible_delivery_dates'] = $b;
						}
                        // Construct dates collection for view
                        if ($dates_collection->has('dates') && $dates_collection->has('possible_delivery_dates')) {
                            $disabled_delivery_dates = [];
                            foreach (array_diff($dates_collection['dates'], $dates_collection['possible_delivery_dates']) as $disabled_delivery_date) {
                                $disabled_delivery_dates[] = Carbon::createFromFormat('Y-m-d', $disabled_delivery_date)->format('d-m-Y');
                            }
                            $dates_collection['disabled_delivery_dates'] = $disabled_delivery_dates;

                            $possible_delivery_dates = [];
                            foreach ($dates_collection['possible_delivery_dates'] as $possible_delivery_date){
                                $possible_delivery_dates[] = Carbon::createFromFormat('Y-m-d', $possible_delivery_date)->format('d-m-Y');
                            }
                            $dates_collection['possible_delivery_dates'] = $possible_delivery_dates;
                        }
                    }
				}
                if ($dates_collection->count()) {
        			return view('patients.delivery-dates')
						->with('warning', false)
						->with('skip_dates_range_array', $skip_dates_range_array)
						->with('dates_collection', $dates_collection)
						->with('compounded_drugs', $compounded_drugs);
                }
            } catch (\Exception $e) {
                logger()->error($e->getMessage());
            }
			return view('patients.delivery-dates')
                    ->with('warning', false)
                    ->with('skip_dates_range_array', $skip_dates_range_array)
                    ->with('dates_collection', [])
					->with('compounded_drugs', []);

        }catch(\Exception $e){
            Session::put('message', $e->getMessage());
            Session::put('alert-type', 'alert-warning');
            return Redirect::back();
        }
    }
    /**
     * Loads Pay now page
     *
     * @param  @param  NULL
     * @return load payment page
     */
    public function getPayNow()
    {
        try{
			$compounding_date = Request::get('d-date');
			if (\DateTime::createFromFormat('Y-m-d', $compounding_date) !== false) {

			}else{
				throw new \Exception("Invalid date format.");
			}
			if(Request::has('nextDayDelivery') && Request::get('nextDayDelivery') == 1){
				$delivery_date = date('Y-m-d', strtotime('+1 day', strtotime($compounding_date)));
				Session::put('next_day_delivery_date', $delivery_date);
			}
			Session::put('delivery_date', $compounding_date);
			return Redirect::to('/patients/order/pay');
        }catch(\Exception $e){
            Session::put('message', $e->getMessage());
            Session::put('alert-type', 'alert-warning');
            return Redirect::back();
        }
    }

    /**
     * Check for order made first time or not, as part of repeat prescription for a prescriptions
     *
     * @param  prescription_drugs_id, prescriptions key
     * @return true/false
     */
    public function getFirstDrugsOrderedMade($prescription_drugs_id, $presription_key)
    {
        $first_drug_order = OrderItems::where('prescription_drugs_fm_id', $prescription_drugs_id)->where('prescription_key', $presription_key)->orderBy('created_at', 'ASC')->first(['created_at']);
        return $first_drug_order;
    }
    /**
     * Get All patients
     *
     * @param  Null
     * @return patients info in json format
     */
    public function getAllPatients()
    {
        try{
            $prescriber_id = Auth::guard('prescriber')->user()->id;
            $sort = Request::get('sort') ? Request::get('sort') : 'desc';
            $orderby = Request::get('orderby') ? Request::get('orderby') : 'created_at';

            $query = Patients::select('patients.*')->orderBy($orderby, $sort);
            $prescibers_patietns = PrescribersPatients::where('prescribers_id', $prescriber_id)->select('patients_id')->get();
            $prescibers_belongs_patient_ids = [];
            if($prescibers_patietns) {
                foreach($prescibers_patietns as $patient){
                    $prescibers_belongs_patient_ids[] = $patient->patients_id;
                }
            }
            if(Request::has('s') && Request::get('s')) {
                $s = trim(Request::get('s'));
                $query = $query->where(
                    function ($query) use ($s) {
                        if(Request::has('s-from')) {
                            $s_from = Request::get('s-from');
                            if(in_array('name', $s_from)) {
                                $query->where('name', 'LIKE', '%' .$s. '%');
                            }
                            if(in_array('email', $s_from)) {
                                $query->orWhere('email', 'LIKE', '%' .$s. '%');
                            }
                            if(in_array('dob', $s_from)) {
                                $dob = date('Y-m-d', strtotime($s));
                                $query->orWhere('date_of_birth', $dob);
                            }
                            if(in_array('postcode', $s_from)) {
                                $query->orWhere('postcode', 'LIKE', '%' .$s. '%');
                            }

                        }else{
                                                $is_valid_date = $this->isValidDateTimeString(trim($s));
                            if($is_valid_date) {
                                $date = date('Y-m-d', strtotime($s));
                                $query->where('date_of_birth', $date);
                            }else{
                                $query->where('name', 'LIKE', '%' .$s. '%')
                                    ->orWhere('email', 'LIKE', '%' .$s. '%')
                                    ->orWhere('postcode', 'LIKE', '%' .$s. '%');
                            }
                        }
                    }
                );
            }
            $query_string = [];
            if(Request::has('s')) {
                $query_string['s'] = Request::get('s');
            }
            $query_string = http_build_query($query_string);
            if($query_string) {
                $query_string = '&'.$query_string;
            }
            if(Request::has('s-from')) {
                $r = Request::get('s-from');
                foreach($r as $row){
                    $query_string .= "&s-from[]=".$row;
                }
            }
            $patients = $query->get();
            $patient_data = [];

            if($patients) {
                foreach($patients as $patient){
                    $patient_belongs = false;
                    if(in_array($patient->id, $prescibers_belongs_patient_ids)) {
                        $patient_belongs = true;
                    }
                    $timezone = config('app.timezone');
                    $datetime = new \DateTime($patient->created_at);
                    $la_time = new \DateTimeZone($timezone);
                    $datetime->setTimezone($la_time);
                    $regiser_date = $datetime->format('d-m-Y');
                    $patient_data[] = [
						'id' => $patient->id,
						'name' => $patient->name,
						'email' => $patient->email,
						'regiser_date' => $regiser_date,
						'dob' => date('d-m-Y', strtotime($patient->date_of_birth)),
						'postcode' => $patient->postcode ? $patient->postcode : '',
						'patient_belongs' => $patient_belongs
                    ];
                }
            }
            return Response::json(['status' => true, 'patients' => $patient_data, 'sort' => $sort, 'orderby' => $orderby, 'query_string' =>$query_string]);
        }catch(\Exception $e){
            return Response::json(['status' => false, 'message' => $e->getMessage()]);
        }
    }
    /**
     * Gets Patient Information by patient id.
     *
     * @param  patient id
     * @return patient info : json format
     */
    public function getPatientInfoById($id)
    {
        try{
            Session::put('existing-patient', false);
            $patient = Patients::find($id);
            if(!$patient) {
                throw new \Exception('Invalid patient id.');
            }
            $prescriber_ids = [];
            if($patient->prescribers) {
                foreach($patient->prescribers as $presciber){
                    $prescriber_ids[] = $presciber->id;
                }
            }
            if(!in_array(Auth::guard('prescriber')->user()->id, $prescriber_ids)) {
                throw new \Exception('This patient information already exists, but does not belongs to you.');
            }
            Session::put('existing-patient', true);
            return Response::json(['status' => true, 'patient' => $patient]);
        }catch(\Exception $e){
            return Response::json(['status' => false, 'message' => $e->getMessage()]);
        }
    }
    /**
     * Ask Patient for consent.
     *
     * @param  patient id
     * @return true/false
     */
    public function askForConsentToPatient($id)
    {
        try{
            $patient = Patients::find($id);
            if(!$patient) {
                throw new \Exception('Invalid patient id.');
            }
            $prescriber_ids = [];
            if($patient->prescribers) {
                foreach($patient->prescribers as $presciber){
                    $prescriber_ids[] = $presciber->id;
                }
            }
            if(in_array(Auth::guard('prescriber')->user()->id, $prescriber_ids)) {
                throw new \Exception('This patient has already provided consent to you.');
            }
            $prescriber_name = Auth::guard('prescriber')->user()->first_name.' '.Auth::guard('prescriber')->user()->last_name;
            $clinic_name = Auth::guard('prescriber')->user()->name_of_clinic;
            $email_service = new EmailService();
            $result = [
            'patient_id' => $patient->id,
            'patient_name' => $patient->name,
            'prescriber_name' => $prescriber_name,
            'clinic_name' => $clinic_name,
            'presciber_id' => Auth::guard('prescriber')->user()->id
            ];
            $email_service->sendMail('emails.send-patient-ask-consent', $result, $patient->email, 'Request for your consent to create your prescriptions');
            if(env('ENABLE_SMS')) {
                $sms = new SMSService();
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
                $message = 'The Prescriber '.$prescriber_name.' from '.$clinic_name.' has requested your consent to add you to their patient list so they can create your prescription. Are you happy to proceed?'.url('/patient/approve-consent?approve=1', [$patient->id, Auth::guard('prescriber')->user()->id]).' Yes';
                $sms->send($patient->phone_number, $message);
            }
            return Response::json(['status' => true, 'message' => 'Requested consent from the patient.']);
        }catch(\Exception $e){
            return Response::json(['status' => false, 'message' => $e->getMessage()]);
        }
    }
    /**
     * Approve/Denied Consent.
     *
     * @param  patient id, prescriber id
     * @return true/false
     */
    public function approveConsent($patient_id, $presciber_id)
    {
        try{
            $already_consent = PrescribersPatients::where('patients_id', $patient_id)->where('prescribers_id', $presciber_id)->count();
            if($already_consent) {
                throw new \Exception('Consent already provided.');
            }
            $patient = Patients::find($patient_id);
            if(!$patient) {
                throw new \Exception('Invalid patient id.');
            }
            $prescriber = Prescribers::find($presciber_id);
            if(!$prescriber) {
                throw new \Exception('Invalid prescriber id.');
            }
            if(Auth::user()->patient->id == $patient_id) {
                $status = Request::has('approve') ? Request::get('approve') : 0;
                $email_service = new EmailService();
                if($status) {
                    $prescriber_patient = new PrescribersPatients();
                    $prescriber_patient->patients_id = $patient_id;
                    $prescriber_patient->prescribers_id = $presciber_id;
                    $prescriber_patient->save();
                    /* Approved Consent*/
                    $result = [
                    'patient_name' => $patient->name,
                    'prescriber_name' => $prescriber->first_name.' '.$prescriber->last_name,
                    ];
                    $email_service->sendMail('emails.send-prescriber-approve-consent', $result, $prescriber->email, 'Consent notification');
                    Session::put('message', 'Request Consent has been approved.');
                    Session::put('alert-type', 'alert-success');
                    return Redirect::to('/patients/dashboard');
                }else{
                    /* Denied Consent*/
                    $result = [
                    'patient_name' => $patient->name,
                    'prescriber_name' => $prescriber->first_name.' '.$prescriber->last_name,
                    ];
                    $email_service->sendMail('emails.send-prescriber-denied-consent', $result, $prescriber->email, 'Consent notification');
                    Session::put('message', 'Request Consent has been denied.');
                    Session::put('alert-type', 'alert-success');
                    return Redirect::to('/patients/dashboard');
                }
            }else{
                throw new \Exception('You are not allowed to perform this action.');
            }
        }catch(\Exception $e){
            Session::put('message', $e->getMessage());
            Session::put('alert-type', 'alert-warning');
            return Redirect::to('/patients/dashboard');
        }
    }
    /**
     * Loads Prescriber Profile View
     *
     * @param  NULL
     * @return View
     */
    public function getPatientProfile()
    {
		try{
			if(Session::has('patient_profile_validation') && !Session::get('patient_profile_validation')){
				$this->getPatientProfileFromSpeed();
			}
			return view('patients.profile');
		}catch(\Exception $e){
            Session::put('message', 'We are currently making improvements to The Specialist Pharmacy Patient Portal at this time. We apologise for any inconvenience. Please contact us on +44 (0) 207 637 1055 about your prescription.');
            Session::put('alert-type', 'alert-warning');
            return Redirect::to('/patients/dashboard');
        }
    }

    /**
     * Update prescriber profile
     *
     * @param  NULL
     * @return success
     */
    public function updatePatientProfile()
    {
        $patient_id = Auth::user()->patient->id;
        $all = Request::all();
		$validator = Validator::make(
            $all, [
				//'first_name' => 'required|max:100',
				//'last_name' => 'required|max:100',
				'email' => 'required|max:100|unique:users,email,'.Auth::user()->id,
				'phone_number' => 'required',
				//'date_of_birth' => 'required|date_format:d-m-Y|before_or_equal:'.\Carbon\Carbon::now()->subYears(18)->format('d-m-Y'),
                'postcode' => 'is_uk_postcode'
            ],
            [
				//'first_name.required' => 'Enter your first name.',
				//'last_name.required' => 'Enter your last name.',
				'phone_number.required' => 'Enter your mobile number',
				//'date_of_birth.required' => 'Enter your date of birth',
				//'date_of_birth.before_or_equal' => 'To update your profile your age must be at least 18 years.',
				'email.unique' => 'User already exist with given email',
				'postcode.is_uk_postcode' => 'Invalid UK postcode',
            ]
        );
        if ($validator->fails()) {
			Session::put('patient_profile_validation', true);
            return redirect('/patients/my-profile')->withErrors($validator)->withInput();
        }
        try{
            $patient = Patients::find($patient_id);
			$before = [
				'title' => $patient->title,
				'name' => $patient->name,
				'email' => $patient->email,
				'phone_number' => $patient->phone_number,
				'address_line_1' => $patient->address_line_1,
				'address_line_2' => $patient->address_line_2,
				'town' => $patient->town,
				'county' => $patient->county,
				'country' => $patient->country,
				'postcode' => $patient->postcode,
				'date_of_birth' => $patient->date_of_birth
			];
			//$patient->title = Request::get('title');
			//$patient->name = Request::get('first_name').' '. Request::get('last_name');
            $patient->phone_number = Request::get('phone_number');
            $patient->address_line_1 = Request::get('address_line_1');
            $patient->address_line_2 = Request::get('address_line_2');
            $patient->town = Request::get('town');
			$patient->county = Request::get('county');
            $patient->country = Request::get('country');
            $patient->postcode = Request::get('postcode');
			$patient->email = Request::get('email');
			//$patient->date_of_birth = date('Y-m-d', strtotime(Request::get('date_of_birth')));
            $patient->save();
            $user = User::find($patient->user_id);
            //$user->first_name = Request::get('first_name');
            //$user->last_name = Request::get('last_name');
			$user->email = Request::get('email');
            $user->save();
			$after = [
				'title' => $patient->title,
				'name' => $patient->name,
				'email' => $patient->email,
				'phone_number' => $patient->phone_number,
				'address_line_1' => $patient->address_line_1,
				'address_line_2' => $patient->address_line_2,
				'town' => $patient->town,
				'county' => $patient->county,
				'country' => $patient->country,
				'postcode' => $patient->postcode,
				'date_of_birth' => $patient->date_of_birth
			];
			$sp = new SpeedAPIService();
			$access_token = $sp->getAccessToken();
			if($access_token) {
				$patient_registration_no = $patient->patient_fm_id;
				$updated_data = [
					'title' => $patient->title,
					'first_name' => $user->first_name,
					'last_name' => $user->last_name,
					'email' => $patient->email,
					'phone_number' => $patient->phone_number,
					'address' => $patient->address_line_1.''.$patient->address_line_2,
					'town' => $patient->town,
					'county' => $patient->county,
					'country' => $patient->country,
					'postcode' => $patient->postcode,
					'dob' => $patient->date_of_birth,
					'sms_opt_in' => Request::has('remind_by_sms') ? 1 : 0,
					'email_opt_in' => Request::has('remind_by_email') ? 1 : 0,
				];
				$sp->updatePatientRecord($access_token, $patient_registration_no, $updated_data);
			}
			activity()->causedBy(Auth::user())->withProperties(['before' => $before, 'after' => $after])->log('Update Profile');
			Session::put('patient_profile_validation', false);
            Session::put('message', 'Your profile has been updated successfully.');
            Session::put('alert-type', 'alert-success');
            return Redirect::to('/patients/my-profile');
        }catch(\Exception $e){
			Session::put('patient_profile_validation', true);
            Session::put('message', $e->getMessage());
            Session::put('alert-type', 'alert-warning');
            return Redirect::to('/patients/my-profile');
        }
    }
	/*Update additional cart*/
	public function updateAdditionalCost()
	{
		$additional_cart_cost = [];
		if(Request::has('data')){
			$data = Request::get('data');
			foreach($data as $r){
				if($r == 'STRACKC'){
					$additional_cart_cost[] = [
						'type' => 'STRACKC',
						'name' => 'Standard Tracked Postage - (Aims to deliver in 1-3 working days)',
						'price' => env('STD_TRACK_COMPOUND_COST')
					];
				}
				if($r == 'LP'){
				 	$session_address = Session::get('patient_delivery_address');
				 	$postage = $session_address['postage'];
				 	$additional_cart_cost[] = [
				 		'type' => 'LP',
				 		'name' => 'eCourier (Same Day Delivery)',
				 		'price' => $postage['london_postcode_price']
				 	];
				}
				if($r == 'STRACKV'){
					$additional_cart_cost[] = [
						'type' => 'STRACKV',
						'name' => 'Standard Tracked Postage - (Aims to deliver in 1-3 working days)',
						'price' => env('STD_TRACK_VITAMINS_POMS_COST')
					];
				}
				if($r == 'NB9'){
					$additional_cart_cost[] = [
						'type' => 'NB9',
						'name' => 'Special Next Day Delivery before 9am',
						'price' => env('SPECIAL_NEXT_DAY_BEFORE_NINE_AM_SHIPPING_COST')
					];
				}
				if($r == 'NB1'){
					$additional_cart_cost[] = [
						'type' => 'NB1',
						'name' => 'Special Next Day Delivery before 1pm',
						'price' => env('SPECIAL_NEXT_DAY_BEFORE_ONE_PM_SHIPPING_COST')
					];
				}
				if($r == 'NBS9'){
					$additional_cart_cost[] = [
						'type' => 'NBS9',
						'name' => 'Special Next Day Saturday before 9am',
						'price' => env('SPECIAL_NEXT_DAY_SATURDAY_BEFORE_NINE_AM_SHIPPING_COST')
					];
				}
				if($r == 'NBS1'){
					$additional_cart_cost[] = [
						'type' => 'NBS1',
						'name' => 'Special Next Day Saturday before 1pm',
						'price' => env('SPECIAL_NEXT_DAY_SATURDAY_BEFORE_ONE_PM_SHIPPING_COST')
					];
				}
				if($r == 'RTP'){
					$additional_cart_cost[] = [
						'type' => 'RTP',
						'name' => 'Royal Mail International Signed and Tracked (7-10 days)',
						'price' => env('ROYALMAIL_TRACKED_POSTAGE')
					];
				}
				if($r == 'RTPI'){
					$additional_cart_cost[] = [
						'type' => 'RTPI',
						'name' => 'Royal Mail International Tracked Postage to Ireland (3-5 days)',
						'price' => env('ROYALMAIL_TRACKED_POSTAGE')
					];
				}
				if($r == 'IPSNT'){
					$additional_cart_cost[] = [
						'type' => 'IPSNT',
						'name' => 'Ireland Postage - Standard untracked',
						'price' => env('IR_POSTAGE_STD_NOT_TRACKED')
					];
				}
				if($r == 'ISC'){
					$additional_cart_cost[] = [
						'type' => 'ISC',
						'name' => 'International Courier (3-5 days)',
						'price' => env('INTERNATIONAL_SHIPPING_COST')
					];
				}
			}
		}
		Session::put('additional_cart_cost', $additional_cart_cost);
		$cart_total = $this->getCartTotal(Cart::content());
		if(Session::has('patient_discount_rate') && Session::get('patient_discount_rate') != 0){
			$discount_rate = Session::get('patient_discount_rate');
			$cart_total = floatval(preg_replace('/[^\d.]/', '', $cart_total));
			$basic_payment = $cart_total;
			$additional_cart_cost = 0;
			$additional_cost = Session::get('additional_cart_cost');
			foreach($additional_cost as $r){
				$additional_cart_cost += (float)$r['price'];
			}
			$basic_payment = $cart_total - $additional_cart_cost;
			$discount = ($basic_payment * $discount_rate)/100;
			$discount = round($discount,2);
			$total = $basic_payment - $discount;
			$total_no_add = $total;
			$total = $total + $additional_cart_cost;
			$total = number_format($total,2);
			$cart_total = $total;
		}
		return Response::json(['status' => true, 'total_costs' => $cart_total, 'additional_cart_cost' => $additional_cart_cost]);
	}

	/**
     * Get all Patient Drugs to be order
     *
     * @param  NULL
     * @return view
     */
	public function getAllMyDrugs()
	{
		return Redirect::to('/patients/prescriptions');
		try{
            $drugs = array();
			$total_approved_drugs = 0;
			$to = date('m/d/Y', strtotime('now'));
			$from = date('m/d/Y', strtotime('-1 month', strtotime('now')));
			$fm = new FileMakerService();
			$access_token = $fm->getFMAccessToken();
			if($access_token) {
				$patient_fm_pk = Auth::user()->patient->patient_fm_pk;
				if($patient_fm_pk){
					$request_params['query'] = [['Creation_Timestamp' => $from.'...'.$to, 'Patient_Kp' => '=='.$patient_fm_pk, 'Repeats_Left' => '>0' ]];
				}else{
					$request_params['query'] = [['Creation_Timestamp' => $from.'...'.$to, 'Patient_Email_Address' => '=='.Auth::user()->patient->email , 'Repeats_Left' => '>0']];
				}
				$request_params['sort'] = [[
					'fieldName' => 'Creation_Timestamp',
					'sortOrder' => 'descend'
				]];
				$response = $fm->getAllPrescriptionsDrugsForPatient($access_token, $request_params);
				$prescription_drugs = [];
				if($response->messages && $response->messages[0]->message == 'OK') {
					foreach($response->response->data as $res){
						$item = json_decode(json_encode($res->fieldData), true);
						$drugs[] = $item;
						if($item['clinicalCheckStatus'] == 1 && $item['Prescription::clinicalCheckStatus'] == 1){
							$total_approved_drugs += 1;
							$info = array(
								'dose_instruction' => $item['Dose_Instructions'],
								'item_name' => $item['Item_Name'],
								'drugs_key' => $item['Primary_kp'],
								'prescription_id' => $item['Prescription::Prescription_ID'],
								'prescription_key' => $item['Prescription_kf'],
								'repeats' => $item['Repeats'],
								'repeats_left' => $item['Repeats_Left'],
								'quantity' => $item['Quantity'],
								'next_reminder_date' => $item['NextReminderDate'] ? date('d-m-Y', strtotime($item['NextReminderDate'])) : '',
								'prescription_date' => $item['Creation_Timestamp'],
								'price' => $item['Price_Per_Dose'],
								'last_reminder_date' => $item['LastReminderDate'] ? date('Y-m-d', strtotime($item['LastReminderDate'])): '',
								'clinical_check_status' => $item['clinicalCheckStatus'],
								'dose_description' => $item['Item_Name_Full'],
								'quantity' => 1,
								'formulation_type' => $item['formulaOrMedicine'],
								'prescriber_name' => $item['Prescription::Prescriber'],
							);
							$prescription_drugs[] = $info;
						}
					}
					PrescriptionDrugsTemp::insert($prescription_drugs);
				}
			}else{
                throw new \Exception("Invalid request or can't get access token.");
            }
			$flavours = FlavourBases::all();
			activity()->causedBy(Auth::user())->log('List all patient medications from SPED');
			return view('patients.all-my-drugs')->with('drugs', $drugs)->with('total_approved_drugs', $total_approved_drugs)->with('flavours',$flavours);
		}catch(\Exception $e){
            Session::put('message', 'We are currently making improvements to The Specialist Pharmacy Patient Portal at this time. We apologise for any inconvenience. Please contact us on +44 (0) 207 637 1055 about your prescription.');
            Session::put('alert-type', 'alert-warning');
            return Redirect::to('/patients/dashboard');
        }
	}

	/**
     * Loads delivery address view
     *
     * @param  @param  NULL
     * @return load delivery address view
     */
	public function getPrescriptionDeliveryAddress()
	{
		try{
			if(Cart::count() > 0){
				activity()->causedBy(Auth::user())->log('Go for select delivery address.');
				$existing_used_address = Orders::where('user_id', Auth::user()->id)->orderBy('id', 'desc')->first();
				$this->getPatientProfileFromSpeed();
				return view('patients.delivery-address')->with('existing_used_address',$existing_used_address);
			}else{
				throw new \Exception('There are no items selected to proceed to order.');
			}
        }catch(\Exception $e){
            Session::put('message', $e->getMessage());
            Session::put('alert-type', 'alert-warning');
            return Redirect::back();
        }
	}
	/**
     * save delivery address to db and session
     *
     * @param  @param  NULL
     * @return redirect to delivery dates scressn
     */
	public function savePrescriptionDeliveryAddress()
	{
		$all = Request::all();
        $validator = Validator::make(
            $all, [
				'postCode' => 'required|is_uk_postcode',
				'addressFirst' => 'required',
				'town' => 'required',
				'country' => 'required',
				'name' => 'required|regex:/^[\pL\s\-]+$/u',
            ],
			[
				'name.required' => 'Please enter your first name',
				'addressFirst.required' => 'Please enter the first line of your address',
				'postCode.required' => 'Please enter a postcode.',
                'postCode.is_uk_postcode' => 'Invalid UK postcode',
            ]
        );
        if ($validator->fails()) {
            return redirect('/patients/delivery-address')->withErrors($validator)->withInput();
        }
		try{
			$d_address = new PatientsDeliveryAddress();
			// $name = Auth::user()->patient->name;
            $name = Request::get('name');
			$parts = explode(" ", $name);
			$last_name = array_pop($parts);
			$first_name = implode(" ", $parts);
			$d_address->patient_id = Auth::user()->patient->id;
			$d_address->first_name = $first_name;
			$d_address->last_name = $last_name;
			$d_address->email = Auth::user()->patient->email;
			// $d_address->phone_number = Auth::user()->patient->phone_number;
            $d_address->phone_number = Request::get('mobileNumber');
			$d_address->address_line_1 = Request::get('addressFirst');
			$d_address->address_line_2 = Request::get('addressLast');
			$d_address->town = Request::get('town');
			$d_address->county = Request::get('county');
			$d_address->country = Request::get('country');
			$d_address->postcode = Request::get('postCode');
			$d_address->save();
			$is_alternate_address = false;

            // Check for selected London postcode
            $postcode = str_replace(' ','', Request::get('postCode'));
            $country = Request::get('country');
            if(strlen($postcode) > 4){
                $postcode = substr($postcode, 0, -3);
            }
			$delivery_postcodes = DeliveryPostcodes::where('postcode','=',$postcode)->first();

			$london_postcode = false;
			$uk_not_london_postcode = false;
			$ireland_postcode = false;
			$international_postcode = false;
			$london_postcode_price = 0;
			if($delivery_postcodes && $country == 'United Kingdom'){
				$london_postcode = true;
				$london_postcode_price = $delivery_postcodes->price;
			}
			else if($country == 'United Kingdom'){
				$uk_not_london_postcode = true;
			}
			else if($country == 'Ireland'){
				$ireland_postcode = true;
			}else{
				$international_postcode = true;
			}
			$delivery_address = [
				'address' => [
					'address_id' => $d_address->id,
					'first_name' => $d_address->first_name,
					'last_name' => $d_address->last_name,
					'email' => $d_address->email,
					'phone_number' => $d_address->phone_number,
					'address_line_1' => Request::get('addressFirst'),
					'address_line_2' => Request::get('addressLast'),
					'town' => Request::get('town'),
					'county' => Request::get('county'),
					'country' => Request::get('country'),
					'postcode' => Request::get('postCode'),
				],
				'postage' => [
					'london_postcode' => $london_postcode,
					'uk_not_london_postcode' => $uk_not_london_postcode,
					'ireland_postcode' => $ireland_postcode,
					'international_postcode' => $international_postcode,
					'london_postcode_price' => round($london_postcode_price)
				],
				'is_alternate_address' => $is_alternate_address
			];
			Session::put('patient_delivery_address', $delivery_address);
			if(!Session::has('additional_cart_cost')){
				Session::put('additional_cart_cost', []);
			}
			return Redirect::to('/patients/prescription/checkout');
		}catch(\Exception $e){
			Session::put('message', $e->getMessage());
            Session::put('alert-type', 'alert-warning');
            return Redirect::to('/patients/delivery-address');
        }
	}
	/**
     * update billing address on sped and on profile
     *
     * @param  @param  NULL
     * @return redirect to delivery dates scressn
     */
	public function updateBillingAddress(){
		$patient_id = Auth::user()->patient->id;
		$all = Request::all();
        $validator = Validator::make(
            $all, [
				'postCode' => 'required|is_uk_postcode',
				'addressFirst' => 'required',
				'town' => 'required',
				'country' => 'required',
				'name' => 'required|regex:/^[\pL\s\-]+$/u',
            ],
			[
				'name.required' => 'Please enter your first name',
				'addressFirst.required' => 'Please enter the first line of your address',
				'postCode.required' => 'Please enter a postcode.',
                'postCode.is_uk_postcode' => 'Invalid UK postcode',
            ]
        );
        if ($validator->fails()) {
            return Response::json(['status' => false, 'messages' => $validator->errors()]);
		}
		try{
			$patient = Patients::find($patient_id);
			$before = [
				'address_line_1' => $patient->address_line_1,
				'address_line_2' => $patient->address_line_2,
				'town' => $patient->town,
				'county' => $patient->county,
				'country' => $patient->country,
				'postcode' => $patient->postcode
			];
			$patient->address_line_1 = Request::get('addressFirst');
            $patient->address_line_2 = Request::get('addressLast');
            $patient->town = Request::get('town');
			$patient->county = Request::get('county');
            $patient->country = Request::get('country');
            $patient->postcode = Request::get('postCode');
			$patient->save();
            $after = [
				'address_line_1' => $patient->address_line_1,
				'address_line_2' => $patient->address_line_2,
				'town' => $patient->town,
				'county' => $patient->county,
				'country' => $patient->country,
				'postcode' => $patient->postcode
			];
			$sp = new SpeedAPIService();
			$access_token = $sp->getFMAccessToken();
			if($access_token) {
				$patient_registration_no = $patient->patient_fm_id;
				$settings = [];
				foreach(Auth::user()->settings as $setting){
					$settings[$setting->name] = $setting->value;
				}
				$updated_data = [
					'title' => $patient->title,
					'first_name' => $user->first_name,
					'last_name' => $user->last_name,
					'email' => $patient->email,
					'phone_number' => $patient->phone_number,
					'address' => $patient->address_line_1.','.$patient->address_line_2,
					'town' => $patient->town,
					'county' => $patient->county,
					'country' => $patient->country,
					'postcode' => $patient->postcode,
					'dob' => $patient->date_of_birth,
					'sms_opt_in' => array_key_exists('remind_by_sms', $settings) && $settings['remind_by_sms'] == 'true' ? 1 : 0,
					'email_opt_in' => array_key_exists('remind_by_email', $settings) && $settings['remind_by_email'] == 'true' ? 1 : 0,
				];
				$sp->updatePatientRecord($access_token, $patient_registration_no, $updated_data);
				
			}
			activity()->causedBy(Auth::user())->withProperties(['before' => $before, 'after' => $after])->log('Update Billing Address');
			$is_alternate_address = false;
			return Response::json(['status' => true, 'messages' => 'Your delivery address has been updated successfully.']);
		}catch(\Exception $e){
			return Response::json(['status' => false, 'messages' => $e->getMessage()]);
        }
	}
	/**
     * update delivery address
     *
     * @param  @param  NULL
     * @return redirect to delivery dates scressn
     */
	public function updateDeliveryAddress(){
		$all = Request::all();
        $validator = Validator::make(
            $all, [
				'postCode' => 'required',
				'addressFirst' => 'required',
				'town' => 'required',
				'country' => 'required',
				'name' => 'required|regex:/^[\pL\s\-]+$/u',

            ],
			[
				'name.required' => 'Please enter your first name',
				'addressFirst.required' => 'Please enter the first line of your address',
				'postCode.required' => 'Please enter a postcode.',
            ]
        );
        if ($validator->fails()) {
            return Response::json(['status' => false, 'messages' => $validator->errors()]);
		}
		try{
			$id = Request::get('id');

			$d_address = PatientsDeliveryAddress::find($id);
			if(!$d_address){
				throw new \Exception('Invalid delivery address id');
			}
            $name = Request::get('name');
            $parts = explode(" ", $name);
            $last_name = array_pop($parts);
            $first_name = implode(" ", $parts);
            $d_address->first_name = $first_name;
            $d_address->last_name = $last_name;
            $d_address->phone_number = Request::get('mobileNumber');
			$d_address->address_line_1 = Request::get('addressFirst');
			$d_address->address_line_2 = Request::get('addressLast');
			$d_address->town = Request::get('town');
			$d_address->county = Request::get('county');
			$d_address->country = Request::get('country');
			$d_address->postcode = Request::get('postCode');
			$d_address->save();
			$is_alternate_address = false;

            // Check for selected London postcode
            $postcode = str_replace(' ','', Request::get('postCode'));
            $country = Request::get('country');
            if(strlen($postcode) > 4){
                $postcode = substr($postcode, 0, -3);
            }
			$delivery_postcodes = DeliveryPostcodes::where('postcode','=',$postcode)->first();

			$london_postcode = false;
			$uk_not_london_postcode = false;
			$ireland_postcode = false;
			$international_postcode = false;
			$london_postcode_price = 0;
			if($delivery_postcodes && $country == 'United Kingdom'){
				$london_postcode = true;
				$london_postcode_price = $delivery_postcodes->price;
			}
			else if($country == 'United Kingdom'){
				$uk_not_london_postcode = true;
			}
			else if($country == 'Ireland'){
				$ireland_postcode = true;
			}else{
				$international_postcode = true;
			}
			$delivery_address = [
				'address' => [
					'address_id' => $d_address->id,
					'first_name' => $d_address->first_name,
					'last_name' => $d_address->last_name,
					'email' => $d_address->email,
					'phone_number' => $d_address->phone_number,
					'address_line_1' => Request::get('addressFirst'),
					'address_line_2' => Request::get('addressLast'),
					'town' => Request::get('town'),
					'county' => Request::get('county'),
					'country' => Request::get('country'),
					'postcode' => Request::get('postCode'),
				],
				'postage' => [
					'london_postcode' => $london_postcode,
					'uk_not_london_postcode' => $uk_not_london_postcode,
					'ireland_postcode' => $ireland_postcode,
					'international_postcode' => $international_postcode,
					'london_postcode_price' => round($london_postcode_price)
				],
				'is_alternate_address' => $is_alternate_address
			];
			Session::put('patient_delivery_address', $delivery_address);
			Session::put('additional_cart_cost', []);
			return Response::json(['status' => true, 'message' => 'Your delivery address has been updated successfully.']);
		}catch(\Exception $e){
			return Response::json(['status' => false, 'message' => $e->getMessage()]);
        }

	}

	/**
     * change delivery address
     *
     * @param  @param  delivery address id
     * @return redirect to compounding screen
     */
	public function changeDeliveryAddress($id){
		try{
			if($id == 0){
				if(Session::has('patient_delivery_address') ){
					Session::forget('patient_delivery_address');
				}
				$is_alternate_address = false;

                // Check for selected London postcode
				$postcode = str_replace(' ','', Auth::user()->patient->postcode);
				$country = Auth::user()->patient->country ?: 'United Kingdom';
                if(strlen($postcode) > 4){
                    $postcode = substr($postcode, 0, -3);
                }
                $delivery_postcodes = DeliveryPostcodes::where('postcode','=',$postcode)->first();

				$london_postcode = false;
				$uk_not_london_postcode = false;
				$ireland_postcode = false;
				$international_postcode = false;
				$london_postcode_price = 0;
                if($delivery_postcodes && $country == 'United Kingdom'){
                    $london_postcode = true;
                    $london_postcode_price = $delivery_postcodes->price;
                }
				else if($country == 'United Kingdom'){
					$uk_not_london_postcode = true;
				}
				else if($country == 'Ireland'){
					$ireland_postcode = true;
				}else{
					$international_postcode = true;
				}
				$message_stack = [];
				if ( Auth::user()->patient->address_line_1 == '') {
					$message_stack[] = "Street is missing. Please update your address.";
				}
				if (Auth::user()->patient->town == '') {
					$message_stack[] = "Town/City is missing. Please update your address.";
				}
				if (Auth::user()->patient->country == '') {
					$message_stack[] = "Country is missing. Please update your address.";
				}
				if (Auth::user()->patient->postcode == '') {
					$message_stack[] = "Postcode is missing. Please update your address.";
				} else {
					if (Auth::user()->patient->country == "United Kingdom") {
						$postcodes = new PostcodesService;
						if (!$postcodes->isValid(Auth::user()->patient->postcode)) {
							$message_stack[] = "Invalid UK postcode. Please update your address.";
						}
					}
				}
				if (!empty($message_stack)) {
					throw new \Exception(implode('<br>', $message_stack));
				}
				$delivery_address = [
					'address' => [
						'address_id' => 0,
						'first_name' => Auth::user()->first_name,
						'last_name' => Auth::user()->last_name,
						'email' => Auth::user()->patient->email,
						'phone_number' => Auth::user()->patient->phone_number,
						'address_line_1' => Auth::user()->patient->address_line_1,
						'address_line_2' => Auth::user()->patient->address_line_2,
						'town' => Auth::user()->patient->town,
						'county' => Auth::user()->patient->county,
						'country' => Auth::user()->patient->country,
						'postcode' => Auth::user()->patient->postcode,
					],
					'postage' => [
						'london_postcode' => $london_postcode,
						'uk_not_london_postcode' => $uk_not_london_postcode,
						'ireland_postcode' => $ireland_postcode,
						'international_postcode' => $international_postcode,
						'london_postcode_price' => round($london_postcode_price)
					],
					'is_alternate_address' => $is_alternate_address
				];
			}else{
				$d_address = PatientsDeliveryAddress::find($id);
				if(!$d_address){
					throw new \Exception('Invalid delivery address id');
				}
				if(Session::has('patient_delivery_address') ){
					Session::forget('patient_delivery_address');
				}
				$is_alternate_address = false;

                // Check for selected London postcode
				$postcode = str_replace(' ','', $d_address->postcode);
				$country = $d_address->country;
                if(strlen($postcode) > 4){
                    $postcode = substr($postcode, 0, -3);
                }
                $delivery_postcodes = DeliveryPostcodes::where('postcode','=',$postcode)->first();

				$london_postcode = false;
				$uk_not_london_postcode = false;
				$ireland_postcode = false;
				$international_postcode = false;
				$london_postcode_price = 0;
                if($delivery_postcodes && $country == 'United Kingdom'){
                    $london_postcode = true;
                    $london_postcode_price = $delivery_postcodes->price;
                }
				else if($country == 'United Kingdom'){
					$uk_not_london_postcode = true;
				}
				else if($country == 'Ireland'){
					$ireland_postcode = true;
				}else{
					$international_postcode = true;
				}
				$message_stack = [];
				$name = $d_address->first_name.' '.$d_address->last_name;
				if($name == ''){
					$message_stack[] = "Name is missing. Please update your name.";
				}
				if (!preg_match('/^[\pL\s\-]+$/u', $name))
				{
					$message_stack[] = "Name have invalid characters. Please update your name.";
				}
				if ($d_address->address_line_1 == '') {
					$message_stack[] = "Street is missing. Please update your address.";
				}
				if ($d_address->town == '') {
					$message_stack[] = "Town/City is missing. Please update your address.";
				}
				if ($d_address->country == '') {
					$message_stack[] = "Country is missing. Please update your address.";
				}
				if ($d_address->postcode == '') {
					$message_stack[] = "Postcode is missing. Please update your address.";
				} else {
					if ($d_address->country == "United Kingdom") {
						$postcodes = new PostcodesService;
						if (!$postcodes->isValid($d_address->postcode)) {
							$message_stack[] = "Invalid UK postcode. Please update your address.";
						}
					}
				}
				if (!empty($message_stack)) {
					throw new \Exception(implode('<br>', $message_stack));
				}

				$delivery_address = [
					'address' => [
						'address_id' => $d_address->id,
						'first_name' => $d_address->first_name,
						'last_name' => $d_address->last_name,
						'email' => $d_address->email,
						'phone_number' => $d_address->phone_number,
						'address_line_1' => $d_address->address_line_1,
						'address_line_2' => $d_address->address_line_2,
						'town' => $d_address->town,
						'county' => $d_address->county,
						'country' => $d_address->country,
						'postcode' => $d_address->postcode,
					],
					'postage' => [
						'london_postcode' => $london_postcode,
						'uk_not_london_postcode' => $uk_not_london_postcode,
						'ireland_postcode' => $ireland_postcode,
						'international_postcode' => $international_postcode,
						'london_postcode_price' => round($london_postcode_price)
					],
					'is_alternate_address' => $is_alternate_address
				];
			}
            // $d_address->update();
			Session::put('patient_delivery_address', $delivery_address);
			Session::put('additional_cart_cost', []);
			return Redirect::to('/patients/prescription/checkout');
		}catch(\Exception $e){
            // $d_address->update();
			Session::put('message', $e->getMessage());
            Session::put('alert-type', 'alert-warning');
            return Redirect::to('/patients/delivery-address');
		}
	}
	/**
     * delete delivery address
     *
     * @param  @param  delivery address id
     * @return redirect to delivery address screen
     */
	public function deleteDeliveryAddress($id){
		try{
			$d_address = PatientsDeliveryAddress::find($id);
			if(!$d_address){
				throw new \Exception('Invalid delivery address id');
			}
			$session_address = [];
			if(Session::has('patient_delivery_address') ){
				$session_address = Session::get('patient_delivery_address');
			}
			if(count($session_address) && $session_address['address']['address_id'] == $id ){
				Session::forget('patient_delivery_address');
			}
			$d_address->delete();
			return Redirect::to('/patients/delivery-address');
		}catch(\Exception $e){
			Session::put('message', $e->getMessage());
            Session::put('alert-type', 'alert-warning');
            return Redirect::to('/patients/delivery-address');
		}
	}
	/**
     * save delivery address to session Backup
     *
     * @param  @param  NULL
     * @return redirect to delivery dates scressn
     */
	public function savePrescriptionDeliveryAddressBackup()
	{
		$all = Request::all();
        $validator = Validator::make(
            $all, [
				'firstName' => 'required|max:100',
				'lastName' => 'required|max:100',
				'postCode' => 'required',
				'email' => 'email',
				'addressFirst' => 'required',
				'country' => 'required',
            ],
			[
				'addressFirst.required' => 'Please enter the first line of your address',
				'postCode.required' => 'Please enter a postcode.',
            ]
        );
        if ($validator->fails()) {
            return redirect('/patients/delivery-address')->withErrors($validator)->withInput();
        }
		try{
			$is_alternate_address = false;

            // Check for selected London postcode
			if(Request::has('isAlternate') && Request::get('isAlternate') == 'on'){
				$postcode = str_replace(' ','', Request::get('alter_postCode'));
				$is_alternate_address = true;
				$country = Request::get('alter_country');
			}else{
				$postcode = str_replace(' ','', Request::get('postCode'));
				$country = Request::get('country');
			}
            if(strlen($postcode) > 4){
                $postcode = substr($postcode, 0, -3);
            }
			$delivery_postcodes = DeliveryPostcodes::where('postcode','=',$postcode)->first();

            $london_postcode = false;
			$uk_not_london_postcode = false;
			$ireland_postcode = false;
			$international_postcode = false;
			$london_postcode_price = 0;
			if($delivery_postcodes && $country == 'United Kingdom'){
				$london_postcode = true;
				$london_postcode_price = $delivery_postcodes->price;
			}
			else if($country == 'United Kingdom'){
				$uk_not_london_postcode = true;
			}
			else if($country == 'Ireland'){
				$ireland_postcode = true;
			}else{
				$international_postcode = true;
			}
			$delivery_address = [
				'address' => [
					'first_name' => Request::get('firstName'),
					'last_name' => Request::get('lastName'),
					'email' => Request::get('email'),
					'phone_number' => Request::get('phoneNumber'),
					'address_line_1' => Request::get('addressFirst'),
					'address_line_2' => Request::get('addressLast'),
					'town' => Request::get('town'),
					'county' => Request::get('county'),
					'country' => Request::get('country'),
					'postcode' => Request::get('postCode'),
				],
				'alter_address' => [
					'email' => Request::get('alter_email'),
					'address_line_1' => Request::get('alter_addressFirst'),
					'address_line_2' => Request::get('alter_addressLast'),
					'town' => Request::get('alter_town'),
					'county' => Request::get('alter_county'),
					'country' => Request::get('alter_country'),
					'postcode' => Request::get('alter_postCode'),
					'alter_phone_number' => Request::get('alter_phone_number'),
				],
				'postage' => [
					'london_postcode' => $london_postcode,
					'uk_not_london_postcode' => $uk_not_london_postcode,
					'ireland_postcode' => $ireland_postcode,
					'international_postcode' => $international_postcode,
					'london_postcode_price' => round($london_postcode_price)
				],
				'is_alternate_address' => $is_alternate_address
			];
			Session::put('patient_delivery_address', $delivery_address);
			if(!Session::has('additional_cart_cost')){
				Session::put('additional_cart_cost', []);
			}
			return Redirect::to('/patients/prescription/checkout');
		}catch(\Exception $e){
			Session::put('message', $e->getMessage());
            Session::put('alert-type', 'alert-warning');
            return Redirect::to('/patients/delivery-address');
        }
	}
	/**
     * Get all prescriptions
     *
     * @param  @param  NULL
     * @return view
     */
	public function getPatientsMedications(){
		// TEMPORARY: Prescriber Names to test for MGC Clinic Pricing modification
        $mgc_prescribers = [
                'laila kaikavoosi',
                'monica lascar',
                'marion gluck',
                'valeria acampora',
                'michèle badenoch',
                'michele badenoch',
                'fatima khan',
                'elaine mcquade',
				'amelie seghers',
				'priya shah',
				'shabana chaudhari',
				'ruslana popelyuk'
            ];

       try{
			$drugs = [];
			$sort = Request::get('sort') ? Request::get('sort') : 'descend';
            $orderby = Request::get('orderby') ? Request::get('orderby') : 'Prescription::Date_Prescription';
			$total_found_records = 0;
			$cache_expires_at = \Carbon\Carbon::now()->addMinutes(2);
			
			if(Request::has('limit') && Request::get('limit') == -1){
				$limit = 10000;
			}else{
				$limit = 10;
			}
			$query_string = [];
			$f = '2';
			if(Request::has('f')) {
				$query_string['f'] = Request::get('f');
				$f = Request::get('f');
			}
			$query_string = http_build_query($query_string);
			$current_page = 1;
			$offset = 0;
			if(Request::has('page')){
				$page = Request::get('page');
				$current_page = $page;
				$offset = (($page - 1) * $limit)+1;
			}
			$patient_user_id = Auth::user()->id;
			if(Cache::has($patient_user_id.'_patient_rx_item_'.$f.'_'.$current_page)){
				$drugs = Cache::get($patient_user_id.'_patient_rx_item_'.$f.'_'.$current_page);
				$total_found_records = Cache::get($patient_user_id.'_patient_items_found_records_'.$f);
			}else{
				$sp = new SpeedAPIService();
				$access_token = $sp->getAccessToken();
				if($access_token) {
					$patient_fm_id = 'Px00000726'; //Auth::user()->patient->patient_fm_id; //'Px00000685'; 
					if(Request::has('f') && Request::get('f') == 1){
						$request_params['registration_no'] = $patient_fm_id;
						$request_params['is_expired'] = false;
						$request_params['requests_left'] = ">0";
						
					}else if(Request::has('f') && Request::get('f') == 0){
						$request_params['registration_no'] = $patient_fm_id;
						$request_params['is_expired'] = true;
						$request_params['requests_left'] = "==0";
					}else{
						$request_params['registration_no'] = $patient_fm_id;
						$request_params['is_expired'] = '';
						$request_params['requests_left'] = '';
					}
					$page = 1;
					$request_params['skip'] = $offset;
					$request_params['take'] = $limit;
					$request_params['sort'] = '[{"field" : "Quotation.StageStartDate", "dir": "desc"}]';
					$request_params['page'] = $page;
					$response = $sp->getPatientMedicationsDetails($access_token, $request_params);
					
					$insert_reminder_options = [];
					$delete_reminder_options = [];
					$prescription_drugs = [];
					echo "<pre hidden>";
					print_r($response);
					echo "</pre>";
					if($response['status']) {
						$total_found_records = $response['data']['total_number_of_records'];
						Cache::put($patient_user_id.'_patient_items_found_records_'.$f, $total_found_records, $cache_expires_at);
						$removed_rx_keys = [];
						foreach($response['data']['productLines'] as $res){
							$is_mgc_clinic = (in_array(strtolower($res['prescriber_name']), $mgc_prescribers) || MGCClinicNames::where('clinic_name', '=', $res['prescriber_clinic_name'])->count() ? TRUE : FALSE);
							
							$item_name = $res['Item_Name_Full'] ? $res['Item_Name_Full'] : $res['item_name'];
							// Check for MGC Clinic price modification
							if ($is_mgc_clinic && $res['PrescriptionItem_Formulation'] == 'Capsules') {
								if ($res['PrescriptionItem_Dose_Amount'] == 100 && $res['Price_Per_Dose'] == 70 && strpos($item_name, 'DHEA') !== false && strpos($item_name, 'Keto') === false) {
									$res['Price_Per_Dose'] = 58;
								} elseif ($res['PrescriptionItem_Dose_Amount'] == 60 && $res['Price_Per_Dose'] == 50 && strpos($item_name, 'DHEA') !== false && strpos($item_name, 'Keto') === false) {
									$res['Price_Per_Dose'] = 35;
								}
							}

							$drugs[] = $res;
							
							if($res['clinical_check_status'] == 1){
								$removed_rx_keys [] = $res['prescription_id'];
								$info = array(
									'dose_instruction' => $res['Dose_Instructions'],
									'item_name' => $res['item_name'],
									'drugs_key' => $res['item_key'],
									'prescription_id' => $res['prescription_id'],
									'prescription_key' => $res['prescription_id'],
									'repeats' => $res['repeats'],
									'repeats_left' => $res['requests_left'],
									'quantity' => $res['quantity'],
									'next_reminder_date' => '',
									'prescription_date' => $res['prescription_date'],
									'price' => $res['Price_Per_Dose'],
									'last_reminder_date' => '',
									'clinical_check_status' => $res['clinical_check_status'],
									'dose_description' => $res['Item_Name_Full'],
									'quantity' => 1,
									'formulation_type' => $res['formulation_type'],
									'formula_medicine_kf' => $res['formula_medicine_kf'],
									'prescriber_name' => $res['prescriber_name'],
									'formulation' => $res['PrescriptionItem_Formulation'],
								);
								$prescription_drugs[] = $info;
								if($info['next_reminder_date']){
									$insert_reminder_options[] = array(
										'patient_id' => Auth::user()->patient->id,
										'prescription_item_key' => $info['drugs_key'],
										'date' => date('Y-m-d', strtotime($info['next_reminder_date'])),
										'notified' => false,
										'created_at' => date('Y-m-d H:i:s'),
										'updated_at' => date('Y-m-d H:i:s'),
									);
								}
								if($info['last_reminder_date']){
									$insert_reminder_options[] = array(
										'patient_id' => Auth::user()->patient->id,
										'prescription_item_key' => $info['drugs_key'],
										'date' => date('Y-m-d', strtotime($info['last_reminder_date'])),
										'notified' => false,
										'created_at' => date('Y-m-d H:i:s'),
										'updated_at' => date('Y-m-d H:i:s'),
									);
								}
								$delete_reminder_options[] = $info['drugs_key'];
							}
						}
						Cache::put($patient_user_id.'_patient_rx_item_'.$f.'_'.$current_page, $drugs, $cache_expires_at);
						
						PrescriptionDrugsReminderDates::whereIn('prescription_item_key', $delete_reminder_options)->where('patient_id',Auth::user()->patient->id)->delete();
						PrescriptionDrugsReminderDates::insert($insert_reminder_options);
						PrescriptionDrugsTemp::whereIn('prescription_id', $removed_rx_keys)->delete();
						PrescriptionDrugsTemp::insert($prescription_drugs);
					}
				}else{
					throw new \Exception("Invalid request or can't get access token.");
				}
				
				activity()->causedBy(Auth::user())->log('List all prescriptions from SPED');
			}
			
			return view('patients.my-medications')
				->with('total_found_records', $total_found_records)
				->with('sort', $sort)
				->with('current_page', $current_page)
				->with('limit', $limit)
				->with('orderby', $orderby)
				->with('query_string', $query_string)
				->with('drugs', $drugs);
		}catch(\Exception $e){
			Session::put('message', 'We are currently making improvements to The Specialist Pharmacy Patient Portal at this time. We apologise for any inconvenience. Please contact us on +44 (0) 207 637 1055 about your prescription.');
            Session::put('alert-type', 'alert-warning');
            return Redirect::to('/patients/my-profile');
        }
	}
	/**
     * Get a prescription medications
     *
     * @param  @param  prescription id
     * @return view
     */
	public function getPatientsPrescriptionMedications($prescription_id){
        $view = (Request::has('viewport') && Request::get('viewport') == 'mobile') ? 'patients.single-medications-mobile' : 'patients.single-medications';
        $is_mgc_clinic = Request::get('is_mgc_clinic');
		try{
            $prescription_drugs = array();
            $prescription = PrescriptionTemp::where('prescription_key', $prescription_id)->first();
            if(!$prescription) {
                throw new \Exception('The selected prescription has not been found.');
            }
            else if($prescription->patient_id != Auth::user()->patient->id) {
                throw new \Exception('You are not authorised to access this prescription.');
            }
			else if($prescription->has_unpaid_prescription == 'NO') {
                //throw new \Exception('<div class="msgBlock">This prescription has been paid already.</div>');
            }
            $prescription_expired = false;
            $generation_date = date('Y-m-d', strtotime($prescription->creation_timestamp));
            $expiry_date = date('Y-m-d', strtotime('+12 months', strtotime($generation_date)));
            if(date('Y-m-d') > $expiry_date) {
                $prescription_expired = true;
            }
			PrescriptionDrugsTemp::where('prescription_key', $prescription_id)->delete();
            $fm = new FileMakerService();
            $access_token = $fm->getFMAccessTokenFromSession();
			$drugs = [];
            if($access_token) {
               $patient_fm_pk = Auth::user()->patient->patient_fm_pk;
				if($patient_fm_pk){
					$request_params['query'] = [['Patient_Kp' => '=='.$patient_fm_pk, 'Prescription_kf' => $prescription_id ]];
				}else{
					$request_params['query'] = [['Patient_Email_Address' => '=='.Auth::user()->patient->email , 'Prescription_kf' => $prescription_id]];
				}
				$request_params['sort'] = [[
					'fieldName' => 'Creation_Timestamp',
					'sortOrder' => 'descend'
				]];
                $response = $fm->getAllPrescriptionsDrugsForPatient($access_token, $request_params);
				$insert_reminder_options = [];
				$delete_reminder_options = [];
				if($response->messages && $response->messages[0]->message == 'OK') {
					foreach($response->response->data as $res){
						$item = json_decode(json_encode($res->fieldData), true);
						$item_name = $res->fieldData->Item_Name_Full ? $res->fieldData->Item_Name_Full : $res->fieldData->Item_Name;
                        // Check for MGC Clinic price modification
                        if ($is_mgc_clinic && $res->fieldData->{"PrescriptionItem__Formula::Formulation"} == 'Capsules') {
                            if ($res->fieldData->{"PrescriptionItem__Formula::Dose_Amount"} == 100 && $res->fieldData->Price_Per_Dose == 70 && strpos($item_name, 'DHEA') !== false && strpos($item_name, 'Keto') === false) {
                                $item['Price_Per_Dose'] = 58;
                            } elseif ($res->fieldData->{"PrescriptionItem__Formula::Dose_Amount"} == 60 && $res->fieldData->Price_Per_Dose == 50 && strpos($item_name, 'DHEA') !== false && strpos($item_name, 'Keto') === false) {
                                $item['Price_Per_Dose'] = 35;
                            }
                        }

                        $drugs[] = $item;
						$append_poms_dose_unit = '';
						if($res->fieldData->formulaOrMedicine == 'M' && $res->fieldData->Repeats_Left >= 0.1 && $res->fieldData->Repeats_Left < 1){
							$append_poms_dose_unit = '('.$res->fieldData->{'PrescriptionItem__Medicine::Dose_Amount'} * $res->fieldData->Repeats_Left.' Dose Units)';
						}
						if($res->fieldData->clinicalCheckStatus == 1){
							$info = array(
								'dose_instruction' => $res->fieldData->Dose_Instructions,
								'item_name' => $res->fieldData->Item_Name.$append_poms_dose_unit,
								'drugs_key' => $res->fieldData->Primary_kp,
								'prescription_id' => $prescription->prescription_fm_id,
								'prescription_key' => $prescription_id,
								'repeats' => $res->fieldData->Repeats,
								'repeats_left' => $res->fieldData->Repeats_Left,
								'quantity' => $res->fieldData->Quantity,
								'next_reminder_date' => $res->fieldData->NextReminderDate ? date('d-m-Y', strtotime($res->fieldData->NextReminderDate)) : '',
								'prescription_date' => $res->fieldData->Creation_Timestamp,
								'price' => $item['Price_Per_Dose'],
								'last_reminder_date' => $res->fieldData->LastReminderDate ? date('Y-m-d', strtotime($res->fieldData->NextReminderDate)): '',
								'clinical_check_status' => $res->fieldData->clinicalCheckStatus,
								'dose_description' => $res->fieldData->Item_Name_Full.$append_poms_dose_unit,
								'quantity' => 1,
								'formulation_type' => $res->fieldData->formulaOrMedicine,
								'formula_medicine_kf' => $res->fieldData->FormulaMedicine_kf,
								'prescriber_name' => $prescription->prescriber_name,
								'formulation' => $res->fieldData->{'PrescriptionItem__Formula::Formulation'},
							);
							$prescription_drugs[] = $info;
							if($info['next_reminder_date']){
								$insert_reminder_options[] = array(
									'patient_id' => Auth::user()->patient->id,
									'prescription_item_key' => $info['drugs_key'],
									'date' => date('Y-m-d', strtotime($info['next_reminder_date'])),
									'notified' => false,
									'created_at' => date('Y-m-d H:i:s'),
									'updated_at' => date('Y-m-d H:i:s'),
								);
							}
							if($info['last_reminder_date']){
								$insert_reminder_options[] = array(
									'patient_id' => Auth::user()->patient->id,
									'prescription_item_key' => $info['drugs_key'],
									'date' => date('Y-m-d', strtotime($info['last_reminder_date'])),
									'notified' => false,
									'created_at' => date('Y-m-d H:i:s'),
									'updated_at' => date('Y-m-d H:i:s'),
								);
							}
							$delete_reminder_options[] = $info['drugs_key'];
						}
                    }
					PrescriptionDrugsReminderDates::whereIn('prescription_item_key', $delete_reminder_options)->where('patient_id',Auth::user()->patient->id)->delete();
					PrescriptionDrugsReminderDates::insert($insert_reminder_options);
					PrescriptionDrugsTemp::insert($prescription_drugs);
				}
				return response()
					->view($view, ['drugs' => $drugs, 'prescription_id' => $prescription_id], 200)
					->header('Content-Type', 'text/html');
			}else{
                throw new \Exception("Invalid request or can't get access token.");
            }
		}catch(\Exception $e){

			 return response()
			->view($view, ['drugs' => [], 'flavours'=> [], 'prescription_id' => $prescription_id], 200)
			->header('Content-Type', 'text/html');
        }
	}
	/**
     * Get all patients orders
     *
     * @param  @param  NULL
     * @return view
     */
	public function getPatientOrders($filter_by = 0){
		try{
			$sort = Request::get('sort') ? Request::get('sort') : 'descend';
            $orderby = Request::get('orderby') ? Request::get('orderby') : 'Date';

			$fm = new FileMakerService();
            $access_token = $fm->getFMAccessTokenFromSession();
            if($access_token) {
                $patient_fm_pk = Auth::user()->patient->patient_fm_pk;
				$offset = 1;
				if(Request::has('limit') && Request::get('limit') == -1){
					$limit = 10000;
				}else{
					$limit = 10;
				}
				$current_page = 1;
				if(Request::has('page')){
					$page = Request::get('page');
					$current_page = $page;
					$offset = (($page - 1) * $limit)+1;
				}
                if($patient_fm_pk){
					$request_params['query'] = [
						['Patient_kf' => $patient_fm_pk, "itemCount" => ">0"]
					];
					$request_params['sort'] = [['fieldName' => $orderby, 'sortOrder' => $sort]];
					$request_params['limit'] = $limit;
					$request_params['offset'] = $offset;
				}else{
					$request_params['query'] = [
						['Request_Patient::patientPrimaryEmailAddress' => Auth::user()->patient->email, "itemCount" => ">0"]
					];
					$request_params['sort'] = [['fieldName' => $orderby, 'sortOrder' => $sort]];
					$request_params['limit'] = $limit;
					$request_params['offset'] = $offset;
				}
				
				if($filter_by == 1){
					$request_params['query'][0]['codeStatus'] = 3;
				}else if($filter_by == 2){
					$request_params['query'][0]['codeStatus'] = 6;
				}
				$response = $fm->getAllOrdersForPatient($access_token, $request_params);
				$orders = [];
				$total_found_records = 0;
				$total_production_records = 0;
				$total_in_dispetched_records = 0;
				if($response->messages && $response->messages[0]->message == 'OK') {
					$total_found_records = $response->response->data[0]->fieldData->totalOrderItemsSummary;
					$total_production_records = $response->response->data[0]->fieldData->totalOrderItemsInProductionSummary;
					$total_in_dispetched_records = $response->response->data[0]->fieldData->totalOrderItemsDispatchedSummary;
					$orders = $response->response->data;
				}
				echo "<pre>";
				print_r($orders);
				die();
				return view('patients.my-orders')
					->with('total_found_records', $total_found_records)
					->with('total_production_records', $total_production_records)
					->with('total_in_dispetched_records', $total_in_dispetched_records)
					->with('sort', $sort)
					->with('current_page', $current_page)
					->with('limit', $limit)
					->with('orderby', $orderby)
					->with('filter_by', $filter_by)
					->with('orders', $orders);
			}else{
                throw new \Exception("Invalid request or can't get access token.");
            }
		}catch(\Exception $e){
			Session::put('message', 'We are currently making improvements to The Specialist Pharmacy Patient Portal at this time. We apologise for any inconvenience. Please contact us on +44 (0) 207 637 1055 about your prescription.');
            Session::put('alert-type', 'alert-warning');
            return Redirect::to('/patients/my-profile');
		}
	}

	/**
     * Get patient information from sped and update on portal
     *
     * @param  @param  NULL
     * @return NULL
     */
	private function getPatientProfileFromSpeed(){
		$sp = new SpeedAPIService();
		$access_token = $sp->getAccessToken();
		if($access_token) {
			$patient_registration_no = Auth::user()->patient->patient_fm_id;
			$response = $sp->getPatientDetails($access_token, $patient_registration_no);
			if($response['status']){
				$user_info = $response['returnData'];
				$patient_id = Auth::user()->patient->id;
				$patient = Patients::find($patient_id);
				$patient->title = $user_info['title'];
				$patient->name = $user_info['first_name'].' '.$user_info['last_name'];
				$patient->phone_number = $user_info['phone_number'];
				$patient->address_line_1 = $user_info['address'];
				$patient->town = $user_info['town'];
				$patient->county = $user_info['county'] ? $user_info['county']: $patient->county;
				$patient->country = $user_info['country'];
				$patient->postcode = $user_info['postcode'];
				$patient->date_of_birth = date('Y-m-d', strtotime($user_info['dob']));
				if(!$patient->patient_fm_id){
					$patient->patient_fm_pk = $user_info['Id'];
					$patient->patient_fm_id = $user_info['registrationNo'];
				}
				if($user_info['discount']){
					$patient->discount = floatval($user_info['discount']);
				}else{
					$patient->discount = 0;
				}
				$patient->save();
				$user = User::find($patient->user_id);
				$user->first_name = $user_info['first_name'];
				$user->last_name = $user_info['last_name'];
				$user->save();
				$user_id = $user->id;
				$email_settings = UsersSettings::firstOrNew(['user_id' => $user_id, 'name' => 'remind_by_email']);
				if($user_info['email_opt_in'] == 'Yes'){
					$email_settings->name = 'remind_by_email';
					$email_settings->value = 'true';
				}else{
					$email_settings->name = 'remind_by_email';
					$email_settings->value = 'false';
				}
				$email_settings->save();
				$sms_settings = UsersSettings::firstOrNew(['user_id' => $user_id, 'name' => 'remind_by_sms']);
				if($user_info['sms_opt_in'] ){
					$sms_settings->name = 'remind_by_sms';
					$sms_settings->value = 'true';
				}else{
					$sms_settings->name = 'remind_by_sms';
					$sms_settings->value = 'false';
				}
				$sms_settings->save();
			}
		}
	}
}
