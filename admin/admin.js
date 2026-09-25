/**
 * [ADMIN] admin.js
 * Peças partilhadas pelas duas páginas de administração: guarda de
 * acesso, chamadas ao admin.php e formatação das células.
 *
 * Cada página só define o que desenha; tudo o resto vive aqui.
 */
const Admin = (() => {
	const LOGIN_URL = '../authentication_page/Login/auth.html';
	let token = '';

	const $ = (id) => document.getElementById(id);

	/* Nomes de utilizador vêm do registo e aparecem em HTML: escapar é
	   obrigatório, mesmo com a validação que o register.php já faz. */
	function esc(value) {
		return String(value ?? '').replace(/[&<>"']/g, (c) => ({
			'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
		}[c]));
	}

	function fmtDate(iso) {
		if (!iso) return '—';
		const d = new Date(iso);
		if (Number.isNaN(d.getTime())) return '—';
		return d.toLocaleString('en-GB', {
			day: '2-digit', month: '2-digit', year: 'numeric',
			hour: '2-digit', minute: '2-digit',
		});
	}

	const STATUS_LABEL = {
		pending: 'Pending',
		approved: 'Approved',
		denied: 'Denied',
	};

	function statusTag(status) {
		const label = STATUS_LABEL[status] || status;
		return `<span class="tag tag-${esc(status)}">${esc(label)}</span>`;
	}

	function boolTag(on, yes, no) {
		return on
			? `<span class="tag tag-approved">${esc(yes)}</span>`
			: `<span class="tag tag-muted">${esc(no)}</span>`;
	}

	async function api(action, params) {
		const isWrite = params !== undefined;
		const url = isWrite ? 'admin.php' : `admin.php?action=${encodeURIComponent(action)}`;

		const options = {
			headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
			credentials: 'same-origin',
		};

		if (isWrite) {
			const body = new FormData();
			body.append('action', action);
			body.append('token', token);
			Object.entries(params).forEach(([k, v]) => body.append(k, v));
			options.method = 'POST';
			options.body = body;
		}

		const resp = await fetch(url, options);
		return resp.json();
	}

	/* Distingue "sem sessao" de "nem sequer cheguei ao servidor": sem
	   isto, abrir a pagina como ficheiro dava o portao de acesso negado,
	   que manda tratar de uma coisa que nao e o problema. */
	function reachError() {
		return location.protocol === 'file:' ? 'file_protocol' : 'unreachable';
	}

	const ERRORS = {
		not_admin: 'The panel session expired. Sign in again.',
		file_protocol: 'This page was opened as a file (file://), so PHP does not run. Open it through WAMP: http://localhost/Netflix/admin/',
		unreachable: 'Could not reach admin.php. Check that WAMP is running.',
		bad_credentials: 'Wrong username or password.',
		bad_token: 'Your session expired. Reload the page.',
		user_not_found: 'That account no longer exists.',
		method_not_allowed: 'Invalid request.',
		unknown_action: 'Unknown action.',
		default: 'Something went wrong. Try again.',
	};

	function notice(message, isBad) {
		const box = $('notice');
		if (!box) return;
		box.textContent = message;
		box.classList.toggle('is-bad', !!isBad);
		box.classList.add('show');
	}

	function noticeError(code) {
		notice(ERRORS[code] || ERRORS.default, true);
	}

	function clearNotice() {
		const box = $('notice');
		if (box) box.classList.remove('show');
	}

	/**
	 * Carrega as contas e, de caminho, confirma que a sessão é de
	 * administrador. Se não for, mostra o portão em vez da página —
	 * não redireciona, para não apagar um erro que valha a pena ler.
	 */
	async function load() {
		let res;
		try {
			res = await api('list');
		} catch (err) {
			$('app').classList.remove('hidden');
			noticeError(reachError());
			return { ok: false };
		}

		if (res.status !== 'ok') {
			$('gate').classList.remove('hidden');
			$('app').classList.add('hidden');
			return { ok: false };
		}

		token = res.token;
		$('gate').classList.add('hidden');
		$('app').classList.remove('hidden');
		$('whoami').textContent = res.user;

		return { ok: true, users: res.users, stats: res.stats, me: res.user };
	}

	function wireLogout() {
		const btn = $('logout');
		if (!btn) return;
		btn.addEventListener('click', async () => {
			try { await api('logout', {}); } catch (err) { /* sai na mesma */ }
			window.location.href = LOGIN_URL;
		});
	}

	return { $, esc, fmtDate, statusTag, boolTag, api, notice, noticeError, clearNotice, load, wireLogout, LOGIN_URL };
})();
