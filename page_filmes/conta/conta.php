<?php
/**
 * [BACKEND-CONTA] conta.php
 * API JSON da pagina da conta: perfil, troca de palavra-passe e ligar ou
 * desligar o 2FA.
 *
 * Tudo o que altera a conta pede a palavra-passe atual, mesmo havendo
 * sessao aberta: sem isso, bastava um computador deixado desbloqueado
 * para alguem trocar a password ou desligar o 2FA.
 *
 * Acoes:
 *   GET  ?action=perfil                 -> dados da conta + token
 *   POST action=email      (atual, email, token)
 *   POST action=password   (atual, nova, confirma, token)
 *   POST action=ligar_2fa  (atual, token) -> devolve o secret uma so vez
 *   POST action=desligar_2fa (atual, token)
 */
session_start();

require __DIR__ . '/../../authentication_page/Shared/Totp.php';
require __DIR__ . '/../../authentication_page/Shared/users.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function respond(array $payload, int $code = 200): void
{
	http_response_code($code);
	echo json_encode($payload);
	exit;
}

/**
 * Le a conta do disco a cada pedido em vez de confiar na sessao: se a
 * conta for negada ou apagada entretanto, perde o acesso de imediato.
 */
function contaAtual(): ?array
{
	if (empty($_SESSION['authenticated']) || empty($_SESSION['user'])) {
		return null;
	}

	$nome = $_SESSION['user'];
	$registo = findUser($nome);

	if ($registo === null || !isApproved($registo)) {
		return null;
	}

	return ['nome' => $nome, 'registo' => $registo];
}

function csrfToken(): string
{
	if (empty($_SESSION['conta_csrf'])) {
		$_SESSION['conta_csrf'] = bin2hex(random_bytes(16));
	}

	return $_SESSION['conta_csrf'];
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$conta = contaAtual();

if ($conta === null) {
	respond(['status' => 'error', 'error' => 'sem_sessao'], 401);
}

$nome = $conta['nome'];
$registo = $conta['registo'];

if ($action === 'perfil') {
	respond([
		'status' => 'ok',
		'token' => csrfToken(),
		'user' => $nome,
		'created_at' => $registo['created_at'] ?? null,
		'estado' => userStatus($registo),
		'tem_2fa' => !empty($registo['totp_secret']),
		'email' => $registo['email'] ?? null,
	]);
}

// --- a partir daqui tudo escreve: POST, token e palavra-passe ---------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	respond(['status' => 'error', 'error' => 'metodo_invalido'], 405);
}

if (!hash_equals(csrfToken(), $_POST['token'] ?? '')) {
	respond(['status' => 'error', 'error' => 'token_invalido'], 403);
}

if (!password_verify($_POST['atual'] ?? '', $registo['password_hash'])) {
	respond(['status' => 'error', 'error' => 'password_errada'], 403);
}

switch ($action) {
	case 'email':
		$email = trim($_POST['email'] ?? '');

		// vazio remove o email; o resto tem de ser um endereco valido
		if ($email !== '') {
			if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
				respond(['status' => 'error', 'error' => 'email_invalido'], 422);
			}
		}

		updateEmail($nome, $email);
		respond(['status' => 'ok', 'email' => $email === '' ? null : $email]);

	case 'password':
		$nova = $_POST['nova'] ?? '';

		if (strlen($nova) < 8) {
			respond(['status' => 'error', 'error' => 'password_fraca'], 422);
		}

		if (!hash_equals($nova, $_POST['confirma'] ?? '')) {
			respond(['status' => 'error', 'error' => 'password_diferente'], 422);
		}

		updatePassword($nome, $nova);

		// a sessao continua aberta, mas com identificador novo
		session_regenerate_id(true);

		respond(['status' => 'ok']);

	case 'ligar_2fa':
		$secret = enableTotp($nome);

		if ($secret === null) {
			respond(['status' => 'error', 'error' => 'sem_sessao'], 401);
		}

		// o secret sai em claro so nesta resposta, como no registo
		respond([
			'status' => 'ok',
			'secret' => $secret,
			'otpauth_uri' => buildOtpAuthUri($secret, $nome),
		]);

	case 'desligar_2fa':
		disableTotp($nome);
		respond(['status' => 'ok']);
}

respond(['status' => 'error', 'error' => 'accao_desconhecida'], 400);
