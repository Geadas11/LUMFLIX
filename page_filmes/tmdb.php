<?php
/**
 * [PROXY-TMDB] tmdb.php
 * Traz o catalogo do TMDB para as paginas de filmes e series.
 *
 * As paginas nunca falam com o TMDB: falam com este ficheiro. Duas
 * razoes — a credencial fica no servidor, e a resposta pode ser
 * guardada em cache, de outro modo cada visita disparava dezenas de
 * pedidos a uma API que tem limites.
 *
 * Aqui so estao as accoes; o que sabe do TMDB esta no tmdb_lib.php.
 *
 * Acoes:
 *   GET ?action=catalogo&tipo=movie|tv  -> lista pronta a desenhar
 *   GET ?action=busca&tipo=..&q=..      -> procura em todo o TMDB
 *   GET ?action=detalhe&tipo=..&id=..   -> duracao e classificacao etaria
 */
session_start();

require __DIR__ . '/tmdb_lib.php';

exigirSessao();

if (!defined('TMDB_TOKEN') || str_starts_with(TMDB_TOKEN, 'COLA_AQUI')) {
	respond(['status' => 'error', 'error' => 'sem_credencial'], 500);
}

/* ---- accoes ----------------------------------------------------------- */

$action = $_GET['action'] ?? '';
$tipo = ($_GET['tipo'] ?? 'movie') === 'tv' ? 'tv' : 'movie';

if ($action === 'catalogo') {
	$dados = catalogo($tipo);

	if (!$dados['itens']) {
		respond(['status' => 'error', 'error' => 'tmdb_indisponivel'], 502);
	}

	respond(['status' => 'ok'] + $dados);
}

if ($action === 'busca') {
	$termo = trim((string) ($_GET['q'] ?? ''));

	/* Uma letra so devolvia meio TMDB e nao ajudava ninguem a encontrar
	   nada; e o limite de cima e para nao guardar em cache procuras
	   absurdas que alguem mandasse de proposito. */
	if (mb_strlen($termo) < 2 || mb_strlen($termo) > 80) {
		respond(['status' => 'ok', 'itens' => []]);
	}

	respond(['status' => 'ok'] + busca($tipo, $termo));
}

if ($action === 'detalhe') {
	$id = (int) ($_GET['id'] ?? 0);

	if ($id <= 0) {
		respond(['status' => 'error', 'error' => 'id_invalido'], 422);
	}

	$cache = daCache("detalhe_{$tipo}_$id", TMDB_CACHE_SEGUNDOS);
	if ($cache !== null) {
		respond(['status' => 'ok'] + $cache);
	}

	$extra = $tipo === 'movie' ? 'release_dates' : 'content_ratings';
	$dados = tmdb("$tipo/$id", ['language' => 'en-US', 'append_to_response' => $extra]);

	if ($dados === null) {
		respond(['status' => 'error', 'error' => 'tmdb_indisponivel'], 502);
	}

	$minutos = $dados['runtime'] ?? ($dados['episode_run_time'][0] ?? null);

	$detalhe = [
		'duracao' => $tipo === 'tv'
			? (($dados['number_of_seasons'] ?? 1) . ' season' . (($dados['number_of_seasons'] ?? 1) === 1 ? '' : 's'))
			: ($minutos ? intdiv($minutos, 60) . 'h ' . str_pad($minutos % 60, 2, '0', STR_PAD_LEFT) . 'm' : null),
		'temporadas' => $tipo === 'tv' ? ($dados['number_of_seasons'] ?? 1) : null,
		'idade' => classificacao($dados, $tipo),
	];

	paraCache("detalhe_{$tipo}_$id", $detalhe);
	respond(['status' => 'ok'] + $detalhe);
}

respond(['status' => 'error', 'error' => 'accao_desconhecida'], 400);
