<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Auth;
class ReportesController extends Controller
{

    public function deliveryguy(Request $req)
    {
        $userId = Auth::user()->id;
        if (!$req->has('desde') || !$req->has('hasta')) {
            $desde = date('Y-m-d', strtotime('-1 days'));
            $hasta = date('Y-m-d');
        } else {
            $desde = $req->desde;
            $hasta = $req->hasta;
        }
        
        $query = "SELECT
            accept_deliveries.user_id,
            users.`name`,
            Sum(orders.delivery_charge) AS delivery,
            Sum(orders.total) - Sum(orders.delivery_charge) AS neto_vendedor,
            Sum(orders.total) AS suma_total,
            orders.payment_mode as tipo_pago,
            accept_deliveries.created_at AS fecha
            FROM accept_deliveries
            INNER JOIN orders ON accept_deliveries.order_id = orders.id INNER JOIN users ON accept_deliveries.user_id = users.id
            WHERE accept_deliveries.is_complete = 1 and users.ref = '$userId' and accept_deliveries.created_at > '$desde' and  accept_deliveries.created_at < '$hasta' GROUP BY users.`name`";
       
        $datos = DB::select($query);
        
        return view('admin.reportes.deliveryGuy', ['reporte' => $datos]);
    }

    public function selectguy(Request $req)
    {
        $guy = $req->input('usuario');
        if (!$req->has('usuario')) {
            return redirect()->back()->with(['error' => 'debe seleccionar un delivery para mostrar la data']);
        }
        $query = "SELECT
            accept_deliveries.user_id,
            accept_deliveries.is_complete,
            users.`name`,
            orders.delivery_charge as delivery,
            orders.total - orders.delivery_charge as neto_vendedor,
            (orders.total - orders.delivery_charge)*0.10/100 as vendedor_10,
            orders.payment_mode as tipo_pago,
            orders.total,
            accept_deliveries.created_at as fecha
            FROM accept_deliveries
            INNER JOIN orders ON accept_deliveries.order_id = orders.id INNER JOIN users ON accept_deliveries.user_id = users.id
            WHERE accept_deliveries.is_complete = 1 and users.`name` = '$guy'";
        $datos = DB::select($query);

        if (count($datos) == 0) {
            return redirect()->back()->with(['warning' => 'no hay datos para mostrar']);
        }

        return $datos;
    }

}
