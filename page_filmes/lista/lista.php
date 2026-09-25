<?php
/**
 * [BACKEND-LISTA] lista.php
 * API JSON da lista pessoal ("My list"). Fica no servidor, ligada a
 * conta, para a lista acompanhar o utilizador em qualquer dispositivo.
 *
 * Acoes:
 *   GET  ?action=listar  -> { token, itens }
 *   POST action=juntar   (token, titulo, categoria, ano, duracao, idade, sinopse, cartaz)
 *   POST action=tirar    (token, titulo)
 */
session_start();

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
function contaAtual(): ?string
{
	if (empty($_SESSION['authenticated']) || empty($_SESSION['user'])) {
		return null;
	}

	$registo = findUser($_SESSION['user']);

	if ($registo === null || !isApproved($registo)) {
		return null;
	}

	return $_SESSION['user'];
}

/**
 * Mesmo token do ver.php, de proposito: sao as duas o estado pessoal do
 * mesmo utilizador, e assim as paginas de catalogo buscam-no uma so vez.
 */
function csrfToken(): string
{
	if (empty($_SESSION['app_csrf'])) {
		$_SESSION['app_csrf'] = bin2hex(random_bytes(16));
	}

	return $_SESSION['app_csrf'];
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$nome = contaAtual();

if ($nome === null) {
	respond(['status' => 'error', 'error' => 'sem_sessao'], 401);
}

if ($action === 'listar') {
	respond([
		'status' => 'ok',
		'token' => csrfToken(),
		'itens' => userList($nome),
	]);
}

// --- a partir daqui escreve: POST e token ----------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	respond(['status' => 'error', 'error' => 'metodo_invalido'], 405);
}

if (!hash_equals(csrfToken(), $_POST['token'] ?? '')) {
	respond(['status' => 'error', 'error' => 'token_invalido'], 403);
}

$titulo = textoLimpo($_POST['titulo'] ?? '', 200);

if ($titulo === '') {
	respond(['status' => 'error', 'error' => 'titulo_invalido'], 422);
}

switch ($action) {
	case 'juntar':
		// so os campos que a pagina da lista mostra; nada do que venha
		// no pedido alem disto e guardado
		addToList($nome, [
			'titulo' => $titulo,
			'categoria' => textoLimpo($_POST['categoria'] ?? '', 60),
			'ano' => (int) ($_POST['ano'] ?? 0),
			'duracao' => textoLimpo($_POST['duracao'] ?? '', 40),
			'idade' => textoLimpo($_POST['idade'] ?? '', 8),
			'sinopse' => textoLimpo($_POST['sinopse'] ?? '', 500),
			// o cartaz da TMDB, para a lista mostrar a mesma arte do catalogo
			'cartaz' => arteTmdb($_POST['cartaz'] ?? ''),
		]);
		break;

	case 'tirar':
		removeFromList($nome, $titulo);
		break;

	default:
		respond(['status' => 'error', 'error' => 'accao_desconhecida'], 400);
}

respond(['status' => 'ok', 'itens' => userList($nome)]);
