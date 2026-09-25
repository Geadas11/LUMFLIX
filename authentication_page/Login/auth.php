<?php
/**
 * [BACKEND-LOGIN] auth.php
 * Verifica password (passo 1) e, se a conta tiver 2FA, o código
 * TOTP (passo 2). Autentica a sessão PHP quando tudo bate certo.
 */
session_start();

require __DIR__ . '/../shared/Totp.php';
require __DIR__ . '/../shared/users.php';
require __DIR__ . '/../../admin/admin_config.php';

$redirectUrl = '../../page_filmes/filmes/filmes.html';
$adminUrl = '../../admin/overview.html';

/**
 * Trava de aprovacao. Corre depois de a password bater certo, nunca
 * antes: responder "por aprovar" a quem errou a password diria a um
 * estranho que aquele utilizador existe.
 */
function statusBlock(array $userData): ?string
{
	switch (userStatus($userData)) {
		case USER_STATUS_PENDING:
			return 'pending_approval';
		case USER_STATUS_DENIED:
			return 'account_denied';
		default:
			return null;
	}
}

function isAjaxRequest(): bool
{
	$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
	$wantsJson = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
	return $isAjax || $wantsJson;
}

if (!empty($_SESSION['authenticated']) && $_SESSION['authenticated'] === true) {
	if (isAjaxRequest()) {
		echo json_encode(['status' => 'ok', 'authenticated' => true, 'redirect' => $redirectUrl]);
		exit;
	}

	header('Location: ' . $redirectUrl);
	exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	header('Location: auth.html');
	exit;
}

// Passo 2: verificação do código TOTP
if (!empty($_POST['otp_step'])) {
	if (empty($_SESSION['pending_2fa']) || empty($_SESSION['pending_user'])) {
		if (isAjaxRequest()) {
			echo json_encode(['status' => 'error', 'error' => 'no_pending']);
			exit;
		}

		header('Location: auth.html');
		exit;
	}

	if (empty($_SESSION['otp_attempts'])) {
		$_SESSION['otp_attempts'] = 0;
	}

	if ($_SESSION['otp_attempts'] >= 5) {
		if (isAjaxRequest()) {
			echo json_encode(['status' => 'error', 'error' => 'too_many_attempts']);
			exit;
		}

		header('Location: auth.html?' . http_build_query([
			'step' => '2',
			'error' => 'too_many_attempts',
			'user' => $_SESSION['pending_user'],
		]));
		exit;
	}

	$pendingUser = $_SESSION['pending_user'];
	$userData = findUser($pendingUser);
	$otpCode = $_POST['otp_code'] ?? '';

	if ($userData && verifyTotp($userData['totp_secret'], $otpCode)) {
		$block = statusBlock($userData);
		if ($block !== null) {
			unset($_SESSION['pending_2fa'], $_SESSION['pending_user'], $_SESSION['otp_attempts']);

			if (isAjaxRequest()) {
				echo json_encode(['status' => 'error', 'error' => $block]);
				exit;
			}

			header('Location: auth.html?' . http_build_query(['error' => $block]));
			exit;
		}

		session_regenerate_id(true);
		$_SESSION['authenticated'] = true;
		$_SESSION['user'] = $pendingUser;
		unset($_SESSION['pending_2fa'], $_SESSION['pending_user'], $_SESSION['otp_attempts']);

		if (isAjaxRequest()) {
			echo json_encode(['status' => 'ok', 'authenticated' => true, 'redirect' => $redirectUrl]);
			exit;
		}

		header('Location: ' . $redirectUrl);
		exit;
	}

	$_SESSION['otp_attempts']++;

	if (isAjaxRequest()) {
		echo json_encode(['status' => 'error', 'error' => 2, 'user' => $pendingUser]);
		exit;
	}

	header('Location: auth.html?' . http_build_query([
		'step' => '2',
		'error' => '2',
		'user' => $pendingUser,
	]));
	exit;
}

// Passo 1: utilizador + palavra-passe
$user = trim($_POST['user'] ?? '');
$pass = $_POST['pass'] ?? '';

/**
 * Painel de administracao. Vem antes da procura em users.json porque o
 * administrador nao e uma conta do site: tem credenciais proprias
 * (admin_config.php), nao passa por 2FA nem por aprovacao. O nome fica
 * reservado no registo, para nao haver duas coisas com o mesmo nome.
 */
if (hash_equals(ADMIN_USER, $user) && password_verify($pass, ADMIN_PASSWORD_HASH)) {
	session_regenerate_id(true);
	$_SESSION['admin_authenticated'] = true;

	if (isAjaxRequest()) {
		echo json_encode(['status' => 'ok', 'authenticated' => true, 'redirect' => $adminUrl]);
		exit;
	}

	header('Location: ' . $adminUrl);
	exit;
}

$userData = findUser($user);

if ($userData && password_verify($pass, $userData['password_hash'])) {
	$block = statusBlock($userData);
	if ($block !== null) {
		if (isAjaxRequest()) {
			echo json_encode(['status' => 'error', 'error' => $block]);
			exit;
		}

		header('Location: auth.html?' . http_build_query(['error' => $block]));
		exit;
	}

	// Sem 2FA configurado: autentica logo, sem passar pelo passo 2
	if (empty($userData['totp_secret'])) {
		session_regenerate_id(true);
		$_SESSION['authenticated'] = true;
		$_SESSION['user'] = $user;

		if (isAjaxRequest()) {
			echo json_encode(['status' => 'ok', 'authenticated' => true, 'redirect' => $redirectUrl]);
			exit;
		}

		header('Location: ' . $redirectUrl);
		exit;
	}

	$_SESSION['pending_2fa'] = true;
	$_SESSION['pending_user'] = $user;
	$_SESSION['otp_attempts'] = 0;

	if (isAjaxRequest()) {
		echo json_encode(['status' => 'ok', 'step' => 2, 'user' => $user]);
		exit;
	}

	header('Location: auth.html?' . http_build_query([
		'step' => '2',
		'user' => $user,
	]));
	exit;
}

// Credenciais erradas. Faltava aqui o ramo AJAX: o fetch seguia o
// redirecionamento, recebia HTML, o resp.json() rebentava e a pagina
// mostrava "Ocorreu um erro" em vez de "Credenciais invalidas".
if (isAjaxRequest()) {
	echo json_encode(['status' => 'error', 'error' => 1, 'user' => $user]);
	exit;
}

header('Location: auth.html?' . http_build_query([
	'error' => '1',
	'user' => $user,
]));
exit;
