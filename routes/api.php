<?php

use App\Http\Controllers\ExtrairEnderecoController;
use App\Http\Controllers\ExtrairImovelController;
use App\Http\Controllers\ImovelStagingController;
use App\Http\Controllers\ImovelStagingFotoController;
use Illuminate\Support\Facades\Route;

Route::post('/imoveis-staging', [ImovelStagingController::class, 'store']);
Route::put('/imoveis-staging/{imovelStaging}', [ImovelStagingController::class, 'update']);
Route::post('/imoveis-staging/{imovelStaging}/analisar-fotos', [ImovelStagingController::class, 'analisarFotos']);
Route::post('/imoveis-staging/{imovelStaging}/finalizar', [ImovelStagingController::class, 'finalizar']);
Route::post('/imoveis-staging/{imovelStaging}/enriquecer-localizacao', [ImovelStagingController::class, 'enriquecerLocalizacao']);
Route::put('/imoveis-staging/{imovelStaging}/foto-capa', [ImovelStagingController::class, 'selecionarFotoCapa']);

Route::post('/imoveis-staging/{imovelStaging}/fotos', [ImovelStagingFotoController::class, 'store']);
Route::delete('/imoveis-staging/{imovelStaging}/fotos/{foto}', [ImovelStagingFotoController::class, 'destroy']);

Route::post('/extrair-imovel', ExtrairImovelController::class);
Route::post('/extrair-endereco', ExtrairEnderecoController::class);
