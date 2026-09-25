/**
 * [NAVEGACAO] nav.js
 * Transicao entre o login e o registo: a diagonal que separa as credenciais
 * da mensagem de boas-vindas tomba sobre as credenciais (vertice de baixo
 * imovel, o de cima e que roda) ate o cartao ficar todo dourado; a pagina
 * troca por tras dela e do outro lado a barra levanta-se a revelar as
 * credenciais, que agora estao no lado oposto.
 *
 * O movimento esta todo no CSS (.sweep-bar); aqui ligam-se as classes,
 * adia-se a navegacao ate a barra fechar, passa-se a marca de uma pagina
 * para a outra pelo sessionStorage e mede-se o angulo de repouso da
 * diagonal, que depende da largura e da altura reais do cartao. Se fosse
 * um valor fixo no CSS, a barra nao assentava em cima da diagonal e
 * via-se um salto no primeiro frame.
 */
(function () {
	var KEY = 'auth:sweep';
	var OUT_MS = 700;    /* descida da barra */
	var IN_MS = 780;     /* subida da barra */
	var TEXT_MS = 520;   /* entrada do texto */
	var TEXT_DELAY = 700;
	var NARROW = '(max-width: 760px)';

	/* Abaixo de 760px o cartao empilha-se e deixa de haver diagonal: a
	   transicao acontece na mesma, mas como cortina (ver style.css), e por
	   isso nao ha angulo nenhum para medir. */
	function isNarrow() {
		return window.matchMedia && window.matchMedia(NARROW).matches;
	}

	/* Inclinacao da diagonal em repouso, medida a partir do painel dourado
	   — assim continua certa se os flex dos paineis ou o --slant mudarem. */
	function measure(card) {
		var glow = card.querySelector('.glow-panel');
		if (!glow || !card.querySelector('.sweep-face')) return false;

		var c = card.getBoundingClientRect();
		var g = glow.getBoundingClientRect();
		if (!c.height || !g.width) return false;

		var slant = parseFloat(getComputedStyle(card).getPropertyValue('--slant')) || 60;
		/* percurso horizontal da diagonal, de cima ate baixo */
		var run = (slant / 100) * g.width;
		var angle = Math.atan2(run, c.height) * 180 / Math.PI;
		if (!card.classList.contains('art-left')) angle = -angle;

		card.style.setProperty('--bar-rest', angle.toFixed(2) + 'deg');
		return true;
	}

	/* --- chegada: a pagina abre tapada, a barra levanta-se -------------- */
	var root = document.documentElement;
	var arriving = false;
	try {
		arriving = !!sessionStorage.getItem(KEY);
		if (arriving) sessionStorage.removeItem(KEY);
	} catch (err) {
		/* sessionStorage bloqueado (modo privado, cookies off) */
	}

	if (arriving) {
		/* a classe entra ja aqui, no <head>: se esperasse pelo DOM pronto,
		   o formulario aparecia uns frames antes de a barra o tapar */
		root.classList.add('sweep-in');

		document.addEventListener('DOMContentLoaded', function () {
			var card = document.querySelector('.split-card');
			/* se algo falhar, destapa em vez de deixar o cartao dourado */
			if (!card || !card.querySelector('.sweep-bar')) {
				root.classList.remove('sweep-in');
				return;
			}
			/* o angulo so interessa a diagonal; na cortina nao se usa */
			if (!isNarrow()) measure(card);
			/* o texto e o ultimo a assentar; limpar no animationend da
			   barra cortava-o a meio, porque o evento borbulha */
			setTimeout(function () {
				root.classList.remove('sweep-in', 'sweep-go');
			}, Math.max(IN_MS, TEXT_DELAY + TEXT_MS) + 80);
			/* dois frames para o browser assentar o estado tapado antes de
			   a animacao arrancar */
			requestAnimationFrame(function () {
				requestAnimationFrame(function () {
					root.classList.add('sweep-go');
				});
			});
		});
	}

	/* --- saida: a barra tomba e so depois e que se navega --------------- */
	document.addEventListener('click', function (ev) {
		/* ctrl/cmd/shift ou botao do meio abrem noutro separador: animar a
		   pagina atual seria errado */
		if (ev.defaultPrevented || ev.button !== 0) return;
		if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) return;

		var target = ev.target;
		if (!target || !target.closest) return;

		/* rodape (login <-> registo) e os botoes da caixa de sucesso
		   (registo -> 2fa, 2fa -> login): sao todos saltos entre estas
		   paginas, logo todos levam a barra */
		var link = target.closest('.form-footer a[href], .success a[href]');
		if (!link) return;

		var card = document.querySelector('.split-card');
		if (!card || !card.querySelector('.sweep-bar')) return;
		if (!isNarrow()) measure(card);

		ev.preventDefault();
		try { sessionStorage.setItem(KEY, '1'); } catch (err) {}

		var href = link.href;
		var done = false;
		var go = function () {
			if (done) return;
			done = true;
			window.location.href = href;
		};

		card.addEventListener('animationend', function (ev) {
			/* so a barra manda: o animationend do WELCOME chega primeiro
			   e navegava a meio da queda */
			if (ev.target && ev.target.classList.contains('sweep-bar')) go();
		});
		/* rede de seguranca: sem animationend (animacoes desligadas no SO,
		   separador em fundo) o link ficava morto */
		setTimeout(go, OUT_MS + 140);

		card.classList.add('sweep-out');
	});
})();
