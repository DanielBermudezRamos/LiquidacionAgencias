<?php

/** @var \Laravel\Lumen\Routing\Router $router */

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It is a breeze. Simply tell Lumen the URIs it should respond to
| and give it the Closure to call when that URI is requested.
|
*/
// EndPoint 1) Importe a Acreditar [GET]

// EndPoint 2) Acreditar [POST]


$router->get('/', function () use ($router) {
    return $router->app->version();
});
$router->get('hola', function () use ($router) {
    return "<h1>Hola <strong>Mundo</strong></h1>";
});
$router->get('saldoLiquidar', 'ImporteController@spImporteOperacion');
$router->post('acreditar', 'ImporteController@spAcreditarTransferencia');
$router->get('/prueba/{id}', 'TestController@index');