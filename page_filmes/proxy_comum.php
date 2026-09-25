<?php
/**
 * [PROXY-COMUM] proxy_comum.php
 * O que todos os proxies deste site precisam, sem saber de nenhum deles.
 *
 * Existem dois — o tmdb.php e o archive.php — e ambos fazem o mesmo a
 * volta do que os distingue: verificar a sessao, chamar um servico de
 * fora, guardar a resposta em cache e devolver JSON. Isso vive aqui, e
 * cada proxy fica so com o que e proprio dele: que enderecos chama e
 * como transforma o que vem de la.
 *
 * Nao sabe o que e o TMDB nem o que e o Internet Archive. Se souber, e
 * porque alguma coisa foi parar ao sitio errado.
 */

/*
 * Certificados para validar HTTPS.
 *
 * O PHP do WAMP vem sem conjunto de certificados configurado, por isso
 * qualquer chamada a uma API falha com "unable to get local issuer
 * certificate". Desligar a verificacao calava o erro e deitava fora a
 * seguranca do HTTPS; em vez disso traz-se o ficheiro para o projecto.
 */
const PROXY_CERTIFICADOS = __DIR__ . '/cacert.pem';

// Quantos pedidos de cada vez quando se pede a varios enderecos. Em fila
// indiana cada um passa o tempo todo a espera da resposta; em paralelo
// esperam todos ao mesmo tempo.
const PROXY_EM_PARALELO = 12;

/*
 * Quanto tempo esperar por uma resposta.
 *
 * O valor de base serve para APIs rapidas. O Internet Archive nao e uma
 * delas: a ficha de um item leva entre 6 e 18 segundos, e com 12 aqui
 * fixos metade das chamadas era cortada a meio sem dar erro nenhum —
 * apareciam simplesmente menos filmes do que deviam.
 */
const PROXY_ESPERA_SEGUNDOS = 12;

/**
 * Devolve JSON e termina.
 *
 * Comprime quando o browser aceita, porque estas respostas sao grandes
 * (o catalogo passa dos 400 KB) e o Apache do WAMP vem sem o mod_deflate
 * ligado. A cache do servidor poupa as chamadas ao servico de fora, mas
 * nao poupava a transferencia — e essa e a parte que se sente num
 * telemovel.
 *
 * O "private" e importante: isto e conteudo de quem tem sessao aberta e
 * nao pode ficar guardado em nenhuma cache pelo caminho.
 */
function respond(array $payload, int $code = 200): void
{
	http_response_code($code);
	header('Content-Type: application/json');
	header('Cache-Control: private, max-age=600');

	$json = json_encode($payload);
	$aceita = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';

	if (function_exists('gzencode') && str_contains($aceita, 'gzip')) {
		$comprimido = gzencode($json, 6);

		if ($comprimido !== false) {
			header('Content-Encoding: gzip');
			header('Vary: Accept-Encoding');
			$json = $comprimido;
		}
	}

	header('Content-Length: ' . strlen($json));
	echo $json;
	exit;
}

/**
 * Corta quem nao tem sessao.
 *
 * Nenhum destes proxies e para estar aberto a internet: um deles gasta
 * uma credencial nossa a cada chamada, e a credencial tem limites.
 */
function exigirSessao(): void
{
	if (empty($_SESSION['authenticated'])) {
		respond(['status' => 'error', 'error' => 'sem_sessao'], 401);
	}
}

/* ---- cache em ficheiro ------------------------------------------------ */

function caminhoCache(string $nome): string
{
	$pasta = __DIR__ . '/cache';
	if (!is_dir($pasta)) {
		mkdir($pasta, 0770, true);
	}

	return $pasta . '/' . preg_replace('/[^a-z0-9_-]/i', '', $nome) . '.json';
}

function daCache(string $nome, int $segundos): ?array
{
	$ficheiro = caminhoCache($nome);

	if (!is_file($ficheiro) || filemtime($ficheiro) < time() - $segundos) {
		return null;
	}

	$dados = json_decode(file_get_contents($ficheiro), true);

	return is_array($dados) ? $dados : null;
}

function paraCache(string $nome, array $dados): void
{
	file_put_contents(caminhoCache($nome), json_encode($dados), LOCK_EX);
}

/* ---- chamadas a servicos de fora -------------------------------------- */

function opcoesHttp(string $url, array $cabecalhos, int $espera = PROXY_ESPERA_SEGUNDOS): array
{
	return [
		CURLOPT_URL => $url,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => $espera,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_CAINFO => PROXY_CERTIFICADOS,
		CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $cabecalhos),
	];
}

/**
 * Um pedido. Devolve null se falhar — quem chama decide o que fazer.
 */
function pedirUm(string $url, array $cabecalhos = [], int $espera = PROXY_ESPERA_SEGUNDOS): ?array
{
	$ch = curl_init();
	curl_setopt_array($ch, opcoesHttp($url, $cabecalhos, $espera));

	$corpo = curl_exec($ch);
	$estado = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	curl_close($ch);

	if ($corpo === false || $estado !== 200) {
		return null;
	}

	$dados = json_decode($corpo, true);

	return is_array($dados) ? $dados : null;
}

/**
 * Varios pedidos ao mesmo tempo.
 *
 * Devolve as respostas com as mesmas chaves dos pedidos, e null onde
 * falhou: uma falha isolada nao pode deitar abaixo a resposta inteira.
 *
 * @param array $pedidos enderecos, ou ['url' => .., 'cabecalhos' => [..]]
 */
function pedirVarios(array $pedidos, array $cabecalhosComuns = [], int $espera = PROXY_ESPERA_SEGUNDOS): array
{
	$respostas = [];

	foreach (array_chunk($pedidos, PROXY_EM_PARALELO, true) as $punhado) {
		$multi = curl_multi_init();
		$abertos = [];

		foreach ($punhado as $chave => $pedido) {
			$url = is_array($pedido) ? $pedido['url'] : $pedido;
			$cabecalhos = is_array($pedido)
				? ($pedido['cabecalhos'] ?? $cabecalhosComuns)
				: $cabecalhosComuns;

			$ch = curl_init();
			curl_setopt_array($ch, opcoesHttp($url, $cabecalhos, $espera));
			curl_multi_add_handle($multi, $ch);
			$abertos[$chave] = $ch;
		}

		// corre ate nao haver nada a espera de resposta
		do {
			$estado = curl_multi_exec($multi, $activos);
			if ($activos) {
				curl_multi_select($multi, 1.0);
			}
		} while ($activos && $estado === CURLM_OK);

		foreach ($abertos as $chave => $ch) {
			$corpo = curl_multi_getcontent($ch);
			$codigo = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			$dados = ($corpo !== null && $codigo === 200) ? json_decode($corpo, true) : null;

			$respostas[$chave] = is_array($dados) ? $dados : null;

			curl_multi_remove_handle($multi, $ch);
			curl_close($ch);
		}

		curl_multi_close($multi);
	}

	return $respostas;
}
