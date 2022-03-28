<?php
namespace App\Http\Controllers;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
//use App\Models\Test;

class TestController extends Controller {
    public function index($id) {
        $resultado = DB::select('select * from test01 where id = :id', ['id' => $id]);
        echo "<pre>";
        print_r($resultado); // */
        /*$resultado = Test::all();
        return response()->json($resultado);// */
    }
    public function spPrueba() {
        
    }
}