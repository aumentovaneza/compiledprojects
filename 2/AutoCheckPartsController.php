<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Part;
use DB;
use App\OrderDetails;
use App\PartParentSku;
use App\Logs;


class AutoCheckPartsController extends Controller
{
    //system create order
    public function createOrder($id) {
        $date = Carbon::now();

        //get parentsku of part
        $parentsku = PartParentSku::where('part_id','=',$id)->where('isMain','True')->first();
        $psku = $parentsku->parentsku_id;

        //get part
        $selPart = Part::where('id',$id)->first();
        $restockqty = $selPart->restockqty;
        $partsku = $selPart->id;
        $supplier = $selPart->supplier_id;
        $part_status = $selPart->part_status;
        $description = $selPart->desc;
        $image = $selPart->image;


        if ($part_status == "Active") {
            $newPIOrder = new OrderDetails();
            $newPIOrder->date_created = Carbon::now();
            $newPIOrder->order_type = "Parts Inventory";
            $newPIOrder->part_sku = $partsku;
            $newPIOrder->supplier = $supplier;
            $newPIOrder->quantity = $restockqty;
            $newPIOrder->description = $description;
            $newPIOrder->parent_sku = $psku;
            $newPIOrder->image = $image;
            $newPIOrder->order_status = "Pending";
            $newPIOrder->save();
        }

        $logs = new Logs();
        $logs->description ="System created a new Part Order with Part SKU ". $newPIOrder->part_sku." today (". $date .")";
        $logs->save();

        return response()->json(array("success"=>true));
    }

    //create order manually
    public function createManualOrder(Request $request, $id) {
        $date = Carbon::now();

        //get parentsku of part
        $parentsku = PartParentSku::where('part_id','=',$id)->where('isMain','True')->get();

        //get part
        $selPart = Part::where('id',$id)->first();
        $restockqty = $selPart->restockqty;
        $partsku = $selPart->id;
        $supplier = $selPart->supplier_id;
        $description = $selPart->desc;
        $image = $selPart->image;

        if (!$parentsku->isEmpty()) {
            $newPIOrder = new OrderDetails();
            $newPIOrder->date_created = $date;
            $newPIOrder->order_type = "Parts Inventory";
            $newPIOrder->part_sku = $partsku;
            $newPIOrder->supplier = $supplier;
            $newPIOrder->quantity = $restockqty;
            $newPIOrder->description = $description;
            $newPIOrder->parent_sku = $request->parentsku_id;
            $newPIOrder->image = $image;
            $newPIOrder->order_status = "Pending";
            $newPIOrder->save();
        }

        $logs = new Logs();
        $user = \Auth::user()->first_name;
        $logs->description = $user . " created a new Part Order with Part SKU ". $newPIOrder->part_sku." today (". $date .")";
        $logs->save();
        return response()->json(array("success"=>true));
    }

    //check stocks of part
    public function checkStocks() {
        $pendingOrders = OrderDetails::select(['part_sku'])
                        ->where('order_type','Parts Inventory')
                        ->where(function ($query) {
                        $query->where('order_status','Pending')
                            ->orWhere('order_status','Pending with Purchasing')
                            ->orWhere('order_status','Requested')
                            ->orWhere('order_status','No Order Required');
                        })
                        ->get();

        $penOrders = $pendingOrders->toArray();

        $partIDs = Part::select(['id'])
                   ->where('part_status','!=','Inactive')
                   ->whereNotIn('id',$penOrders)
                   ->get();

        $partID = $partIDs->toArray();
        
        foreach($partID as $id) {
            $part = Part::where('id',$id)->first();
            $soh = $part->stocksonhand;
            $reorder = $part->reorderqty;

            if($soh <= $reorder) {
                $this->createOrder($id);
            }
        }
    }
}
