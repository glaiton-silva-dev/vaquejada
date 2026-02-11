<?php
// index.php

// 1. SEGURANÇA: Token simples para impedir acesso não autorizado
$meuTokenSecreto = 'coloque_uma_senha_dificil_aqui_123';

// Verifica se o token veio na URL (ex: ?token=senha123)
if (!isset($_GET['token']) || $_GET['token'] !== $meuTokenSecreto) {
    http_response_code(403);
    die("Acesso negado.");
}

// 2. CONFIGURAÇÕES (Use Variáveis de Ambiente no Render para segurança)
// No Render, use o "Internal Hostname" do banco (ex: dpg-xxxx-a)
$dbHost = getenv('DB_HOST') ?: 'dpg-d3lbqr95pdvs73acpvo0-a'; 
$dbName = getenv('DB_NAME') ?: 'vaquejada_meob';
$dbUser = getenv('DB_USER') ?: 'vaquejada';
$dbPass = getenv('DB_PASS') ?: 'SUA_SENHA';
$dbPort = '5432';
$mpAccessToken = getenv('MP_TOKEN') ?: 'SEU_TOKEN_MP';

// ---------------- CONEXÃO ---------------- //
try {
    // No Render interno não precisa de SSL connection string complexa, 
    // mas o padrão pgsql funciona bem.
    $dsn = "pgsql:host=$dbHost;port=$dbPort;dbname=$dbName";
    
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    die("Erro DB: " . $e->getMessage());
}

// ---------------- LÓGICA (Mesma anterior) ---------------- //

echo "Iniciando verificação...<br>";

// ... (COLE AQUI A LÓGICA DO SCRIPT ANTERIOR: QUERY, FOREACH, CURL MP, UPDATES) ...
// DICA: Troque os "echo \n" por "echo <br>" para ler no navegador se precisar debug.

echo "Verificação concluída.";
?>
