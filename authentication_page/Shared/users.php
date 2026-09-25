<?php
/**
 * [BASE-DE-DADOS] users.php
 * Armazenamento das contas em data/users.json (criar, ler, ativar 2FA,
 * aprovar/negar). Usado por: register/register.php, 2fa/setup.php,
 * login/auth.php e admin/admin.php.
 */

/**
 * Estado de aprovacao de uma conta. Uma conta nova nasce em PENDING e
 * so entra depois de um administrador a aprovar.
 */
const USER_STATUS_PENDING = 'pending';
const USER_STATUS_APPROVED = 'approved';
const USER_STATUS_DENIED = 'denied';

function usersFilePath(): string
{
	return __DIR__ . '/data/users.json';
}

/**
 * Ficheiro que serve so de cadeado.
 *
 * Nao se tranca o proprio users.json porque quem grava tem de o esvaziar
 * primeiro, e quem estivesse a ler nesse instante lia um ficheiro vazio.
 * Era assim que se perdiam contas: o leitor concluia "nao ha contas
 * nenhumas" e a funcao seguinte gravava esse vazio por cima de todas.
 */
function usersLockPath(): string
{
	return usersFilePath() . '.lock';
}

/**
 * Tranca as contas e devolve o cadeado.
 *
 * Pede-se em loadUsers() e larga-se em saveUsers(), por isso o ciclo
 * ler-alterar-gravar que todas as funcoes daqui fazem corre inteiro sem
 * ninguem pelo meio: dois pedidos ao mesmo tempo passam a esperar um
 * pelo outro em vez de escreverem por cima um do outro. Se o pedido so
 * ler, o PHP larga o cadeado sozinho quando a pagina acaba.
 */
function usersLock()
{
	static $handle = null;

	if ($handle === null) {
		$dir = dirname(usersLockPath());
		if (!is_dir($dir)) {
			mkdir($dir, 0770, true);
		}

		$handle = fopen(usersLockPath(), 'c');
		if ($handle === false) {
			throw new RuntimeException('Não foi possível abrir o cadeado das contas.');
		}
	}

	if (!flock($handle, LOCK_EX)) {
		throw new RuntimeException('Não foi possível trancar as contas.');
	}

	return $handle;
}

function loadUsers(): array
{
	usersLock();

	$path = usersFilePath();

	if (!file_exists($path)) {
		return [];
	}

	$contents = file_get_contents($path);

	// ficheiro ainda por estrear: nao ha contas, e certo
	if (trim($contents) === '') {
		return [];
	}

	$data = json_decode($contents, true);

	/* Ha texto, mas nao se entende. Devolver [] aqui era o que fazia a
	   funcao seguinte gravar por cima e apagar toda a gente; mais vale a
	   pagina dar erro e o ficheiro ficar como esta. */
	if (!is_array($data)) {
		throw new RuntimeException('data/users.json está ilegível; não se grava por cima.');
	}

	return $data;
}

/**
 * Escreve o array de utilizadores de forma atómica, usando um lock
 * para evitar corrupção se dois pedidos escreverem ao mesmo tempo.
 */
function saveUsers(array $users): void
{
	$path = usersFilePath();
	$dir = dirname($path);

	if (!is_dir($dir)) {
		mkdir($dir, 0770, true);
	}

	$cadeado = usersLock();

	/* Grava-se num ficheiro ao lado e so no fim se troca pelo bom. O
	   users.json nunca chega a existir meio escrito nem vazio: ou e o
	   antigo, ou e o novo. */
	$temporario = $path . '.' . uniqid('', true) . '.tmp';
	$json = json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

	if ($json === false || file_put_contents($temporario, $json) !== strlen($json)) {
		@unlink($temporario);
		throw new RuntimeException('Não foi possível gravar data/users.json.');
	}

	if (!rename($temporario, $path)) {
		@unlink($temporario);
		throw new RuntimeException('Não foi possível substituir data/users.json.');
	}

	flock($cadeado, LOCK_UN);
}

function findUser(string $username): ?array
{
	$users = loadUsers();
	return $users[$username] ?? null;
}

function userExists(string $username): bool
{
	return findUser($username) !== null;
}

/**
 * Cria uma conta nova, sempre sem 2FA — isso é configurado depois,
 * na página /2fa/. Devolve null se o utilizador já existir.
 */
function createUser(string $username, string $password): ?array
{
	if (userExists($username)) {
		return null;
	}

	$users = loadUsers();

	$users[$username] = [
		'password_hash' => password_hash($password, PASSWORD_DEFAULT),
		'totp_secret' => null,
		'created_at' => date('c'),
		'status' => USER_STATUS_PENDING,
		'reviewed_at' => null,
		'reviewed_by' => null,
	];

	saveUsers($users);

	return ['user' => $username];
}

/**
 * Ativa o 2FA para um utilizador já existente: gera um secret novo,
 * guarda-o, e devolve-o em claro (só nesta chamada) para poderes
 * mostrá-lo na página de configuração. Devolve null se o utilizador
 * não existir.
 */
function enableTotp(string $username): ?string
{
	$users = loadUsers();

	if (!isset($users[$username])) {
		return null;
	}

	$secret = generateTotpSecret();
	$users[$username]['totp_secret'] = $secret;
	saveUsers($users);

	return $secret;
}

/**
 * Estado de uma conta. As contas criadas antes de existir aprovacao nao
 * tem o campo: contam como aprovadas, senao toda a gente — incluindo o
 * administrador — ficava fechada de fora quando isto entrou.
 */
function userStatus(array $record): string
{
	return $record['status'] ?? USER_STATUS_APPROVED;
}

function isApproved(array $record): bool
{
	return userStatus($record) === USER_STATUS_APPROVED;
}

/**
 * Marca uma conta como aprovada ou negada, guardando quem decidiu e
 * quando. Devolve false se a conta nao existir ou o estado nao for valido.
 */
function reviewUser(string $username, string $status, string $reviewer): bool
{
	$allowed = [USER_STATUS_PENDING, USER_STATUS_APPROVED, USER_STATUS_DENIED];

	if (!in_array($status, $allowed, true)) {
		return false;
	}

	$users = loadUsers();

	if (!isset($users[$username])) {
		return false;
	}

	$users[$username]['status'] = $status;
	$users[$username]['reviewed_at'] = date('c');
	$users[$username]['reviewed_by'] = $reviewer;

	saveUsers($users);

	return true;
}

function deleteUser(string $username): bool
{
	$users = loadUsers();

	if (!isset($users[$username])) {
		return false;
	}

	unset($users[$username]);
	saveUsers($users);

	return true;
}

/**
 * Lista as contas sem nada de sensivel: nem hash da password nem secret
 * 2FA saem daqui, so se a conta tem 2FA ou nao.
 */
function listUsersForAdmin(): array
{
	$out = [];

	foreach (loadUsers() as $username => $record) {
		$out[] = [
			// (string) obrigatorio: o PHP converte chaves de array so com
			// digitos em inteiros, e "123456789" chegava ao JSON como
			// numero — o lado do browser rebentava ao tratar como texto
			'user' => (string) $username,
			'email' => $record['email'] ?? null,
			'status' => userStatus($record),
			'has_2fa' => !empty($record['totp_secret']),
			'created_at' => $record['created_at'] ?? null,
			'reviewed_at' => $record['reviewed_at'] ?? null,
			'reviewed_by' => $record['reviewed_by'] ?? null,
		];
	}

	return $out;
}

/**
 * Troca a palavra-passe. Quem chama e que confirma a antiga primeiro —
 * esta funcao so escreve.
 */
function updatePassword(string $username, string $password): bool
{
	$users = loadUsers();

	if (!isset($users[$username])) {
		return false;
	}

	$users[$username]['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
	saveUsers($users);

	return true;
}

/**
 * Desliga o 2FA, apagando o secret. A conta passa a entrar so com a
 * palavra-passe.
 */
function disableTotp(string $username): bool
{
	$users = loadUsers();

	if (!isset($users[$username])) {
		return false;
	}

	$users[$username]['totp_secret'] = null;
	saveUsers($users);

	return true;
}

/**
 * Guarda (ou apaga) o email da conta. Passar cadeia vazia remove-o.
 * Quem chama e que valida o formato — esta funcao so escreve.
 */
function updateEmail(string $username, ?string $email): bool
{
	$users = loadUsers();

	if (!isset($users[$username])) {
		return false;
	}

	$users[$username]['email'] = ($email === null || $email === '') ? null : $email;
	saveUsers($users);

	return true;
}

/**
 * Lista pessoal ("a minha lista"). Fica no registo do utilizador, e nao
 * no browser, para acompanhar a conta em qualquer dispositivo.
 *
 * Cada entrada guarda o que a pagina da lista precisa de mostrar, porque
 * o catalogo vive nas paginas e nao ha aqui de onde o ir buscar.
 */
function userList(string $username): array
{
	$registo = findUser($username);
	$lista = $registo['lista'] ?? [];

	return is_array($lista) ? array_values($lista) : [];
}

/**
 * Junta um titulo a lista. Se ja la estiver, nao duplica — devolve false
 * para quem chama saber que nada mudou.
 */
/**
 * Deixa passar enderecos de imagem da TMDB e mais nada.
 *
 * Estes enderecos sao guardados e depois postos num atributo src: se um
 * pedido pudesse escrever aqui qualquer endereco, a pagina de outra
 * pessoa passava a ir buscar imagens ao sitio que o atacante quisesse.
 */
function arteTmdb(?string $url): string
{
	$url = trim((string) $url);

	return str_starts_with($url, 'https://image.tmdb.org/t/p/') ? substr($url, 0, 200) : '';
}

/**
 * Texto vindo de um pedido, pronto a guardar.
 *
 * Faz duas coisas que o substr() nao faz: corta por caracteres em vez de
 * bytes, e deita fora bytes que nao sejam UTF-8 valido.
 *
 * O corte por bytes partia acentos ao meio. O que sobrava deixava de ser
 * UTF-8, o json_encode devolvia false, o saveUsers recusava-se a gravar
 * — e bem — e o pedido morria com erro fatal. Com sinopses do TMDB, que
 * passam dos 500 caracteres e trazem acentos, era so uma questao de
 * tempo ate acontecer.
 */
function textoLimpo(?string $valor, int $maximo): string
{
	$texto = trim((string) $valor);

	// converter de UTF-8 para UTF-8 deixa cair o que nao for valido
	if (!mb_check_encoding($texto, 'UTF-8')) {
		$texto = mb_convert_encoding($texto, 'UTF-8', 'UTF-8');
	}

	return mb_substr($texto, 0, $maximo, 'UTF-8');
}

function addToList(string $username, array $item): bool
{
	$users = loadUsers();

	if (!isset($users[$username]) || empty($item['titulo'])) {
		return false;
	}

	$lista = $users[$username]['lista'] ?? [];
	if (!is_array($lista)) {
		$lista = [];
	}

	foreach ($lista as $ja) {
		if (($ja['titulo'] ?? null) === $item['titulo']) {
			return false;
		}
	}

	$item['added_at'] = date('c');
	array_unshift($lista, $item);

	// um limite para o ficheiro nao crescer sem fim
	$users[$username]['lista'] = array_slice($lista, 0, 100);
	saveUsers($users);

	return true;
}

function removeFromList(string $username, string $titulo): bool
{
	$users = loadUsers();

	if (!isset($users[$username])) {
		return false;
	}

	$lista = $users[$username]['lista'] ?? [];
	if (!is_array($lista)) {
		$lista = [];
	}

	$users[$username]['lista'] = array_values(array_filter(
		$lista,
		fn($item) => ($item['titulo'] ?? null) !== $titulo
	));

	saveUsers($users);

	return true;
}

/**
 * "Continuar a ver". Fica no registo do utilizador, pela mesma razao da
 * lista pessoal: no browser era partilhado por todas as contas que
 * usassem aquele computador, e nao acompanhava a conta noutro.
 */
function userProgress(string $username): array
{
	$registo = findUser($username);
	$itens = $registo['continuar'] ?? [];

	return is_array($itens) ? array_values($itens) : [];
}

function guardarContinuar(string $username, array $itens): bool
{
	$users = loadUsers();

	if (!isset($users[$username])) {
		return false;
	}

	// 20 chega: isto e para retomar, nao e um historico
	$users[$username]['continuar'] = array_slice(array_values($itens), 0, 20);
	saveUsers($users);

	return true;
}

/**
 * Poe (ou volta a por) um titulo no topo. Se ja la estava, mantem o
 * progresso que tinha em vez de o deitar fora.
 */
function touchProgress(string $username, array $item): bool
{
	if (empty($item['titulo'])) {
		return false;
	}

	$itens = userProgress($username);
	$progresso = 0;
	$restantes = [];

	foreach ($itens as $ja) {
		if (($ja['titulo'] ?? null) === $item['titulo']) {
			$progresso = (int) ($ja['progresso'] ?? 0);
		} else {
			$restantes[] = $ja;
		}
	}

	$item['progresso'] = $progresso;
	$item['visto_em'] = date('c');
	array_unshift($restantes, $item);

	return guardarContinuar($username, $restantes);
}

function setProgress(string $username, string $titulo, int $pct): bool
{
	$itens = userProgress($username);

	foreach ($itens as &$item) {
		if (($item['titulo'] ?? null) === $titulo) {
			$item['progresso'] = max(0, min(100, $pct));
			$item['visto_em'] = date('c');
		}
	}
	unset($item);

	return guardarContinuar($username, $itens);
}

function removeProgress(string $username, string $titulo): bool
{
	$itens = array_filter(
		userProgress($username),
		fn($item) => ($item['titulo'] ?? null) !== $titulo
	);

	return guardarContinuar($username, $itens);
}
