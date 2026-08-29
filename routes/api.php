<?php

use App\Http\Controllers\ExtrairEnderecoController;
use App\Http\Controllers\ExtrairImovelController;
use App\Http\Controllers\ImovelStagingController;
use App\Http\Controllers\ImovelStagingFotoController;
use App\Http\Controllers\ImovelStagingFotoEdicaoController;
use App\Http\Controllers\ImovelStagingPacoteController;
use Illuminate\Support\Facades\Route;

Route::post('/imoveis-staging', [ImovelStagingController::class, 'store']);
Route::put('/imoveis-staging/{imovelStaging}', [ImovelStagingController::class, 'update']);
Route::post('/imoveis-staging/{imovelStaging}/analisar-fotos', [ImovelStagingController::class, 'analisarFotos']);
Route::post('/imoveis-staging/{imovelStaging}/gerar-titulo-descricao', [ImovelStagingController::class, 'gerarTituloDescricao']);
Route::get('/imoveis-staging/{imovelStaging}/status-descricao', [ImovelStagingController::class, 'statusDescricao']);
Route::post('/imoveis-staging/{imovelStaging}/validar', [ImovelStagingController::class, 'validarQualidade']);
Route::post('/imoveis-staging/{imovelStaging}/confirmar-pendencia', [ImovelStagingController::class, 'confirmarPendencia']);
Route::post('/imoveis-staging/{imovelStaging}/finalizar', [ImovelStagingController::class, 'finalizar']);
Route::post('/imoveis-staging/{imovelStaging}/enriquecer-localizacao', [ImovelStagingController::class, 'enriquecerLocalizacao']);
Route::put('/imoveis-staging/{imovelStaging}/foto-capa', [ImovelStagingController::class, 'selecionarFotoCapa']);

Route::post('/imoveis-staging/{imovelStaging}/fotos', [ImovelStagingFotoController::class, 'store']);
Route::delete('/imoveis-staging/{imovelStaging}/fotos/{foto}', [ImovelStagingFotoController::class, 'destroy']);

Route::get('/imoveis-staging/{imovelStaging}/fotos/{foto}/itens-removiveis', [ImovelStagingFotoEdicaoController::class, 'itensRemoviveis']);
Route::get('/imoveis-staging/{imovelStaging}/fotos/{foto}/edicoes', [ImovelStagingFotoEdicaoController::class, 'index']);
Route::post('/imoveis-staging/{imovelStaging}/fotos/{foto}/edicoes', [ImovelStagingFotoEdicaoController::class, 'store']);
Route::get('/imoveis-staging/{imovelStaging}/fotos/{foto}/edicoes/{edicao}', [ImovelStagingFotoEdicaoController::class, 'show']);
Route::post('/imoveis-staging/{imovelStaging}/fotos/{foto}/edicoes/{edicao}/aprovar', [ImovelStagingFotoEdicaoController::class, 'aprovar']);
Route::post('/imoveis-staging/{imovelStaging}/fotos/{foto}/edicoes/{edicao}/rejeitar', [ImovelStagingFotoEdicaoController::class, 'rejeitar']);
Route::delete('/imoveis-staging/{imovelStaging}/fotos/{foto}/edicao-ativa', [ImovelStagingFotoEdicaoController::class, 'desativarEdicaoAtiva']);

Route::get('/imoveis-staging/{imovelStaging}/pacote-prontos', [ImovelStagingPacoteController::class, 'dados']);
Route::get('/imoveis-staging/{imovelStaging}/pacote-prontos.zip', [ImovelStagingPacoteController::class, 'zip']);

Route::post('/extrair-imovel', ExtrairImovelController::class);
Route::post('/extrair-endereco', ExtrairEnderecoController::class);
