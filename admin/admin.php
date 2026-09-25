<?php
/**
 * [BACKEND-ADMIN] admin.php
 * API JSON das paginas de administracao: listar contas, aprovar, negar
 * e apagar. Tudo o que escreve exige sessao de administrador e token.
 *
 * O painel tem login proprio (ver admin_config.php), separado das contas
 * de utilizador: entrar aqui nao da acesso ao site, e entrar no site nao
 * da acesso aqui.
 *
 * Acoes:
 *   POST action=login      -> user, pass
 *   POST action=logout
 *   GET  ?action=session   -> se ha sessao de painel aberta
 *   GET  ?action=list      -> contas + contagens + token
 *   POST action=approve|deny|pending|delete  (user, token)
 */
session_start();

require __DIR__ . '/admin_config.php';
require __DIR__ . '/../authentication_page/Shared/users.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function respond(array $payload, int $code = 200): void
{
	http_response_code($code);
	echo json_encode($payload);
	exit;
}

function currentAdmin(): ?string
{
	return !empty($_SESSION['admin_authenticated']) ? ADMIN_USER : null;
}

function csrfToken(): string
{
	if (empty($_SESSION['admin_csrf'])) {
		$_SESSION['admin_csrf'] = bin2hex(random_bytes(16));
	}

	return $_SESSION['admin_csrf'];
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$admin = currentAdmin();

if ($action === 'login') {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		respond(['status' => 'error', 'error' => 'method_not_allowed'], 405);
	}

	$user = trim($_POST['user'] ?? '');
	$pass = $_POST['pass'] ?? '';

	/* hash_equals no nome e password_verify na password: as duas
	   comparacoes demoram o mesmo com ou sem acerto, para o tempo de
	   resposta nao denunciar qual das metades estava certa */
	$userOk = hash_equals(ADMIN_USER, $user);
	$passOk = password_verify($pass, ADMIN_PASSWORD_HASH);

	if (!$userOk || !$passOk) {
		respond(['status' => 'error', 'error' => 'bad_credentials'], 401);
	}

	session_regenerate_id(true);
	$_SESSION['admin_authenticated'] = true;

	respond(['status' => 'ok', 'user' => ADMIN_USER]);
}

if ($action === 'logout') {
	// so fecha o painel: se houver sessao de utilizador, fica de pe
	unset($_SESSION['admin_authenticated'], $_SESSION['admin_csrf']);
	respond(['status' => 'ok']);
}

if ($admin === null) {
	respond(['status' => 'error', 'error' => 'not_admin'], 403);
}

if ($action === 'session') {
	respond(['status' => 'ok', 'user' => $admin, 'token' => csrfToken()]);
}

if ($action === 'list') {
	$users = listUsersForAdmin();

	$stats = [
		'total' => count($users),
		'pending' => 0,
		'approved' => 0,
		'denied' => 0,
		'with_2fa' => 0,
	];

	foreach ($users as $u) {
		if (isset($stats[$u['status']])) {
			$stats[$u['status']]++;
		}
		if ($u['has_2fa']) {
			$stats['with_2fa']++;
		}
	}

	respond([
		'status' => 'ok',
		'user' => $admin,
		'token' => csrfToken(),
		'users' => $users,
		'stats' => $stats,
	]);
}

// --- a partir daqui, tudo escreve: exige POST e token ------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	respond(['status' => 'error', 'error' => 'method_not_allowed'], 405);
}

$token = $_POST['token'] ?? '';

if (!hash_equals(csrfToken(), $token)) {
	respond(['status' => 'error', 'error' => 'bad_token'], 403);
}

$target = trim($_POST['user'] ?? '');

if ($target === '' || findUser($target) === null) {
	respond(['status' => 'error', 'error' => 'user_not_found'], 404);
}

switch ($action) {
	case 'approve':
		reviewUser($target, USER_STATUS_APPROVED, $admin);
		break;

	case 'deny':
		reviewUser($target, USER_STATUS_DENIED, $admin);
		break;

	case 'pending':
		reviewUser($target, USER_STATUS_PENDING, $admin);
		break;

	case 'delete':
		deleteUser($target);
		break;

	default:
		respond(['status' => 'error', 'error' => 'unknown_action'], 400);
}

respond(['status' => 'ok', 'user' => $target, 'action' => $action]);
