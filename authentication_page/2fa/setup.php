<?php
/**
 * [BACKEND-2FA] setup.php
 * Gera e ativa o secret TOTP para o utilizador guardado na sessão
 * (por register.php). Devolve o secret em claro só desta vez.
 */
session_start();

require __DIR__ . '/../shared/Totp.php';
require __DIR__ . '/../shared/users.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_response_code(405);
	echo json_encode(['status' => 'error', 'error' => 'method_not_allowed']);
	exit;
}

$user = $_SESSION['pending_setup_user'] ?? null;

if (!$user) {
	echo json_encode(['status' => 'error', 'error' => 'no_pending_user']);
	exit;
}

$secret = enableTotp($user);

if (!$secret) {
	echo json_encode(['status' => 'error', 'error' => 'user_not_found']);
	exit;
}

// Consumido — evita gerar um novo secret sem querer se a página for recarregada
unset($_SESSION['pending_setup_user']);

echo json_encode([
	'status' => 'ok',
	'user' => $user,
	'totp_secret' => $secret,
	// O QR e desenhado no browser a partir deste URI: o secret nunca sai
	// daqui para nenhum servico exterior.
	'otpauth_uri' => buildOtpAuthUri($secret, $user),
]);
