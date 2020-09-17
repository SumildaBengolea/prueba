<?php

namespace App\Http\Controllers;

use App\Addon;
use App\Coupon;
use App\GpsTable;
use App\Helpers\TranslationHelper;
use App\Item;
use App\Order;
use App\Orderitem;
use App\OrderItemAddon;
use App\Orderstatus;
use App\PushNotify;
use App\Restaurant;
use App\Sms;
use App\User;
use Hashids;
use Illuminate\Http\Request;
use Omnipay\Omnipay;

class OrderController extends Controller
{
    /**
     * @param Request $request
     */
    public function placeOrder(Request $request, TranslationHelper $translationHelper)
    {
        $keys = ['orderPaymentWalletComment', 'orderPartialPaymentWalletComment'];
        $translationData = $translationHelper->getDefaultLanguageValuesForKeys($keys);

        $newOrder = new Order();

        $checkingIfEmpty = Order::count();

        $lastOrder = Order::orderBy('id', 'desc')->first();

        if ($lastOrder) {
            $lastOrderId = $lastOrder->id;
            $newId = $lastOrderId + 1;
            $uniqueId = Hashids::encode($newId);
        } else {
            //first order
            $newId = 1;
        }
        $uniqueId = Hashids::encode($newId);
        $unique_order_id = 'OD' . '-' . date('m-d') . '-' . strtoupper(str_random(4)) . '-' . strtoupper($uniqueId);
        $newOrder->unique_order_id = $unique_order_id;

        $restaurant_id = $request['order'][0]['restaurant_id'];
        
        $restaurant = Restaurant::where('id', $restaurant_id)->first();

        $newOrder->currency_type_code = $restaurant->codigo_moneda;
        $newOrder->currency_type_simbol = $restaurant->simbolo_moneda;
        $newOrder->currency_type_miles = $restaurant->separador_mil_moneda;
        $newOrder->currency_type_decimal = $restaurant->decimal_moneda;
        
        $newOrder->user_id = $request['user']['data']['id'];

        if ($restaurant->auto_acceptable) {
            $newOrder->orderstatus_id = '2';
            $this->smsToDelivery($restaurant_id);
        } else {
            $newOrder->orderstatus_id = '1';
        }

        $newOrder->location = json_encode($request['location']);

        $full_address = $request['user']['data']['default_address']['house'] . ', ' . $request['user']['data']['default_address']['address'];
        $newOrder->address = $full_address;

        //get restaurant charges
        $newOrder->restaurant_charge = $restaurant->restaurant_charges;

        $orderTotal = 0;
        foreach ($request['order'] as $oI) {
            $originalItem = Item::where('id', $oI['id'])->first();
            $orderTotal += ($originalItem->price * $oI['quantity']);

            if (isset($oI['selectedaddons'])) {
                foreach ($oI['selectedaddons'] as $selectedaddon) {
                    $addon = Addon::where('id', $selectedaddon['addon_id'])->first();
                    if ($addon) {
                        $orderTotal += $addon->price * $oI['quantity'];
                    }
                }
            }
        }

        if ($request->coupon) {
            $coupon = Coupon::where('code', strtoupper($request['coupon']['code']))->first();
            if ($coupon) {
                $newOrder->coupon_name = $request['coupon']['code'];
                if ($coupon->discount_type == 'PERCENTAGE') {
                    $orderTotal = $orderTotal - (($coupon->discount / 100) * $orderTotal);
                }
                if ($coupon->discount_type == 'AMOUNT') {
                    $orderTotal = $orderTotal - $coupon->discount;
                }
                $coupon->count = $coupon->count + 1;
                $coupon->save();
            }
        }

        if ($request->delivery_type == 1) {
            if ($restaurant->delivery_charge_type == 'DYNAMIC') {
                //get distance between user and restaurant,
                $distance = $this->getDistance($request['user']['data']['default_address']['latitude'], $request['user']['data']['default_address']['longitude'], $restaurant->latitude, $restaurant->longitude);

                if ($distance > $restaurant->base_delivery_distance) {
                    $extraDistance = $distance - $restaurant->base_delivery_distance;
                    $extraCharge = ($extraDistance / $restaurant->extra_delivery_distance) * $restaurant->extra_delivery_charge;
                    $dynamicDeliveryCharge = $restaurant->base_delivery_charge + $extraCharge;

                    if (config('settings.enDelChrRnd') == 'true') {
                        $dynamicDeliveryCharge = ceil($dynamicDeliveryCharge);
                    }

                    $newOrder->delivery_charge = $dynamicDeliveryCharge;
                    $orderTotal = $orderTotal + $dynamicDeliveryCharge;
                } else {
                    $newOrder->delivery_charge = $restaurant->base_delivery_charge;
                    $orderTotal = $orderTotal + $restaurant->base_delivery_charge;
                }

            } else {
                $newOrder->delivery_charge = $restaurant->delivery_charges;
                $orderTotal = $orderTotal + $restaurant->delivery_charges;
            }

        } else {
            $newOrder->delivery_charge = 0;

        }

        $orderTotal = $orderTotal + $restaurant->restaurant_charges;

        if (config('settings.taxApplicable') == 'true') {
            $newOrder->tax = config('settings.taxPercentage');
            $orderTotal = $orderTotal + (float) (((float) config('settings.taxPercentage') / 100) * $orderTotal);
        }

        //this is the final order total
        // return response()->json($orderTotal);

        if ($request['method'] === 'COD') {
            if ($request->partial_wallet == true) {
                //deduct all user amount and add
                $user = User::where('id', $request['user']['data']['id'])->first();
                $newOrder->payable = $orderTotal - $user->balanceFloat;
            }
            if ($request->partial_wallet == false) {
                $newOrder->payable = $orderTotal;
            }
        }

        $newOrder->total = $orderTotal;

        $newOrder->order_comment = $request['order_comment'];

        $newOrder->payment_mode = $request['method'];

        $newOrder->restaurant_id = $request['order'][0]['restaurant_id'];

        if ($request->delivery_type == 1) {
            //delivery
            $newOrder->delivery_type = 1;
        } else {
            //selfpickup
            $newOrder->delivery_type = 2;
        }

        //process stripe payment
        if ($request['method'] == 'STRIPE') {
            //successfuly received user token
            if ($request['payment_token']) {
                $gateway = Omnipay::create('Stripe');
                $gateway->setApiKey(config('settings.stripeSecretKey'));

                $token = $request['payment_token']['id'];

                if ($request->partial_wallet == true) {
                    $user = User::where('id', $request['user']['data']['id'])->first();
                    $stripeAmount = $orderTotal - $user->balanceFloat;
                } else {
                    $stripeAmount = $orderTotal;
                }

                $response = $gateway->purchase([
                    'amount' => sprintf('%0.2f', $stripeAmount),
                    'currency' => $restaurant->codigo_moneda, /*config('settings.currencyId'),*/
                    'token' => $token,
                    'metadata' => [
                        'OrderId' => $unique_order_id,
                        'Name' => $request['user']['data']['name'],
                        'Email' => $request['user']['data']['email'],
                    ],
                ])->send();

                if ($response->isSuccessful()) {
                    //when success then save order
                    $newOrder->save();

                    if ($request->partial_wallet == true) {
                        //deduct all user amount and add
                        $user = User::where('id', $request['user']['data']['id'])->first();
                        $user->withdraw($user->balanceFloat * 100, ['description' => $translationData->orderPartialPaymentWalletComment . $newOrder->unique_order_id]);
                    }

                    foreach ($request['order'] as $orderItem) {
                        $item = new Orderitem();
                        $item->order_id = $newOrder->id;
                        $item->item_id = $orderItem['id'];
                        $item->name = $orderItem['name'];
                        $item->quantity = $orderItem['quantity'];
                        $item->price = $orderItem['price'];
                        $item->save();

                        if (isset($orderItem['selectedaddons'])) {
                            foreach ($orderItem['selectedaddons'] as $selectedaddon) {
                                $addon = new OrderItemAddon();
                                $addon->orderitem_id = $item->id;
                                $addon->addon_category_name = $selectedaddon['addon_category_name'];
                                $addon->addon_name = $selectedaddon['addon_name'];
                                $addon->addon_price = $selectedaddon['price'];
                                $addon->save();
                            }
                        }
                    }

                    $gps_table = new GpsTable();
                    $gps_table->order_id = $newOrder->id;
                    $gps_table->save();

                    $response = [
                        'success' => true,
                        'data' => $newOrder,
                    ];

                    // Send SMS
                    if (!$restaurant->auto_acceptable) {
                        if (config('settings.smsRestaurantNotify') == 'true') {
                            $restaurant_id = $request['order'][0]['restaurant_id'];
                            $this->smsToRestaurant($restaurant_id, $orderTotal);
                        }
                    }

                    // END SMS

                    return response()->json($response);

                } elseif ($response->isRedirect()) {

                } else {
                  /*
                    $mensajes = [
                        'Your card was declined'=>'su tarjeta fue rechazada',
                        ''=>'',
                        ''=>'',
                        ''=>'',
                        ];*/
                    $response = [
                        'success' => false,
                        'data' => null,
                        'message' => $response->getMessage(),
                    ];

                    return response()->json($response);
                }
            }
        }

        //process paypal payment
        if ($request['method'] == 'PAYPAL' || $request['method'] == 'PAYSTACK' || $request['method'] == 'RAZORPAY') {
            //successfuly received payment
            $newOrder->save();
            if ($request->partial_wallet == true) {
                //deduct all user amount and add
                $user = User::where('id', $request['user']['data']['id'])->first();
                $user->withdraw($user->balanceFloat * 100, ['description' => $translationData->orderPartialPaymentWalletComment . $newOrder->unique_order_id]);
            }
            foreach ($request['order'] as $orderItem) {
                $item = new Orderitem();
                $item->order_id = $newOrder->id;
                $item->item_id = $orderItem['id'];
                $item->name = $orderItem['name'];
                $item->quantity = $orderItem['quantity'];
                $item->price = $orderItem['price'];
                $item->save();
                if (isset($orderItem['selectedaddons'])) {
                    foreach ($orderItem['selectedaddons'] as $selectedaddon) {
                        $addon = new OrderItemAddon();
                        $addon->orderitem_id = $item->id;
                        $addon->addon_category_name = $selectedaddon['addon_category_name'];
                        $addon->addon_name = $selectedaddon['addon_name'];
                        $addon->addon_price = $selectedaddon['price'];
                        $addon->save();
                    }
                }
            }

            $gps_table = new GpsTable();
            $gps_table->order_id = $newOrder->id;
            $gps_table->save();

            $response = [
                'success' => true,
                'data' => $newOrder,
            ];

            // Send SMS
            if (!$restaurant->auto_acceptable) {
                if (config('settings.smsRestaurantNotify') == 'true') {
                    $restaurant_id = $request['order'][0]['restaurant_id'];
                    $this->smsToRestaurant($restaurant_id, $orderTotal);
                }
            }
            // END SMS

            return response()->json($response);

        }
        //if new payment gateway is added, write elseif here
        else {
            $newOrder->save();
            if ($request['method'] === 'COD') {
                if ($request->partial_wallet == true) {
                    //deduct all user amount and add
                    $user = User::where('id', $request['user']['data']['id'])->first();
                    $user->withdraw($user->balanceFloat * 100, ['description' => $translationData->orderPartialPaymentWalletComment . $newOrder->unique_order_id]);
                }
            }

            //if method is WALLET, then deduct amount with appropriate description
            if ($request['method'] === 'WALLET') {
                $user = User::where('id', $request['user']['data']['id'])->first();
                $user->withdraw($orderTotal * 100, ['description' => $translationData->orderPaymentWalletComment . $newOrder->unique_order_id]);
            }

            foreach ($request['order'] as $orderItem) {
                $item = new Orderitem();
                $item->order_id = $newOrder->id;
                $item->item_id = $orderItem['id'];
                $item->name = $orderItem['name'];
                $item->quantity = $orderItem['quantity'];
                $item->price = $orderItem['price'];
                $item->save();
                if (isset($orderItem['selectedaddons'])) {
                    foreach ($orderItem['selectedaddons'] as $selectedaddon) {
                        $addon = new OrderItemAddon();
                        $addon->orderitem_id = $item->id;
                        $addon->addon_category_name = $selectedaddon['addon_category_name'];
                        $addon->addon_name = $selectedaddon['addon_name'];
                        $addon->addon_price = $selectedaddon['price'];
                        $addon->save();
                    }
                }
            }

            $gps_table = new GpsTable();
            $gps_table->order_id = $newOrder->id;
            $gps_table->save();

            $response = [
                'success' => true,
                'data' => $newOrder,
            ];

            // Send SMS
            if (!$restaurant->auto_acceptable) {
                if (config('settings.smsRestaurantNotify') == 'true') {
                    $restaurant_id = $request['order'][0]['restaurant_id'];
                    $this->smsToRestaurant($restaurant_id, $orderTotal);
                }
            }
            // END SMS

            return response()->json($response);
        }
    }

    /**
     * @param Request $request
     */
    public function getOrders(Request $request)
    {
        $orders = Order::where('user_id', $request->user_id)->with('orderitems', 'orderitems.order_item_addons')->orderBy('id', 'DESC')->get();
        return response()->json($orders);
    }

    /**
     * @param Request $request
     */
    public function getOrderItems(Request $request)
    {
        $items = Orderitem::where('order_id', $request->order_id)->get();
        return response()->json($items);

    }

    /**
     * @param Request $request
     */
    public function cancelOrder(Request $request, TranslationHelper $translationHelper)
    {

        $keys = ['orderRefundWalletComment', 'orderPartialRefundWalletComment'];

        $translationData = $translationHelper->getDefaultLanguageValuesForKeys($keys);

        $order = Order::where('id', $request->order_id)->first();

        $user = User::where('id', $request->user_id)->first();

        //check if user is cancelling their own order...
        if ($order->user_id == $request->user_id && $order->orderstatus_id == 1) {

            //if payment method is not COD, and order status is 1 (Order placed) then refund to wallet
            $refund = false;
            if ($order->orderstatus_id == 1 && $order->payment_mode != 'COD') {
                $user->deposit($order->total * 100, ['description' => $translationData->orderRefundWalletComment . $order->unique_order_id]);
                $refund = true;
            }

            if ($order->payment_mode == 'COD' || $order->payment_mode == 'STRIPE' || $order->payment_mode == 'PAYPAL' || $order->payment_mode == 'PAYSTACK' || $order->payment_mode == 'RAZORPAY') {
                //if payment is COD, check total and payable.. if there is change, pay the difference
                if ($order->total - $order->payable != 0) {
                    //that means the customer has used wallet to pay partially..
                    //initiate partial refund
                    $user->deposit(($order->total - $order->payable) * 100, ['description' => $translationData->orderPartialRefundWalletComment . $order->unique_order_id]);
                    $refund = true;
                }
            }

            //cancel order
            $order->orderstatus_id = 6; //6 means canceled..
            $order->save();

            //throw notification to user
            if (config('settings.enablePushNotificationOrders') == 'true') {
                $notify = new PushNotify();
                $notify->sendPushNotification('6', $order->user_id);
            }

            $response = [
                'success' => true,
                'refund' => $refund,
            ];

            return response()->json($response);

        } else {
            $response = [
                'success' => false,
                'refund' => false,
            ];
            return response()->json($response);
        }

    }

    /**
     * @param $latitudeFrom
     * @param $longitudeFrom
     * @param $latitudeTo
     * @param $longitudeTo
     * @return mixed
     */
    private function getDistance($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo)
    {
        $latFrom = deg2rad($latitudeFrom);
        $lonFrom = deg2rad($longitudeFrom);
        $latTo = deg2rad($latitudeTo);
        $lonTo = deg2rad($longitudeTo);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
            cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));
        return $angle * 6371;
    }

    /**
     * @param $restaurant_id
     * @param $orderTotal
     */
    private function smsToRestaurant($restaurant_id, $orderTotal)
    {
        //get restaurant
        $restaurant = Restaurant::where('id', $restaurant_id)->first();
        if ($restaurant) {
            if ($restaurant->is_notifiable) {
                //get all pivot users of restaurant (Store Ownerowners)
                $pivotUsers = $restaurant->users()
                    ->wherePivot('restaurant_id', $restaurant_id)
                    ->get();
                //filter only res owner and send notification.
                foreach ($pivotUsers as $pU) {
                    if ($pU->hasRole('Store Owner')) {
                        // Include Order orderTotal or not ?
                        switch (config('settings.smsRestOrderValue')) {
                            case 'true':
                                $message = config('settings.defaultSmsRestaurantMsg') . round($orderTotal);
                                break;
                            case 'false':
                                $message = config('settings.defaultSmsRestaurantMsg');
                                break;
                        }
                        // As its not an OTP based message Nulling OTP
                        $otp = null;
                        $smsnotify = new Sms();
                        $smsnotify->processSmsAction('OD_NOTIFY', $pU->phone, $otp, $message);
                    }
                }
            }
        }
    }
    /**
     * @param $restaurant_id
     */
    private function smsToDelivery($restaurant_id)
    {
        //get restaurant
        $restaurant = Restaurant::where('id', $restaurant_id)->first();
        if ($restaurant) {
            //get all pivot users of restaurant (Store Ownerowners)
            $pivotUsers = $restaurant->users()
                ->wherePivot('restaurant_id', $restaurant_id)
                ->get();
            //filter only res owner and send notification.
            foreach ($pivotUsers as $pU) {
                if ($pU->hasRole('Delivery Guy')) {
                    if ($pU->delivery_guy_detail->is_notifiable) {
                        $message = config('settings.defaultSmsDeliveryMsg');
                        // As its not an OTP based message Nulling OTP
                        $otp = null;
                        $smsnotify = new Sms();
                        $smsnotify->processSmsAction('OD_NOTIFY', $pU->phone, $otp, $message);
                    }
                }
            }
        }
    }
}
