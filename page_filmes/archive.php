<?php
/**
 * [PROXY-ARCHIVE] archive.php
 * A fila de classicos: filmes que se podem mesmo ver.
 *
 * O Internet Archive tem milhares de longas metragens de dominio
 * publico, servidas com suporte a Range — ou seja, da para saltar para o
 * minuto 40 sem descarregar o resto. E o video que o site nao tinha.
 *
 * O que o Archive nao tem e arte: as miniaturas sao fotogramas e os
 * titulos vem escritos de qualquer maneira. Por isso cada filme e
 * cruzado com o TMDB, que da o cartaz, a sinopse e os generos — e serve
 * ao mesmo tempo de crivo, porque um filme que o TMDB nao catalogou a
 * serio nao entra.
 *
 * Acoes:
 *   GET ?action=catalogo         -> classicos prontos a desenhar
 *   GET ?action=video&arquivo=.. -> endereco do ficheiro a tocar
 */

session_start();

require __DIR__ . '/tmdb_lib.php';
require __DIR__ . '/archive_config.php';

exigirSessao();

/* ---- Internet Archive -------------------------------------------------- */

/**
 * Os candidatos: os mais descarregados da coleccao, ja so os que tem MP4.
 *
 * O filtro do formato e o que impede a fila de prometer filmes que nao
 * tocam. Sem ele entram itens cuja ficha nao tem ficheiro nenhum para
 * dar, e isso so se descobre quando alguem ja carregou em Play.
 */
function candidatos(): array
{
	$procura = sprintf(
		'collection:(%s) AND mediatype:(movies) AND year:[%d TO %d] AND format:(MPEG4)',
		ARCHIVE_COLECCAO,
		ARCHIVE_ANO_DE,
		ARCHIVE_ANO_ATE
	);

	$url = 'https://archive.org/advancedsearch.php?' . http_build_query([
		'q' => $procura,
		'sort[]' => 'downloads desc',
		'rows' => ARCHIVE_CANDIDATOS,
		'page' => 1,
		'output' => 'json',
	]) . '&fl[]=identifier&fl[]=title&fl[]=year';

	$dados = pedirUm($url);

	return $dados['response']['docs'] ?? [];
}

/**
 * O endereco do ficheiro a tocar, escolhido da ficha do item.
 */
function videoDoItem(string $identificador): ?string
{
	$ficha = pedirUm(
		'https://archive.org/metadata/' . rawurlencode($identificador),
		[],
		ARCHIVE_ESPERA_SEGUNDOS
	);

	if ($ficha === null) {
		return null;
	}

	$porFormato = [];

	foreach ($ficha['files'] ?? [] as $f) {
		$formato = $f['format'] ?? '';
		$nome = $f['name'] ?? '';

		if ($nome === '' || !str_ends_with(strtolower($nome), '.mp4')) {
			continue;
		}

		$porFormato[$formato] ??= $nome;
	}

	foreach (ARCHIVE_FORMATOS as $preferido) {
		if (isset($porFormato[$preferido])) {
			return 'https://archive.org/download/' . rawurlencode($identificador)
				. '/' . rawurlencode($porFormato[$preferido]);
		}
	}

	// qualquer MP4 serve, se nenhum dos preferidos existir
	$qualquer = reset($porFormato);

	return $qualquer === false
		? null
		: 'https://archive.org/download/' . rawurlencode($identificador) . '/' . rawurlencode($qualquer);
}

/* ---- cruzamento com o TMDB --------------------------------------------- */

/**
 * Um campo da procura do Archive, como texto.
 *
 * Alguns campos vem como lista em vez de texto: o Archive deixa o mesmo
 * campo ter varios valores, e um item com dois titulos devolve os dois.
 * Sem isto o trim() rebentava com "array given" — e como so acontece em
 * alguns itens, passou despercebido ate a lista de candidatos crescer.
 */
function campo(array $doc, string $nome): string
{
	$valor = $doc[$nome] ?? '';

	if (is_array($valor)) {
		$valor = reset($valor) ?: '';
	}

	return trim((string) $valor);
}

/**
 * O endereco da procura no TMDB que corresponde a um item do Archive.
 *
 * Procura-se pelo titulo com o ano, que e o que separa os muitos filmes
 * com nomes iguais.
 */
function urlDaProcura(array $doc): string
{
	$query = ['language' => 'en-US', 'query' => campo($doc, 'title')];

	$ano = campo($doc, 'year');
	if ($ano !== '') {
		$query['year'] = (int) $ano;
	}

	return urlTmdb('search/movie', $query);
}

function serve(array $bruto): bool
{
	if (empty($bruto['poster_path']) || !empty($bruto['adult'])) {
		return false;
	}

	if ((int) ($bruto['vote_count'] ?? 0) < ARCHIVE_VOTOS_MINIMOS) {
		return false;
	}

	return !in_array(ARCHIVE_GENERO_EXCLUIDO, $bruto['genre_ids'] ?? [], true);
}

/* ---- filmes que nao tocam ----------------------------------------------- */

function falhados(): array
{
	$dados = daCache('classicos_falhados', ARCHIVE_ESQUECER_FALHA * 30) ?? [];
	$limite = time() - ARCHIVE_ESQUECER_FALHA;

	return array_filter($dados, fn($quando) => (int) $quando > $limite);
}

function anotarFalha(string $identificador): void
{
	$dados = falhados();
	$dados[$identificador] = time();

	paraCache('classicos_falhados', $dados);
}

/* ---- montagem ----------------------------------------------------------- */

function classicos(): array
{
	$cache = daCache('classicos', ARCHIVE_CACHE_SEGUNDOS);
	if ($cache !== null) {
		return $cache;
	}

	$docs = candidatos();
	if (!$docs) {
		/* Sem itens nao se grava nada em cache: uma falha do Archive nao
		   pode deixar a fila vazia durante uma semana. */
		return ['itens' => []];
	}

	/* So as procuras no TMDB, todas ao mesmo tempo. A ficha de cada item
	   do Archive fica para depois: e ela que traz o nome do ficheiro de
	   video, mas demora ate 18 segundos, e pedir 250 delas aqui punha
	   esta chamada a levar minutos — para depois deitar fora a maioria,
	   porque so um terco dos candidatos passa o crivo. */
	$procuras = pedirVarios(array_map('urlDaProcura', $docs), cabecalhosTmdb());

	$mapa = generos('movie');
	$itens = [];

	foreach ($docs as $n => $doc) {
		$identificador = campo($doc, 'identifier');
		if ($identificador === '') {
			continue;
		}

		$bruto = $procuras[$n]['results'][0] ?? null;

		if ($bruto === null || !serve($bruto)) {
			continue;
		}

		// o mesmo filme aparece no Archive em varias copias
		if (isset($itens[$bruto['id']])) {
			continue;
		}

		$item = normaliza($bruto, $mapa, 'movie');
		if ($item === null) {
			continue;
		}

		$item['arquivo'] = $identificador;

		$itens[$bruto['id']] = $item;
	}

	$resultado = ['itens' => array_values($itens)];
	paraCache('classicos', $resultado);

	return $resultado;
}

/* ---- accoes -------------------------------------------------------------- */

if (($_GET['action'] ?? '') === 'catalogo') {
	$dados = classicos();

	/* Os partidos saem aqui, e nao quando a fila for montada de novo: a
	   fila fica uma semana em cache, e ninguem espera uma semana para um
	   filme que nao toca deixar de aparecer. */
	$fora = falhados();
	if ($fora) {
		$dados['itens'] = array_values(array_filter(
			$dados['itens'],
			fn($i) => !isset($fora[$i['arquivo']])
		));
	}

	if (!$dados['itens']) {
		respond(['status' => 'error', 'error' => 'archive_indisponivel'], 502);
	}

	respond(['status' => 'ok'] + $dados);
}

/**
 * O endereco do ficheiro a tocar.
 */
if (($_GET['action'] ?? '') === 'video') {
	$arquivo = trim((string) ($_GET['arquivo'] ?? ''));

	if ($arquivo === '' || !preg_match('/^[A-Za-z0-9._-]{1,120}$/', $arquivo)) {
		respond(['status' => 'error', 'error' => 'arquivo_invalido'], 422);
	}

	$nome = 'video_' . $arquivo;

	$cache = daCache($nome, ARCHIVE_CACHE_VIDEO_SEGUNDOS);
	if ($cache !== null) {
		respond(['status' => 'ok'] + $cache);
	}

	$video = videoDoItem($arquivo);
	if ($video === null) {
		anotarFalha($arquivo);
		respond(['status' => 'error', 'error' => 'sem_video'], 502);
	}

	$dados = ['video' => $video];
	paraCache($nome, $dados);

	respond(['status' => 'ok'] + $dados);
}

respond(['status' => 'error', 'error' => 'accao_desconhecida'], 400);