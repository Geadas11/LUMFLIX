<?php
/**
 * [CONFIG-ADMIN] admin_config.php
 * Credenciais do painel de administracao. Sao independentes das contas
 * de utilizador: entrar aqui nao e entrar no site, e vice-versa.
 *
 * Para mudar a password, gera um hash novo e cola-o aqui:
 *   php -r "echo password_hash('a-tua-password', PASSWORD_DEFAULT);"
 */

const ADMIN_USER = 'admin';

// Guardada em hash mesmo sendo simples, para nao ficar em claro no disco.
const ADMIN_PASSWORD_HASH = '$2y$10$QAcXzYmMKzXwLU0Vp76fSen1mf/8zBerNLDPLpmJ5y4YgVc9KFDdy';
