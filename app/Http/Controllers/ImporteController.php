<?php
namespace App\Http\Controllers;

use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\ModelNotFoundException;


class ImporteController extends Controller {
    /**
     * Valida el formato de CBU.
     */
    private function validarCBU($numero) {
        $Peso = [3,1,7,9];
        $j = 0;
        $suma = 0;
        $digito = 0;

        // Verifica longitud
        If (strlen($numero) <> 22 )return false;
        // Verifica que son todo números
        if(!is_numeric($numero)) return false;
        // Verifica 8º Dígito
        for ($i = 6; $i >= 0; $i--) {
            $suma += intval($numero[$i]) * $Peso[($j % 4)];
            $j++;
        }
        $digito = (10 - ($suma % 10)) % 10;
        if($numero[7] != $digito) return false;
        // Verifica 22º Dígito
        $suma = 0;
        $j = 0;
        for($i = 20; $i >= 8; $i--) {
            $suma += intval($numero[$i]) * $Peso[($j % 4)];
            $j++;
        }
        $digito = (10 - ($suma % 10)) % 10;
        if($numero[21] != $digito) return false;
        //Si aprueba todas condiciones retorna verdadero.
        return true;
    } // fin validarCBU
    /**
     * spImporteOperacion. Devuelve El total disponible de una Operación.
     */
    public function spImporteOperacion(Request $req) {
        $oper = 0;
        $validator = Validator::make($req->all(), 
                ['operacion' => 'required|min:6|integer'],
                [
                    'operacion.required' => 'Es requerido un número de Operación',
                    'operacion.min' => 'El número de Operación debe tener mínimo 6 dígitos',
                    'operacion.integer'=>'Numero invalido, debe ser un entero.'
                ]
            );
        if ($validator->fails()) {
            return response()->json(array('success' => false, 'mensajes' => $validator->errors()), 400);
        }
        $oper = (int)$req->operacion;
        if (is_integer($oper) && $oper == $req->operacion) {  
            $resultado = DB::select('CALL sp_SaldoOperacion( ? );', array($oper));
            if (!is_array($resultado) || empty($resultado)) {
                return response()->json(array('success' => false, 'mensajes' =>  
                ['operacion' => ["La Operación $req->operacion no Existe."]]), 400);
            }
            return response()->json(array('success' => true, 'data' => $resultado[0]));
        }
        return response()->json(array('success' => false,'mensajes' =>  ['operacion' => "$req->operacion produjo un error no controlado."]), 400);
    }
    /**
     * spAcreditarTercero. Permite Acreditar a un Tercero en monto Total/parcial disponible
     * en la operación.
     */
    public function spSolicitarAcreditacion(Request $req) {
        $validator = Validator::make($req->all(), 
                [
                    'Operacion' => 'required|integer',
                    'CodAgencia' => 'required|integer',
                    'CUIT_DNI' => 'required|integer',
                    'TipoCuenta' => 'required',
                    'CBU' => 'required|min:22|max:22',
                    'Importe' => 'required',
                    'Usuario' => 'required'
                ],
                [
                    'Operacion.required' => 'Es requerido un número de Operación',
                    'Operacion.integer'=>'Numero inválido, debe ser un entero.',
                    'CodAgencia.required' => 'Es requerido un número de Agencia',
                    'CodAgencia.integer'=>'Numero invalido, debe ser un entero.',
                    'CUIT_DNI.required' => 'Es requerido un número de DNI/CUIL/CUIT, sin separadores ni espacios',
                    'CUIT_DNI.integer'=>'Numero invalido, El DNI/CUIL/CUIT debe ser un entero, sin separadores ni espacios.',
                    'TipoCuenta.required' => 'Es requerido el tipo de cuenta.',
                    'CBU.required' => 'Es requerido un número de CBU.',
                    'CBU.*' => 'El CBU debe tener 22 dígitos.',
                    'Importe.required'=>'Es requerido el Importe para la transferencia.',
                    'Usuario.required'=>'Es requerido el Usuario que realiza la operación.'
                ]
            );
        if ($validator->fails()) {
            return response()->json(array('success' => false, 'mensajes' => $validator->errors()), 400);
        }
        $Operacion = $req->Operacion;
        $codAgencia = $req->CodAgencia;
        $cobDocNumero = str_replace(array(".", "-", ",", "/", " "), '', $req->CUIT_DNI);
        $cobNombre = $req->Nombre;
        $cobApellido = $req->Apellido;
        //$CuitCuil = str_replace(array(".", "-", ",", "/", " "), '', $req->CUIT_CUIL);
        $tipoDoc = $req->TipoDoc;
        //$tipoResponsable = $req->TipoResponsable;
        /*$calle = $req->Calle;
        $altura = $req->Altura;
        $cp = $req->CP;
        $provincia = $req->Provincia;
        $sibId = $req->SibID;
        $nroSituacionIB = $req->NroSituacionIB;*/
        $CBU = $req->CBU;
        $tipoCuenta = $req->TipoCuenta;
        $importe = $req->Importe;
        //$retorno = $req->Retorno;

        $usuarioAlta = !is_null($req->Usuario)? $req->Usuario : 'Admin';
        $fchLiquidacion = date("Y-m-d H:i:s");
        //Validar Datos
        switch (strtoupper($tipoCuenta)) {
            case 'CA': $tipoCuenta = 1; break;
            case 'CC': $tipoCuenta = 2; break;
            default:
                return response()->json(array('success' => false,
                    'mensajes' => "#001. Tipo de Cuenta inválido ('CA'/'CC')."), 405);
                break;
        }
        if (!$this->validarCBU($CBU)) {
            return response()->json(array('success' => false,
                                          'mensaje' => "#002. Número de CBU $CBU inválido."), 405);
        };
        
        $dato = array();
        
        /*$dato = DB::select('Call sp_CobradorSolicitud(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, @_numero);',
        [$cobDocNumero, $codAgencia, $cobNombre, $cobApellido, $CuitCuil, $tipoDoc, $usuarioAlta, 
        $tipoResponsable, $calle, $altura, $cp, $provincia, $sibId, $nroSituacionIB]);
        $idCob = DB::select('Select @_numero;');
        if ($idCob = -2) {// DB::rollBack();
            DB::select('ROLLBACK;');
            return response()->json(array('success' => false, 
                    'mensaje' => "#003. El Cobrador $cobDocNumero no corresponde con el código de Agencia $codAgencia."), 405);
        }*/
        try {
            DB::beginTransaction();
            //DB::select('START TRANSACTION;');
            DB::select('SET @_numero = 0;');
            $dato = DB::select('Call sp_ABM_SolicitudAcreditacion_Agencia("A", 0, ?, ?, ?, ?, ?, ?, ?, @_numero)',
            [$Operacion, $codAgencia, $cobDocNumero, $CBU, $tipoCuenta, $importe, $usuarioAlta]);
            //$cobNombre = $req->Nombre;
            //$cobApellido = $req->Apellido;
            //$tipoDoc = $req->TipoDoc;
            $idCob = DB::select('Select @_numero numero;');
            if(empty($idCob)) {  DB::rollBack();
                // DB::select('ROLLBACK;');
                return response()->json(array('success' => false, 
                        'mensajes' => "#003. No se obtuvo resultado de la Base de Datos al Agregar la Solicitud de Acreditación."), 405);
            }
        }
        catch (Exception $e) {
            DB::select('ROLLBACK;');
            return response()->json(array('success' => false, 
                    'mensajes' => "#004. No se obtuvo resultado de la Base de Datos al Agregar la Solicitud de Acreditación. {$e->getMessage()}"), 405);
        }
        
        DB::select('COMMIT;');
        //Retornar resultado
        return response()->json(["success"=>true,  
                                "mensaje"=> "Se realizó exitosamente la transacción",
                                'Tracking' => [                             
                                    "SolicitudID" => $idCob[0]->numero,
                                    "CBU" => $CBU
                                ]], 201);
    }
}