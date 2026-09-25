<?php
/**
 * [BACKEND-REGISTO] register.php
 * Recebe utilizador+password do register.html, valida, cria a conta
 * (sem 2FA) e guarda o utilizador na sessão para o 2fa/setup.php usar.
 */
session_start();

require __DIR__ . '/../shared/users.php';
require __DIR__ . '/../../admin/admin_config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_response_code(405);
	echo json_encode(['status' => 'error', 'error' => 'method_not_allowed']);
	exit;
}

$user = trim($_POST['user'] ?? '');
$pass = $_POST['pass'] ?? '';
$passConfirm = $_POST['pass_confirm'] ?? '';

// Validações básicas
if ($user === '' || !preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $user)) {
	echo json_encode(['status' => 'error', 'error' => 'invalid_user']);
	exit;
}

if (strlen($pass) < 8) {
	echo json_encode(['status' => 'error', 'error' => 'weak_password']);
	exit;
}

if (!hash_equals($pass, $passConfirm)) {
	echo json_encode(['status' => 'error', 'error' => 'password_mismatch']);
	exit;
}

// Nome reservado: o painel entra pelo mesmo login, e duas coisas com o
// mesmo nome faziam com que a conta nunca conseguisse entrar.
if (strcasecmp($user, ADMIN_USER) === 0) {
	echo json_encode(['status' => 'error', 'error' => 'user_exists']);
	exit;
}

if (userExists($user)) {
	echo json_encode(['status' => 'error', 'error' => 'user_exists']);
	exit;
}

$result = createUser($user, $pass);

if ($result === null) {
	echo json_encode(['status' => 'error', 'error' => 'user_exists']);
	exit;
}

// Guarda o utilizador na sessão para a página /2fa/ saber para quem gerar o secret
$_SESSION['pending_setup_user'] = $result['user'];

echo json_encode([
	'status' => 'ok',
	'user' => $result['user'],
]);
