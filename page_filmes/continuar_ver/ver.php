<?php
/**
 * [BACKEND-CONTINUAR] ver.php
 * API JSON do "Continuar a ver". Fica no servidor, ligado a conta, pela
 * mesma razao da lista pessoal: no browser era partilhado por todas as
 * contas daquele computador e nao acompanhava a conta noutro.
 *
 * Acoes:
 *   GET  ?action=listar    -> { token, itens }
 *   POST action=registar   (token, titulo, categoria, extra, arte)
 *   POST action=progresso  (token, titulo, progresso)
 *   POST action=tirar      (token, titulo)
 *   POST action=limpar     (token)
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
 * Mesmo token do lista.php, de proposito: sao as duas o estado pessoal do
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
		'itens' => userProgress($nome),
	]);
}

// --- a partir daqui escreve: POST e token ----------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	respond(['status' => 'error', 'error' => 'metodo_invalido'], 405);
}

if (!hash_equals(csrfToken(), $_POST['token'] ?? '')) {
	respond(['status' => 'error', 'error' => 'token_invalido'], 403);
}

if ($action === 'limpar') {
	guardarContinuar($nome, []);
	respond(['status' => 'ok', 'itens' => []]);
}

$titulo = textoLimpo($_POST['titulo'] ?? '', 200);

if ($titulo === '') {
	respond(['status' => 'error', 'error' => 'titulo_invalido'], 422);
}

switch ($action) {
	case 'registar':
		// so os campos que a pagina mostra; o resto do pedido e ignorado
		touchProgress($nome, [
			'titulo' => $titulo,
			'categoria' => textoLimpo($_POST['categoria'] ?? '', 60),
			'extra' => textoLimpo($_POST['extra'] ?? '', 60),
			// imagem larga da TMDB: estes cartoes sao deitados (16/9), por
			// isso pede-se o fundo e nao o cartaz, que e ao alto
			'arte' => arteTmdb($_POST['arte'] ?? ''),
		]);
		break;

	case 'progresso':
		setProgress($nome, $titulo, (int) ($_POST['progresso'] ?? 0));
		break;

	case 'tirar':
		removeProgress($nome, $titulo);
		break;

	default:
		respond(['status' => 'error', 'error' => 'accao_desconhecida'], 400);
}

respond(['status' => 'ok', 'itens' => userProgress($nome)]);
