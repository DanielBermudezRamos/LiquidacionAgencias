<?php
namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\ModelNotFoundException;


class ImporteController extends Controller {
    
    public function index($id) {
        DB::select('SET @Devuelve;');
        DB::select('CALL sp_UnRegistro(?, @Devuelve);',[$id]);
        $resultado = DB::select('Select @Devuelve as Names');
        return response()->json(['Identificador'=>$id, 'nombre'=> $resultado[0]->Names]);
    }
    private function toJSON($array) { return json_encode($array);}
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
                return response()->json(array('success' => false,'mensajes' =>  ['operacion' => ["La Operación $req->operacion no Existe."]]), 400);        
            }
            return response()->json(array('success' => true, 'data' => $resultado[0]));
        }
        return response()->json(array('success' => false,'mensajes' =>  ['operacion' => "$req->operacion produjo un error no controlado."]), 400);
    }
    /**
     * spAcreditarTercero. Permite Acreditar a un Tercero en monto Total/parcial disponible
     * en la operación.
     */
    public function spAcreditarTransferencia(Request $req) {
        $validator = Validator::make($req->all(), 
                [
                    'Operacion' => 'required|integer',
                    'CodAgencia' => 'required|integer',
                    'CUIT' => 'required|integer',
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
                    'CUIT.required' => 'Es requerido un número de DNI/CUIL/CUIT, sin separadores ni espacios',
                    'CUIT.integer'=>'Numero invalido, El DNI/CUIL/CUIT debe ser un entero, sin separadores ni espacios.',
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
        $cobDocNumero = str_replace(array(".", "-", ",", "/", " "), '', $req->CUIT);
        $tipoCuenta = $req->TipoCuenta;
        $CBU = $req->CBU;
        $importe = $req->Importe;
        $usuarioAlta = !is_null($req->Usuario)? $req->Usuario : 'Admin';
        $fchLiquidacion = date("Y-m-d H:i:s");
        $idChequera = 1321; // <---- Asignación Fija para Transferencias
        $cobNombre = "";
        $cobApellido = "";
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
                                          'mensaje' => "#001. Número de CBU $CBU inválido."), 405);
        };
        
        $dato = array();
        //
        $dato = DB::select("SELECT c.Codigo, u.OdP FROM Creditos c LEFT JOIN (
            SELECT Operacion, SUM(Numero) OdP FROM OrdenesDePago o GROUP BY o.Operacion
            UNION ALL
            SELECT Operacion, SUM(comprobanteNum) FROM BilleteraVirtual bv GROUP BY bv.Operacion
            ) u ON c.Codigo = u.Operacion Where c.Codigo = ? ;",[$Operacion]);
        
        if(!is_array($dato)  || empty($dato)) {
            // DB::rollBack();
            return response()->json(array('success' => false, 
                    'mensajes' => array('CUIT' => ["#002. No se encuentra la Operación Nro $Operacion."])), 405);
        }elseif($Operacion != $dato[0]->Codigo) {
            return response()->json(array('success' => false, 
                    'mensajes' => array('CUIT' => ["#002. No se encuentra la Operación Nro $Operacion."])), 405);
        }elseif(empty($dato[0]->Codigo)) {
            return response()->json(array('success' => false, 
                    'mensajes' => array('CUIT' => ["#002. La Operación Nro $Operacion no tiene Orden de Pago Asociada."])), 405);
        }
        $OPR = $dato[0]->OdP;
        //
        $dato = DB::select('SELECT ID, Nombre, Apellido FROM Cobradores WHERE Agencia = ? AND CuitDni = ? ;',[$codAgencia, $cobDocNumero]);
        
        if(!is_array($dato)  || empty($dato)) {
            // DB::rollBack();
            return response()->json(array('success' => false, 
                    'mensajes' => array('CUIT' => ["#002. No se obtuvo resultado de la Base de Datos. El Cobrador $cobDocNumero no está registrado o no corresponde con el código de Agencia $codAgencia."])), 405);
        }
        $cobrador = $dato[0]->ID;
        $cobNombre = $dato[0]->Nombre;
        $cobApellido = $dato[0]->Apellido;
        //Procesar Petición
        DB::select('Set @NumChequeId = 0;');
        DB::select('Set @resultado = 0;');
        $dato = DB::select('select ch.ID ChequeraId, bc.`Codigo` BcContaId, bc.Cuenta, bc.`CuentaChequeDiferido` 
        from CONTA_Chequeras ch inner join CONTA_Bancos bc on ch.`Banco` = bc.`Codigo` where ch.`ID` = ?', [$idChequera]);
        if(!is_array($dato)) {
            // DB::rollBack();
            return response()->json(array('success' => false, 
                    'mensajes' => ['CUIT' => ["#002. No se obtuvo resultado de la Base de Datos."]]), 405);
        }
        $ctaBV = 1110225;
        $Cuenta = $dato[0]->Cuenta;
        $BcContaId = $dato[0]->BcContaId;
        $CtaChDiferido = $dato[0]->CuentaChequeDiferido;
        //Cargamos en nuevo número de Cheque para registrar la Transferencia
        //DB::beginTransaction();
        DB::select('START TRANSACTION;');
        DB::select('SET @_numero = 0;');
        DB::select('CALL sp_GetProxNumTransf_ChequeraID(?, @_numero);', array($idChequera));
        if (!$resultado = DB::select('SELECT @_numero AS NumCheq;')) {
            DB::select('ROLLBACK;');//DB::rollBack();
            return response()->json(array('success' => false, 'mensaje' => "#003. No se obtuvo resultado de la Base de Datos."), 405)[0];
        }
        $nroCheque = $resultado[0]->NumCheq;
        
        $GrabadoSolic = $this->GrabarSolicitud($codAgencia, $usuarioAlta, $importe, $idChequera, $cobrador, $nroCheque, $fchLiquidacion);
        if(!is_array($GrabadoSolic)) {
            DB::select('ROLLBACK;');//DB::rollBack();
            return response()->json(array($GrabadoSolic));
        }
        elseif(!$GrabadoSolic['success']) {
            DB::select('ROLLBACK;');//DB::rollBack();
            return response()->json(array('success' => false, 'mensaje' => '#006. '. $GrabadoSolic['mensaje']))[0];
        }
        $OPR = $GrabadoSolic['NumSolicitud'];
        //Cargamos el nuevo número de Asiento para el registro contable.
        DB::select('Set @NumAsiento = 0'); 
        DB::select('CALL CONTA_GetNumeroAsiento( @NumAsiento)');
        $dato = DB::select('Select @NumAsiento As Numero');
        if(!is_array($dato)) {
            DB::select('ROLLBACK;');//DB::rollBack();
            return response()->json(array('success' => false, 'mensaje' => "#007. No se obtuvo resultado de la Base de Datos."), 405)[0];
        }
        $NroAsiento = $dato[0]->Numero;
        $GrabadoTransf = $this->GrabarTransferencia($OPR, $cobNombre, $cobApellido, $cobDocNumero, $tipoCuenta, $CBU, $importe, $nroCheque, $usuarioAlta, $fchLiquidacion, $BcContaId);
        if(!$GrabadoTransf['success']) {
            DB::select('ROLLBACK;');//DB::rollBack();
            return response()->json(array('success' => false, 'mensaje' => "#009. ".$GrabadoTransf['mensaje']), 405)[0];
        }
        $nroTrans = $GrabadoTransf['NumTrans'];
        $GrabadoMovBco = $this->GrabarMovBanco($OPR, $nroTrans, $fchLiquidacion, $importe, $codAgencia,$BcContaId, 1110225, $CtaChDiferido, $NroAsiento, $idChequera, $usuarioAlta);
        if(!$GrabadoMovBco['success']) {
            DB::select('ROLLBACK;');//DB::rollBack();
            return response()->json(array('success' => false, 'mensaje' => "#011. ".$GrabadoMovBco['mensaje']), 405)[0];
        }                
        $GrabadoMovConta = $this->GrabarMovContable($OPR,$nroTrans,$fchLiquidacion,$importe,$codAgencia,$BcContaId,$Cuenta,$ctaBV,$NroAsiento, $idChequera, $usuarioAlta);
        if(!$GrabadoMovConta['success']) {
            DB::select('ROLLBACK;');//DB::rollBack();
            return response()->json(array('success' => false, 'mensaje' => "#014. ".$GrabadoMovConta['mensaje']), 405)[0];
        }
        DB::select('COMMIT;');
        //Retornar resultado
        return response()->json(["success"=>true,  
                                "mensaje"=>"Se realizó exitosamente la transacción",
                                'Tracking' => [
                                    'ID_Transferencia'=>"$nroTrans",
                                    'Nro_Transferencia'=>"Nro de Transferencia: $nroCheque",
                                    'OPR'=>"Orden de Pago: $OPR",
                                    'NroAsiento'=>"Número de Asiento: $NroAsiento",
                                    'MovBanco'=>"Movimiento Bancario {$GrabadoMovBco['movBcoId']}",
                                    'MovContaH'=>"Movimiento Contable Haber {$GrabadoMovConta['MovContaH']}",
                                    'MovContaD'=>"Movimiento Contable Debe {$GrabadoMovConta['MovContaD']}"
                                ]], 201);
    }
    /**
     * GrabarSolicitud. Graba en la DB la solicitud de Acreditación.
     */
    private function GrabarSolicitud($codAgencia, $usuario, $importe, $idChequera, $cobrador, $nroTrans, $fchLiquidacion) {
        try {
            DB::Select("SET @resultado = 0;");
            DB::select("CALL sp_SolicitudLiquidacionBV_Insertar( ?, ?, ?, 4, 0, ?, ?, ?, 0, ?, @resultado);",
                        array($codAgencia, $usuario, $importe, $idChequera, $cobrador, $nroTrans, $fchLiquidacion));
            $resultado = DB::select("SELECT @resultado AS solicId");
            if (!is_array($resultado)) {
                DB::select('ROLLBACK;');//DB::rollBack();
                return array (
                    'success' => false,
                    'mensaje' => "#004. Error al Agregar una nueva solicitud de Transferencia.\n".$php_errormsg);
            }
            //Retorna resultado Exitoso
            return array(
                'success'=> true,
                'mensaje' => "Registrado exitosamente Solicitud de Trans.",
                'NumSolicitud' => $resultado[0]->solicId
            );
        } catch (ModelNotFoundException $ex) {
            DB::select('ROLLBACK;');
            return array(
                'success' => false,
                'mensaje' => '#005. ' . $ex->getMessage());
        }
    }
    /**
     * GrabarTransferencia. Guarda la Operación.
     */
    private function GrabarTransferencia($OPR, $cobNombre, $cobApellido, $cobDocNumero, $tipoCuenta, $CBU, $importe, $nroCheque, 
                                            $usuarioAlta, $fchLiquidacion, $BcContaId) {
        $NombreCompleto = $cobNombre . ' ' . $cobApellido;
        DB::select('Set @resultado = 0;');
        DB::select("CALL sp_p_transferencias_abm('A', null, null, ?, ?, ?, ?, ?, ?, ?, 1, ?, null, 10, ?, ?, null, @resultado);", 
                    array($OPR, $NombreCompleto, $cobDocNumero, $tipoCuenta, $CBU, $importe, $nroCheque, $usuarioAlta, 
                            $fchLiquidacion, $BcContaId));
        $resultado = DB::select("Select @resultado As TrasfId");         
        if(!is_array($resultado)) {
            DB::select('ROLLBACK;');//DB::rollBack();
            return array('success'=> false,
                         'error' => "#008. No se obtuvo resultado de la Base de Datos.");
        }
        return array (
            'success'=> true,
            'mensaje' => "Registrado exitosamente Grabado de Transferencia.",
            'NumTrans' => $resultado[0]->TrasfId);
    }
    /**
     * 
     */
    private function GrabarMovBanco($OPR,$nroCheque,$fchLiquidacion,$importe,$codAgencia,$BcContaId,$Cuenta, $CtaChDiferido, $NroAsiento, $idChequera, $usuarioAlta) {
        DB::select("SET @resultado = 0");
        DB::select("SET @retorno = 0");
        $FchEmision = Carbon::createFromFormat('Y-m-d H:i:s', $fchLiquidacion)->format('Ymd');
        $concepto = "Liquidacion BV Agencia $codAgencia";
        DB::select("CALL sp_movimientobanco_Abm('A', @resultado, ?, null, ?, ?, ?, ?, ?, 0, null, null, null, ?, null, ?, 1, ?, ?, null, null, null, ?, null, null, ?, 11, null, null, @retorno);",
        [$FchEmision, $nroCheque, $importe, $BcContaId, $Cuenta, $concepto, $OPR, $idChequera, $CtaChDiferido, $Cuenta,$usuarioAlta, $NroAsiento]);
        $resultado = DB::select("Select @resultado As movBcoId");
        if (!is_array($resultado)) {
            DB::select('ROLLBACK;');//DB::rollBack();
            return array('success'=> false,'mensaje' => "#010. Falla al obtener el resultado: ");
        }
        return array (
            'success'=> true,
            'mensaje' => "Registrado exitosamente Movimiento de Banco",
            'movBcoId' => $resultado[0]->movBcoId);
    }   // end GrabarMovBanco
    /**
     * 
     */
    public static function GrabarMovContable($OPR,$nroCheque,$fchLiquidacion,$importe,$codAgencia,$BcContaId,$Cuenta,$ctaBV,$NroAsiento, $idChequera, $usuarioAlta) {
        //
        $strOPR = str_pad($OPR, 12, "0", STR_PAD_LEFT);
        $Fech = Carbon::createFromFormat('Y-m-d H:i:s', $fchLiquidacion)->format('Ymd');
        $concepto = "Solicitud de Liquidacion. Agencia Nro. $codAgencia";
        DB::select("SET @resultado = 0");
        DB::select("SET @retorno = 0");
        DB::select("CALL CONTA_ABMMovConta('A', null, ?, ?, ?, 'H', ?, ?, 'OPB', ?,
                        null, null, null, ?, null, null, ?, ?, null, null, @retorno, @resultado)",
                    [$Fech, $NroAsiento, $Cuenta, $importe, $concepto, $strOPR, $usuarioAlta,$nroCheque,$BcContaId]);
        $resultado = DB::select("SELECT @resultado as Result_H");
        if (!is_array($resultado)) {
            DB::select('ROLLBACK;');//DB::rollBack();
            return array ('success'=> false,'mensaje' => "#012. Falla al Grabar el 'HABER'");
        }
        $datoH = $resultado[0]->Result_H;
        $concepto = "Solicitud de Liquidacion. Agencia Nro. $codAgencia";
        DB::select("SET @resultado = 0");
        DB::select("SET @retorno = 0");
        DB::select("CALL CONTA_ABMMovConta('A', null, ?, ?, ?, 'D', ?, ?, 'OPB', ?, null, null, null, ?, null, null, ?, ?, null, null, @retorno, @resultado)",
                    [$Fech, $NroAsiento, $ctaBV, $importe, $concepto, $strOPR, $usuarioAlta, $nroCheque, $BcContaId]);
        $resultado = DB::select("SELECT @resultado as Result_D");
        if (!is_array($resultado)) {
            DB::select('ROLLBACK;');//DB::rollBack();
            return array ('success'=> false,'error' => "#013. Falla al Grabar el 'DEBE' ");
        }
        $datoD = $resultado[0]->Result_D;
        return array (
            'success'=> true,
            'mensaje' => "Registrado exitosamente Movimiento Contable", 
            'MovContaD' => $datoD, 
            'MovContaH' => $datoH
        );
    }  // end GrabarMovBanco
}