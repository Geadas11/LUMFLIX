<?php
/**
 * [SESSAO] session.php
 * Diz quem esta autenticado e permite terminar a sessao. Serve as
 * paginas em .html (o catalogo, por exemplo), que por serem estaticas
 * nao conseguem ler a sessao do PHP por si.
 *
 *   GET             -> { authenticated, user, is_admin, tem_email }
 *   POST action=logout
 */
session_start();

require __DIR__ . '/users.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (($_POST['action'] ?? '') === 'logout') {
	$_SESSION = [];
	session_destroy();
	echo json_encode(['status' => 'ok']);
	exit;
}

$registo = !empty($_SESSION['user']) ? findUser($_SESSION['user']) : null;

echo json_encode([
	'status' => 'ok',
	'authenticated' => !empty($_SESSION['authenticated']),
	'user' => $_SESSION['user'] ?? null,
	'is_admin' => !empty($_SESSION['admin_authenticated']),
	// para as paginas poderem avisar quem ainda nao associou um email
	'tem_email' => $registo !== null && !empty($registo['email']),
]);
