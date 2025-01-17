<?php

namespace App\Http\Controllers;

require __DIR__.'/../../../vendor/autoload.php';
require_once("phpqrcode/qrlib.php");

use App\Batch;
use App\POSProduct;
use App\ProductVariation;
use App\SalesGroup;
use App\SalesHistory;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Response;
use Mike42\Escpos\Printer;
use Mike42\Escpos\EscposImage;
use Mike42\Escpos\PrintConnectors\FilePrintConnector;

class CartController extends Controller {

    public function __construct() {
        $this->middleware("auth");
    }

    public function test() {
        $last_receipt_no = SalesGroup::all()->sortByDesc("id")->first()->receipt_no;
        $num = $last_receipt_no + 1;
        var_dump($num);

        die();
    }

    /**
     ** return all items in cart
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function all() {
        if(request()->ajax()) {
            return Response::json(array(\Cart::getContent()));
        } else {
            return Response::json([]);
        }
    }

    /**
     ** add a new item to cart
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function add(Request $request) {
        $batch_id = $request["batch_id"];
        $name = $request["name"];
        $price = $request["price"];
        $qty = $request["quantity"];

        // Before adding, check to ensure the current batch for product has enough quantity
        $product_batch = Batch::find($batch_id);
        $product_variation = ProductVariation::find($product_batch->variation_id);

        $item = \Cart::get($batch_id);
        $item_total_qty = $qty;
        if($item)
            $item_total_qty += $item->quantity;

        $remaining_stock = ($product_batch->current_quantity - $item_total_qty);

        $new_batch_exists = "regular";
        $new_batch = null;

        if($remaining_stock == -1) {
            // set current batch "on_sale" value to 0
            $product_batch->on_sale = 0;
            $product_batch->save();

            // check to see if a new batch exists
            $new_batch = Batch::where("variation_id", $product_variation->id)->where("current_quantity", ">", 0)->first();

            // if it does, set the 'on_sale' value to 1
            if($new_batch) {
                $new_batch->on_sale = 1;
                $new_batch->save();
                $new_batch_exists = "true";
            } else {
                $new_batch_exists = "false";
            }
        }

        // Note that the new batch is not what will be added to the cart at this time.
        // Not until the user requests for an additional quantity

        // Remaining quantity is more than zero
        \Cart::add($product_batch->id, $name, $price, $qty, array());

        return Response::json([
            "new_batch" => $new_batch,
            "new_batch_exists" => $new_batch_exists,
            /*"item" => $item,
            "qty" => $qty,
            "remaining" => $remaining_stock*/
        ]);
    }

    /**
     ** update an existing cart item
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request) {
        $batch_id = $request["id"];
        $price = $request["price"];
        $qty = $request["quantity"];

        \Cart::update($batch_id, array(
            "quantity" => $qty,
            "price" => $price
        ));

        return Response::json(array(\Cart::getContent()));
    }

    /**
     * remove an item from cart
     *
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function delete($id) {

        if((int)$id > 0) {
            if (\Cart::remove($id)) {
                return Response::json(["success" => "Item has been removed from cart!"]);
            } else {
                return Response::json(["error" => "Item does not exist in cart!"]);
            }
        } else {
            return Response::json(["error" => "Item id should be of type int and greater than zero!"], 406);
        }
    }

    /**
     ** delete all items in cart
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteAll() {
        $this->clearCart();
        return Response::json(["success" => "All cart items have been removed!"]);
    }

    /**
     ** record sales of items in cart
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sellLN(Request $request) {
        $tendered_amount = $request["tendered"];
        $change = $request["change"];
        $remaining_balance = $request["balance_remaining"];
        $payment_method = title_case($request["payment_method"]);
        $remarks = $request["remarks"];

        $sales_group = null;

        // All items in cart
        $items = \Cart::getContent();

        // Get a comma-separated string of names of all products in cart
        $product_names = "";
        foreach ($items as $item) {
            $product_names .= "{$item->name}, ";
        }

        $last_receipt_no = 10285142;
        $_last_sale = SalesGroup::all()->sortByDesc("id");
        if(count($_last_sale) > 0) {
            $last_receipt_no = $_last_sale->first()->receipt_no;
        }

        // Create a new sales group
        $sales_group = SalesGroup::create([
            "products" => rtrim($product_names, ", "),
            "total_amount" => \Cart::getSubTotal(),
            "amount_tendered" => $tendered_amount,
            "change_amount" => $change,
            "balance_due" => $remaining_balance,
            "payment_method" => $payment_method,
            "remarks" => ($remarks == "") ? Carbon::now() : $remarks,
            "seller" => \Auth::user()->name,
            "receipt_no" => (int)$last_receipt_no + 1
        ]);

        // Loop through each cart item
        // retrieve product and batch details
        // persist a new SalesHistory to DB
        // update each batch current_quantity value
        // update product stock value
        foreach ($items as $item) {
            //get active product batch
            $batch_id = $item->id;
            $active_batch = Batch::find($batch_id);

            // Get product variation
            $product_variation = ProductVariation::find($active_batch->variation_id);

            // reduce batch's current quantity by the sold quantity
            $active_batch->current_quantity -= $item->quantity;
            $active_batch->save();

            // update product variation stock value
            $product_variation->stock -= $item->quantity;
            $product_variation->save();

            // Initialize helper variables
            $sale_price = ($item->price / $item->quantity);
            $loss = 0;
            $profit = 0;

            // Calculate profit & loss
            if(($sale_price - $active_batch->unit_cost) > 0) {
                $profit = $sale_price - $active_batch->unit_cost;
            } elseif(($sale_price - $active_batch->unit_cost) < 0) {
                $loss = -1 * ($sale_price - $active_batch->unit_cost);
            }

            // Create new selling history
            $sales_history = SalesHistory::create([
                "sales_group" => $sales_group->id,
                "variation_id" => $product_variation->id,
                "batch_id" => $active_batch->id,
                "quantity" => $item->quantity,
                "total_cost" => doubleval($item->price),
                "unit_cost" => doubleval($sale_price),
                "profit" => doubleval($profit),
                "loss" => doubleval($loss)
            ]);
        }

        $app_settings = \DB::table("app_config")->get()->first();

        function divideFloat($a, $b, $precision=2) {
            $a*=pow(10, $precision);
            $result=(int)($a / $b);
            if (strlen($result)==$precision) return '0.' . $result;
            else return preg_replace('/(\d{' . $precision . '})$/', '.\1', $result);
        }

        $data_array =  array(
                "euro" => divideFloat($sales_group['change_amount'],100)
        );
        
        $make_call = callAPI('POST', 'http://localhost:5000/lnurl', json_encode($data_array));
        $response = json_decode($make_call, true);
        $lnurl    = $response['lnurl'];
        $satoshi  = $response['satoshis'];

        /* Information for the receipt */
        $tendered = new item('Amount Paid', divideFloat($sales_group['amount_tendered'],100));
        $change = new item('Change', divideFloat($sales_group['change_amount'],100));
        $satoshis = new item('Change (in satoshis)', $satoshi);
        $total = new item('Total', divideFloat($sales_group['total_amount'],100), true);
        /* Date is kept the same for testing */
        $date = date('l jS \of F Y h:i:s A');
        //$date = "Monday 6th of April 2015 02:56:25 PM";

        require_once("phpqrcode/qrlib.php");

        \QRcode::png($lnurl, "test.png", 'L', 5, 0);

        $img = EscposImage::load("test.png");
        $logo = EscposImage::load(__DIR__.'/../../../public/img/blocklogo.png', false);

        $connector = new FilePrintConnector("/dev/usb/lp0");

        $printer = new Printer($connector);

        /* Print logo */
        $printer -> setJustification(Printer::JUSTIFY_CENTER);
        $printer -> bitImage($logo);
        $printer -> feed();

        /* Name of shop */
        $printer -> setJustification(Printer::JUSTIFY_LEFT);
        $printer -> selectPrintMode(Printer::MODE_DOUBLE_WIDTH);
        $printer -> text("The Block Lisboa\n");
        $printer -> selectPrintMode();
        $printer -> text("R. Latino Coelho 63 1er Andar, 1050-133 Lisboa\n");
        $printer -> feed();

        /* Title of receipt */
        $printer -> setEmphasis(true);
        $printer -> text("SALES INVOICE {$sales_group['receipt_no']}\n");
        $printer -> text("Your cashier: {$sales_group['seller']}\n");
        $printer -> setEmphasis(false);
        $printer -> text($date);
        $printer -> feed();

        /* Items */
        $printer -> setJustification(Printer::JUSTIFY_LEFT);
        $printer -> setEmphasis(true);
        $printer -> text(new item('', 'EUR'));
        $printer -> setEmphasis(false);
        foreach ($items as $item) {
            $printer -> text(new item($item['name'],divideFloat($item['price'],100),$item['quantity']));
        }
        
        $printer -> feed();
        $printer -> setEmphasis(true);
        $printer -> text($total);
        $printer -> setEmphasis(false);
        $printer -> feed();

        /* Change part */
        $printer -> text($tendered);
        $printer -> text($change);
        $printer -> text($satoshis);
        
        /* Footer */
        $printer -> feed(2);
        $printer -> setJustification(Printer::JUSTIFY_CENTER);

        /* Print LNURL */
        $printer -> bitImage($img);
        $printer -> feed();

        $printer -> text("This is a QR code that can be scanned\n");
        $printer -> text("with a compatible Bitcoin Lightning Wallet.\n");
        $printer -> feed();
        $printer -> text("To receive your bitcoin, you need to scan\n");
        $printer -> text("this QR code with one of the following wallets:\n");
        $printer -> feed();
        $printer -> text("- Bitcoin Lightning Wallet (BLW)\n");
        $printer -> text("- Bluewallet\n");
        $printer -> text("- Wallet of Satoshi\n");
        $printer -> feed(2);
        /* Cut the receipt and open the cash drawer */
        $printer -> cut();

        $printer -> close();

        // return receipt number and app settings
        return Response::json([
            "sales_group" => $sales_group,
            "items" => $items,
            "settings" => $app_settings
        ]);
    }

    public function sell(Request $request) {
        $tendered_amount = $request["tendered"];
        $change = $request["change"];
        $remaining_balance = $request["balance_remaining"];
        $payment_method = title_case($request["payment_method"]);
        $remarks = $request["remarks"];

        $sales_group = null;

        // All items in cart
        $items = \Cart::getContent();

        // Get a comma-separated string of names of all products in cart
        $product_names = "";
        foreach ($items as $item) {
            $product_names .= "{$item->name}, ";
        }

        $last_receipt_no = 10285142;
        $_last_sale = SalesGroup::all()->sortByDesc("id");
        if(count($_last_sale) > 0) {
            $last_receipt_no = $_last_sale->first()->receipt_no;
        }

        // Create a new sales group
        $sales_group = SalesGroup::create([
            "products" => rtrim($product_names, ", "),
            "total_amount" => \Cart::getSubTotal(),
            "amount_tendered" => $tendered_amount,
            "change_amount" => $change,
            "balance_due" => $remaining_balance,
            "payment_method" => $payment_method,
            "remarks" => ($remarks == "") ? Carbon::now() : $remarks,
            "seller" => \Auth::user()->name,
            "receipt_no" => (int)$last_receipt_no + 1
        ]);

        // Loop through each cart item
        // retrieve product and batch details
        // persist a new SalesHistory to DB
        // update each batch current_quantity value
        // update product stock value
        foreach ($items as $item) {
            //get active product batch
            $batch_id = $item->id;
            $active_batch = Batch::find($batch_id);

            // Get product variation
            $product_variation = ProductVariation::find($active_batch->variation_id);

            // reduce batch's current quantity by the sold quantity
            $active_batch->current_quantity -= $item->quantity;
            $active_batch->save();

            // update product variation stock value
            $product_variation->stock -= $item->quantity;
            $product_variation->save();

            // Initialize helper variables
            $sale_price = ($item->price / $item->quantity);
            $loss = 0;
            $profit = 0;

            // Calculate profit & loss
            if(($sale_price - $active_batch->unit_cost) > 0) {
                $profit = $sale_price - $active_batch->unit_cost;
            } elseif(($sale_price - $active_batch->unit_cost) < 0) {
                $loss = -1 * ($sale_price - $active_batch->unit_cost);
            }

            // Create new selling history
            $sales_history = SalesHistory::create([
                "sales_group" => $sales_group->id,
                "variation_id" => $product_variation->id,
                "batch_id" => $active_batch->id,
                "quantity" => $item->quantity,
                "total_cost" => doubleval($item->price),
                "unit_cost" => doubleval($sale_price),
                "profit" => doubleval($profit),
                "loss" => doubleval($loss)
            ]);
        }

        $app_settings = \DB::table("app_config")->get()->first();

        function divideFloat($a, $b, $precision=2) {
            $a*=pow(10, $precision);
            $result=(int)($a / $b);
            if (strlen($result)==$precision) return '0.' . $result;
            else return preg_replace('/(\d{' . $precision . '})$/', '.\1', $result);
        }

        /* Information for the receipt */
        $tendered = new item('Amount Paid', divideFloat($sales_group['amount_tendered'],100));
        $change = new item('Change', divideFloat($sales_group['change_amount'],100));
        $total = new item('Total', divideFloat($sales_group['total_amount'],100), true);
        /* Date is kept the same for testing */
        $date = date('l jS \of F Y h:i:s A');
        //$date = "Monday 6th of April 2015 02:56:25 PM";
        
        $logo = EscposImage::load(__DIR__.'/../../../public/img/blocklogo.png', false);

        $connector = new FilePrintConnector("/dev/usb/lp0");

        $printer = new Printer($connector);

        /* Print logo */
        $printer -> setJustification(Printer::JUSTIFY_CENTER);
        $printer -> bitImage($logo);
        $printer -> feed();

        /* Name of shop */
        $printer -> setJustification(Printer::JUSTIFY_LEFT);
        $printer -> selectPrintMode(Printer::MODE_DOUBLE_WIDTH);
        $printer -> text("The Block Lisboa\n");
        $printer -> selectPrintMode();
        $printer -> text("R. Latino Coelho 63 1er Andar, 1050-133 Lisboa\n");
        $printer -> feed();

        /* Title of receipt */
        $printer -> setEmphasis(true);
        $printer -> text("SALES INVOICE {$sales_group['receipt_no']}\n");
        $printer -> text("Your cashier: {$sales_group['seller']}\n");
        $printer -> setEmphasis(false);
        $printer -> text($date);
        $printer -> feed();

        /* Items */
        $printer -> setJustification(Printer::JUSTIFY_LEFT);
        $printer -> setEmphasis(true);
        $printer -> text(new item('', 'EUR'));
        $printer -> setEmphasis(false);
        foreach ($items as $item) {
            $printer -> text(new item($item['name'],divideFloat($item['price'],100),$item['quantity']));
        }
        
        $printer -> feed();
        $printer -> setEmphasis(true);
        $printer -> text($total);
        $printer -> setEmphasis(false);
        $printer -> feed();

        /* Change part */
        $printer -> text($tendered);
        $printer -> text($change);

        $printer -> feed(2);
        $printer -> setJustification(Printer::JUSTIFY_CENTER);
        $printer -> text("THANK YOU FOR BUYING FROM US\n");
        $printer -> text("Returns are only accepted within 24 hours\nfrom the time of purchase, on show of this\nreceipt, and given the item is in\nan acceptable good condition.\n");
        $printer -> feed(2);
        /* Cut the receipt and open the cash drawer */
        $printer -> cut();
        $printer -> pulse();

        $printer -> close();

        // return receipt number and app settings
        return Response::json([
            "sales_group" => $sales_group,
            "items" => $items,
            "settings" => $app_settings
        ]);
    }

    /**
     ** validate all items in cart
     ** ensure requested quantity is not more than available stock
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function process() {
        $error = "";

        $total = \Cart::getTotal();
        if((int)$total == 0) {
            $error = "The cart seems to be empty.";
            return Response::json(["error" => $error], 406);
        }

        $items = \Cart::getContent();
        $pos_product = new POSProduct();
        foreach ($items as $item) {
            $product = $pos_product->findByBatch($item->id);
            if (floatval($item->quantity) > floatval($product->available_stock)) {
                $error .= "Requested quantity for {$product->variation_name} is more than available stock.\n";
            }
        }

        if ($error != "") {
            return Response::json(["error" => $error], 406);
        } else {
            return Response::json([
                "success" => "All cart items can be sold!",
                "items" => $items,
            ]);
        }
    }

    /**
     ** clear all items in cart
     *
     */
    public function clearCart() {
        $items = \Cart::getContent();
        foreach ($items as $item) {
            \Cart::remove($item->id);
        }
        \Cart::clear();
    }

    /**
     * @param $product_id
     * @return Batch
     */
    public function getProductBatch($product_id) {
        $batch = Batch::where("variation_id", $product_id)->where("on_sale", 1)->first();
        return $batch ?? new Batch();
    }
}

class item
{
    private $quantity;
    private $name;
    private $price;
    private $dollarSign;

    public function __construct($name = '', $price = '', $quantity = 0, $dollarSign = false)
    {
        $this -> quantity = $quantity;
        $this -> name = $name;
        $this -> price = $price;
        $this -> dollarSign = $dollarSign;
    }

    public function __toString()
    {
        $rightCols = 10;
        $leftCols = 38;
        if ($this -> dollarSign) {
            $leftCols = $leftCols / 2 - $rightCols / 2;
        }
        $sign = ($this -> dollarSign ? 'EUR ' : '');

        if ($this -> name == 'Total') {
            $left = str_pad($this -> name, $leftCols);
            $right = str_pad($sign . $this -> price, $rightCols, ' ', STR_PAD_LEFT);
        } elseif ($this -> quantity > 0) {
            $left = str_pad($this -> quantity . ' x ' . $this -> name, $leftCols);
        } else {
            $left = str_pad($this -> name, $leftCols);
        }

        $right = str_pad($sign . $this -> price, $rightCols, ' ', STR_PAD_LEFT);
        return "$left$right\n";
    }
}

function callAPI($method, $url, $data){
    $curl = curl_init();
 
    switch ($method){
       case "POST":
          curl_setopt($curl, CURLOPT_POST, 1);
          if ($data)
             curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
          break;
       case "PUT":
          curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
          if ($data)
             curl_setopt($curl, CURLOPT_POSTFIELDS, $data);			 					
          break;
       default:
          if ($data)
             $url = sprintf("%s?%s", $url, http_build_query($data));
    }
 
    // OPTIONS:
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
       'APIKEY: 111111111111111111111',
       'Content-Type: application/json',
    ));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
 
    // EXECUTE:
    $result = curl_exec($curl);
    if(!$result){die("Connection Failure");}
    curl_close($curl);
    return $result;
 }