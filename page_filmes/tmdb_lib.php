<?php
/**
 * [BIBLIOTECA-TMDB] tmdb_lib.php
 * Tudo o que e proprio do TMDB: que enderecos se chamam e como se
 * transforma o que vem de la.
 *
 * Nao responde a pedidos nenhuns — isso e do tmdb.php. Fica separado
 * porque o archive.php tambem precisa de falar com o TMDB, para ir
 * buscar os cartazes e as sinopses dos classicos que encontra no
 * Internet Archive.
 */

require_once __DIR__ . '/proxy_comum.php';
require_once __DIR__ . '/tmdb_config.php';

/** O cabecalho que autentica: o token viaja aqui e nao no endereco. */
function cabecalhosTmdb(): array
{
	return ['Authorization: Bearer ' . TMDB_TOKEN];
}

function urlTmdb(string $caminho, array $query = []): string
{
	$url = 'https://api.themoviedb.org/3/' . ltrim($caminho, '/');

	return $query ? $url . '?' . http_build_query($query) : $url;
}

function tmdb(string $caminho, array $query = []): ?array
{
	return pedirUm(urlTmdb($caminho, $query), cabecalhosTmdb());
}

/**
 * Varios pedidos ao TMDB ao mesmo tempo.
 *
 * @param array $pedidos lista de [caminho, query]
 */
function tmdbVarios(array $pedidos): array
{
	$urls = [];
	foreach ($pedidos as $n => [$caminho, $query]) {
		$urls[$n] = urlTmdb($caminho, $query);
	}

	$respostas = pedirVarios($urls, cabecalhosTmdb());
	ksort($respostas);

	return $respostas;
}

/* ---- do TMDB para a forma que as paginas desenham --------------------- */

/**
 * A TMDB devolve os generos como numeros; esta e a tabela que os traduz.
 * Os nomes sao ajustados aos que o site ja usava.
 */
function generos(string $tipo): array
{
	$dados = tmdb("genre/$tipo/list", ['language' => 'en-US']);
	$mapa = [];

	foreach ($dados['genres'] ?? [] as $g) {
		$nome = $g['name'];
		if ($nome === 'Science Fiction') {
			$nome = 'Sci-Fi';
		}
		$mapa[$g['id']] = $nome;
	}

	return $mapa;
}

/**
 * Passa um resultado da TMDB para a forma que as paginas ja desenham.
 * A duracao e a classificacao etaria nao vem nas listagens: sao pedidas
 * a parte, pela accao "detalhe", so quando se abre a ficha.
 */
function normaliza(array $bruto, array $mapa, string $tipo): ?array
{
	$titulo = $bruto['title'] ?? $bruto['name'] ?? '';
	$data = $bruto['release_date'] ?? $bruto['first_air_date'] ?? '';

	if ($titulo === '') {
		return null;
	}

	$categorias = [];
	foreach ($bruto['genre_ids'] ?? [] as $id) {
		if (isset($mapa[$id])) {
			$categorias[] = $mapa[$id];
		}
	}

	return [
		'id' => $bruto['id'],
		'tipo' => $tipo,
		'titulo' => $titulo,
		'categorias' => $categorias,
		'ano' => $data !== '' ? (int) substr($data, 0, 4) : null,
		'sinopse' => $bruto['overview'] ?? '',
		'cartaz' => !empty($bruto['poster_path']) ? TMDB_IMAGENS . $bruto['poster_path'] : null,
		/* imagem larga, para o fundo do destaque; o cartaz e vertical e
		   esticado a largura do ecra ficava irreconhecivel */
		'fundo' => !empty($bruto['backdrop_path']) ? TMDB_FUNDOS . $bruto['backdrop_path'] : null,
		'nota' => round((float) ($bruto['vote_average'] ?? 0), 1),
	];
}

/**
 * Os pedidos que formam o catalogo, pela ordem em que interessam.
 *
 * Os primeiros sao os populares — e a fila "Popular now" e tambem a base
 * do resto. Depois vem os melhor pontuados, que e por onde entram os
 * classicos: a lista do que e popular hoje so tem filmes recentes. E por
 * fim vai-se genero a genero, senao havia generos com tres ou quatro
 * titulos e a fila ficava mais curta que uma pagina de cartazes.
 */
function fontes(string $tipo, array $mapa): array
{
	$pedidos = [];
	$comum = ['language' => 'en-US'];

	for ($p = 1; $p <= TMDB_PAGINAS_POPULARES; $p++) {
		$pedidos[] = ["$tipo/popular", $comum + ['page' => $p]];
	}

	for ($p = 1; $p <= TMDB_PAGINAS_TOPO; $p++) {
		$pedidos[] = ["$tipo/top_rated", $comum + ['page' => $p]];
	}

	foreach (array_keys($mapa) as $idGenero) {
		for ($p = 1; $p <= TMDB_PAGINAS_POR_GENERO; $p++) {
			$pedidos[] = ["discover/$tipo", $comum + [
				'page' => $p,
				'with_genres' => $idGenero,
				'sort_by' => 'popularity.desc',
				'vote_count.gte' => TMDB_VOTOS_MINIMOS,
			]];
		}
	}

	return $pedidos;
}

function catalogo(string $tipo): array
{
	$cache = daCache("catalogo_$tipo", TMDB_CACHE_SEGUNDOS);
	if ($cache !== null) {
		return $cache;
	}

	$mapa = generos($tipo);
	$pedidos = fontes($tipo, $mapa);
	$respostas = tmdbVarios($pedidos);

	/* Indexado pelo id: o mesmo titulo vem em varias respostas (esta nos
	   populares e em cada um dos seus generos) e so deve ficar uma vez.
	   Aparecer em varias filas e outra coisa: isso decide-se na pagina,
	   a partir do "categorias" de cada item. */
	$itens = [];
	$populares = [];

	foreach ($respostas as $n => $dados) {
		foreach ($dados['results'] ?? [] as $bruto) {
			$item = normaliza($bruto, $mapa, $tipo);
			if ($item === null) {
				continue;
			}

			// o pedido 0 e a primeira pagina dos populares
			if ($n === 0) {
				$populares[] = $item['id'];
			}

			$itens[$item['id']] = $item;
		}
	}

	$resultado = ['itens' => array_values($itens), 'populares' => $populares];
	paraCache("catalogo_$tipo", $resultado);

	return $resultado;
}

/**
 * Procura em todo o TMDB, nao so no que o catalogo trouxe.
 *
 * E o que faz um titulo antigo como o Casablanca ser alcancavel: nunca
 * aparece nas listas do que e popular, mas esta la e por aqui chega-se a
 * ele. Guarda-se cada procura em cache, porque a mesma palavra escrita
 * outra vez nao precisa de novo pedido.
 */
function busca(string $tipo, string $termo): array
{
	$nome = "busca_{$tipo}_" . md5(mb_strtolower($termo));

	$cache = daCache($nome, TMDB_CACHE_SEGUNDOS);
	if ($cache !== null) {
		return $cache;
	}

	$mapa = generos($tipo);
	$itens = [];

	// duas paginas: 40 resultados chegam de sobra para uma procura
	$respostas = tmdbVarios([
		["search/$tipo", ['language' => 'en-US', 'query' => $termo, 'page' => 1]],
		["search/$tipo", ['language' => 'en-US', 'query' => $termo, 'page' => 2]],
	]);

	foreach ($respostas as $dados) {
		foreach ($dados['results'] ?? [] as $bruto) {
			$item = normaliza($bruto, $mapa, $tipo);
			if ($item !== null) {
				$itens[$item['id']] = $item;
			}
		}
	}

	$resultado = ['itens' => array_values($itens)];
	paraCache($nome, $resultado);

	return $resultado;
}

/**
 * Classificacao etaria do pais pedido, com queda para os EUA — nem todos
 * os titulos tem classificacao portuguesa.
 */
function classificacao(array $dados, string $tipo): ?string
{
	if ($tipo === 'movie') {
		foreach ($dados['release_dates']['results'] ?? [] as $pais) {
			if (!in_array($pais['iso_3166_1'] ?? '', ['PT', 'US'], true)) {
				continue;
			}
			foreach ($pais['release_dates'] ?? [] as $lancamento) {
				if (!empty($lancamento['certification'])) {
					return $lancamento['certification'];
				}
			}
		}

		return null;
	}

	foreach ($dados['content_ratings']['results'] ?? [] as $pais) {
		if (in_array($pais['iso_3166_1'] ?? '', ['PT', 'US'], true) && !empty($pais['rating'])) {
			return $pais['rating'];
		}
	}

	return null;
}
