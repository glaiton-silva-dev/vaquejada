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

// Buscar pagamentos PENDING
// Trazemos subscription_id pois ele é o elo com as senhas/ingressos
$sql = "SELECT id, subscription_id, mp_payment_id, created_at, external_reference 
        FROM payments 
        WHERE status = 'PENDING'"; 

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
    $subId = $payment['subscription_id']; 
    $mpId = $payment['mp_payment_id'];
    
    // Tratamento de Datas
    $createdAt = new DateTime($payment['created_at']);
    $now = new DateTime();
    
    // Diferença em minutos
    $diffMinutes = ($now->getTimestamp() - $createdAt->getTimestamp()) / 60;

    echo "<strong>Processando Pagamento ID:</strong> $paymentId <br>";
    echo "&nbsp;&nbsp;- Tempo decorrido: " . round($diffMinutes, 1) . " min.<br>";

    // --- CASO 1: Expirou o tempo (> 10 min) ---
    if ($diffMinutes > $timeoutMinutes) {
        echo "&nbsp;&nbsp;- <span style='color:red'>EXPIRADO. Cancelando localmente...</span><br>";
        markAsClosed($pdo, $paymentId, $subId, 'CANCELLED');
        echo "<hr>";
        continue; // Vai para o próximo
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
                $localStatus = ($mpStatus === 'rejected') ? 'REJECTED' : 'CANCELLED';
                markAsClosed($pdo, $paymentId, $subId, $localStatus);
            }
        } else {
            echo "&nbsp;&nbsp;- <span style='color:orange'>Não foi possível obter status do MP.</span><br>";
        }
    } else {
        echo "&nbsp;&nbsp;- Sem ID do MP, aguardando webhook ou tempo limite.<br>";
    }
    echo "<hr>";
}

echo "<h4>Verificação concluída com sucesso.</h4>";


// ==================================================================
// 4. FUNÇÕES AUXILIARES
// ==================================================================

/**
 * Consulta a API do Mercado Pago
 */
function getMercadoPagoStatus($mpPaymentId, $token) {
    $url = "https://api.mercadopago.com/v1/payments/$mpPaymentId";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Timeout curto para não travar
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
 * Marca como Aprovado (Atualiza Subscription, Passwords e Payment)
 */
function markAsApproved($pdo, $paymentId, $subId, $mpPaymentId) {
    try {
        $pdo->beginTransaction();

        // 1. Atualizar Subscription -> CONFIRMED
        $stmtSub = $pdo->prepare("UPDATE subscriptions SET status = 'CONFIRMED', confirmed_at = NOW() WHERE id = ?");
        $stmtSub->execute([$subId]);

        // 2. Atualizar Passwords -> RESERVED
        // Assume que 'passwords' tem 'subscription_id' ou lógica similar
        $stmtPass = $pdo->prepare("UPDATE passwords SET status = 'RESERVED', sold_at = NOW() WHERE subscription_id = ?");
        $stmtPass->execute([$subId]);

        // 3. Atualizar Payment -> APPROVED
        $stmtPay = $pdo->prepare("UPDATE payments SET status = 'APPROVED', mp_payment_id = ?, updated_at = NOW() WHERE id = ?");
        $stmtPay->execute([$mpPaymentId, $paymentId]);

        $pdo->commit();
        echo "&nbsp;&nbsp;- <span style='color:green'>SUCESSO: Pagamento APROVADO e registros atualizados.</span><br>";

    } catch (Exception $e) {
        $pdo->rollBack();
        echo "&nbsp;&nbsp;- <span style='color:red'>ERRO ao aprovar: " . $e->getMessage() . "</span><br>";
    }
}

/**
 * Marca como Fechado/Cancelado (Libera Passwords e Cancela Subscription)
 */
function markAsClosed($pdo, $paymentId, $subId, $statusInfo) {
    try {
        $pdo->beginTransaction();

        // 1. Atualizar Subscription -> CANCELLED
        $stmtSub = $pdo->prepare("UPDATE subscriptions SET status = 'CANCELLED' WHERE id = ?");
        $stmtSub->execute([$subId]);

        // 2. Atualizar Passwords -> AVAILABLE (Libera os ingressos)
        // Mantém subscription_id ou limpa? O Node apenas mudava status.
        // Aqui vamos liberar o status para AVAILABLE para ser vendido novamente.
        $stmtPass = $pdo->prepare("UPDATE passwords SET status = 'AVAILABLE' WHERE subscription_id = ?");
        $stmtPass->execute([$subId]);

        // 3. Atualizar Payment -> CANCELLED/REJECTED
        $stmtPay = $pdo->prepare("UPDATE payments SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmtPay->execute([$statusInfo, $paymentId]);

        $pdo->commit();
        echo "&nbsp;&nbsp;- <span style='color:blue'>INFO: Pagamento $statusInfo e registros liberados.</span><br>";

    } catch (Exception $e) {
        $pdo->rollBack();
        echo "&nbsp;&nbsp;- <span style='color:red'>ERRO ao cancelar: " . $e->getMessage() . "</span><br>";
    }
}
?>
