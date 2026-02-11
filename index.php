<?php
// index.php

// ==================================================================
// 1. SEGURANÇA E CONFIGURAÇÃO
// ==================================================================

// Token de segurança
$meuTokenSecreto = getenv('CRON_SECRET') ?: 'coloque_uma_senha_dificil_aqui_123';

if (!isset($_GET['token']) || $_GET['token'] !== $meuTokenSecreto) {
    http_response_code(403);
    die("Acesso negado: Token inválido.");
}

// --- DADOS DO BANCO ---
$dbHost = getenv('DB_HOST') ?: 'dpg-d3lbqr95pdvs73acpvo0-a.oregon-postgres.render.com';
$dbName = getenv('DB_NAME') ?: 'vaquejada_meob';
$dbUser = getenv('DB_USER') ?: 'vaquejada';
$dbPass = getenv('DB_PASS') ?: 'SUA_SENHA_REAL_AQUI';
$dbPort = '5432';

// Token do Mercado Pago
$mpAccessToken = getenv('MP_TOKEN') ?: 'SEU_TOKEN_MP';

// Tempo limite (10 minutos)
$timeoutMinutes = 10;

// ==================================================================
// 2. CONEXÃO COM O BANCO DE DADOS
// ==================================================================

try {
    // CORREÇÃO CRÍTICA AQUI: Adicionado ";sslmode=require"
    $dsn = "pgsql:host=$dbHost;port=$dbPort;dbname=$dbName;sslmode=require";
    
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 10
    ]);

    // Linha de teste (pode comentar depois)
    // echo "Conectado com sucesso ao banco!<br>"; 

} catch (PDOException $e) {
    http_response_code(500);
    // Exibe o erro completo para facilitar o debug
    die("Erro Crítico na Conexão DB: " . $e->getMessage());
}

// ==================================================================
// 3. LÓGICA DE PROCESSAMENTO
// ==================================================================

echo "<h3>[" . date('Y-m-d H:i:s') . "] Iniciando verificação de pagamentos...</h3>";

// 1. QUERY AJUSTADA:
// Trazemos "mpPreferenceId" para poder buscar caso falte o "mpPaymentId"
$sql = 'SELECT id, "subscriptionId", "mpPaymentId", "mpPreferenceId", "createdAt", "externalReference" 
        FROM payments 
        WHERE status = \'pending\' AND id = \'4c0809b5-a501-4926-a75a-54d3d6efd58b\''; 

try {
    $stmt = $pdo->query($sql);
    $payments = $stmt->fetchAll();
} catch (Exception $e) {
    die("Erro ao buscar pagamentos: " . $e->getMessage());
}

if (count($payments) === 0) {
    echo "Nenhum pagamento pendente encontrado.<br>";
    echo "Verificação concluída.";
    exit;
}

foreach ($payments as $payment) {
    $paymentId = $payment['id'];
    
    // CORREÇÃO NAS CHAVES DO ARRAY:
    $subId = $payment['subscriptionId']; 
    $mpId = $payment['mpPaymentId'];        // Pode vir NULL ou Vazio
    $mpPrefId = $payment['mpPreferenceId']; // Usado para buscar se mpId for nulo
    $externalReference = $payment['externalReference'];
    $createdAtVal = $payment['createdAt']; 
    
    // Tratamento de Datas
    $createdAt = new DateTime($createdAtVal);
    $now = new DateTime();
    $diffMinutes = ($now->getTimestamp() - $createdAt->getTimestamp()) / 60;

    echo "<strong>Processando Pagamento ID:</strong> $paymentId <br>";
    echo "&nbsp;&nbsp;- Tempo decorrido: " . round($diffMinutes, 1) . " min.<br>";

    // ==================================================================
    // NOVA LÓGICA: RECUPERAR ID PELA PREFERÊNCIA
    // ==================================================================
    if (empty($mpId) && !empty($mpPrefId)) {
        echo "&nbsp;&nbsp;- ID do Pagamento vazio. Buscando pela preferência ($mpPrefId)...<br>";
        
        $foundPayment = searchPaymentIdByPreference($externalReference, $mpAccessToken);
        
        if ($foundPayment) {
            $mpId = $foundPayment['id']; // Recuperamos o ID!
            $statusEncontrado = $foundPayment['status'];
            
            echo "&nbsp;&nbsp;- <span style='color:purple'>ENCONTRADO: ID $mpId (Status: $statusEncontrado)</span><br>";
            
            // Atualiza o banco imediatamente para não precisar buscar de novo na próxima
            try {
                $stmtUpd = $pdo->prepare('UPDATE payments SET "mpPaymentId" = ? WHERE id = ?');
                $stmtUpd->execute([$mpId, $paymentId]);
            } catch (Exception $e) {
                echo "&nbsp;&nbsp;- Erro ao salvar ID recuperado: " . $e->getMessage() . "<br>";
            }
        } else {
            echo "&nbsp;&nbsp;- Nenhum pagamento encontrado para esta preferência ainda.<br>";
        }
    }

    // --- CASO 2: Verificar status no Mercado Pago ---
    if (!empty($mpId)) {
        $mpStatus = getMercadoPagoStatus($mpId, $mpAccessToken);

        if ($mpStatus) {
            echo "&nbsp;&nbsp;- Status no MP: <b>$mpStatus</b><br>";

            if ($mpStatus === 'approved') {
                markAsApproved($pdo, $paymentId, $subId, $mpId);
            } 
            elseif (in_array($mpStatus, ['rejected', 'cancelled', 'expired'])) {
                // Mapeia para o enum do seu banco
                $localStatus = ($mpStatus === 'rejected') ? 'rejected' : 'cancelled';
                markAsClosed($pdo, $paymentId, $subId, $localStatus);
            }
        } else {
            echo "&nbsp;&nbsp;- <span style='color:orange'>Não foi possível obter status do MP.</span><br>";
        }
    } else {
      echo "&nbsp;&nbsp;- Sem ID do MP, aguardando cliente pagar...<br>";  
    }
    echo "<hr>";
}

echo "<h4>Verificação concluída com sucesso.</h4>";

// ==================================================================
// 4. FUNÇÕES AUXILIARES
// ==================================================================

function searchPaymentIdByPreference($preferenceId, $token) {
    $url = "https://api.mercadopago.com/v1/payments/search?external_reference=$preferenceId&sort=date_created&criteria=desc&limit=1";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $token",
        "Content-Type: application/json"
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode == 200) {
        $data = json_decode($response, true);
        if (!empty($data['results']) && isset($data['results'][0]['id'])) {
            return $data['results'][0];
        }
    }
    return null;
}

function getMercadoPagoStatus($mpPaymentId, $token) {
    $url = "https://api.mercadopago.com/v1/payments/$mpPaymentId";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); 
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $token",
        "Content-Type: application/json"
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode == 200) {
        $data = json_decode($response, true);
        return strtolower($data['status'] ?? '');
    }
    
    return null;
}

/**
 * Marca como Aprovado
 */
function markAsApproved($pdo, $paymentId, $subId, $mpPaymentId) {
    try {
        $pdo->beginTransaction();

        $stmtSub = $pdo->prepare('UPDATE subscriptions SET status = \'confirmed\', "confirmedAt" = NOW() WHERE id = ?');
        $stmtSub->execute([$subId]);

        $stmtPass = $pdo->prepare('UPDATE passwords SET status = \'reserved\', "soldAt" = NOW() WHERE "subscriptionId" = ?');
        $stmtPass->execute([$subId]);
        
        $stmtPay = $pdo->prepare('UPDATE payments SET status = \'approved\', "mpPaymentId" = ?, "updatedAt" = NOW() WHERE id = ?');
        $stmtPay->execute([$mpPaymentId, $paymentId]);

        $pdo->commit();
        echo "&nbsp;&nbsp;- <span style='color:green'>SUCESSO: Pagamento APROVADO.</span><br>";

    } catch (Exception $e) {
        $pdo->rollBack();
        echo "&nbsp;&nbsp;- <span style='color:red'>ERRO ao aprovar: " . $e->getMessage() . "</span><br>";
    }
}

/**
 * Marca como Fechado/Cancelado
 */
function markAsClosed($pdo, $paymentId, $subId, $statusInfo) {
    try {
        $pdo->beginTransaction();

        $stmtSub = $pdo->prepare("UPDATE subscriptions SET status = 'cancelled' WHERE id = ?");
        $stmtSub->execute([$subId]);

        $stmtPass = $pdo->prepare('UPDATE passwords SET status = \'available\' WHERE "subscriptionId" = ?');
        $stmtPass->execute([$subId]);

        $stmtPay = $pdo->prepare('UPDATE payments SET status = ?, "updatedAt" = NOW() WHERE id = ?');
        $stmtPay->execute([$statusInfo, $paymentId]);

        $pdo->commit();
        echo "&nbsp;&nbsp;- <span style='color:blue'>INFO: Pagamento $statusInfo.</span><br>";

    } catch (Exception $e) {
        $pdo->rollBack();
        echo "&nbsp;&nbsp;- <span style='color:red'>ERRO ao cancelar: " . $e->getMessage() . "</span><br>";
    }
}
?>
