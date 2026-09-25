<?php
/**
 * [LOGICA-2FA] Totp.php
 * Geração e verificação de códigos TOTP (compatíveis com Google Authenticator).
 * Usado por: register/register.php (indiretamente via users.php),
 * 2fa/setup.php e login/auth.php.
 */

function base32Decode(string $value): string
{
	$value = strtoupper($value);
	$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	$bits = '';
	$output = '';

	for ($index = 0, $length = strlen($value); $index < $length; $index++) {
		$position = strpos($alphabet, $value[$index]);
		if ($position === false) {
			continue;
		}
		$bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
	}

	foreach (str_split($bits, 8) as $chunk) {
		if (strlen($chunk) === 8) {
			$output .= chr(bindec($chunk));
		}
	}

	return $output;
}

function base32Encode(string $binary): string
{
	$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	$bits = '';
	foreach (str_split($binary) as $char) {
		$bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
	}

	$output = '';
	foreach (str_split($bits, 5) as $chunk) {
		if (strlen($chunk) < 5) {
			$chunk = str_pad($chunk, 5, '0');
		}
		$output .= $alphabet[bindec($chunk)];
	}

	return $output;
}

/**
 * Gera um novo secret aleatório em Base32, pronto a usar no Google Authenticator.
 */
function generateTotpSecret(int $bytes = 10): string
{
	return base32Encode(random_bytes($bytes));
}

function verifyTotp(string $secret, string $code, int $window = 1): bool
{
	$code = preg_replace('/\D+/', '', $code);
	if (strlen($code) !== 6) {
		return false;
	}

	$key = base32Decode($secret);
	$timeSlice = (int) floor(time() / 30);

	for ($offset = -$window; $offset <= $window; $offset++) {
		$binaryTime = pack('N*', 0) . pack('N*', $timeSlice + $offset);
		$hash = hash_hmac('sha1', $binaryTime, $key, true);
		$offsetValue = ord(substr($hash, -1)) & 0x0F;
		$part = substr($hash, $offsetValue, 4);
		$value = unpack('N', $part)[1] & 0x7FFFFFFF;
		$totp = str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);

		if (hash_equals($totp, $code)) {
			return true;
		}
	}

	return false;
}

/**
 * Constrói o URI otpauth:// usado para gerar o QR code de configuração.
 */
function buildOtpAuthUri(string $secret, string $user, string $issuer = 'LumFlix'): string
{
	$label = rawurlencode($issuer) . ':' . rawurlencode($user);
	$query = http_build_query([
		'secret' => $secret,
		'issuer' => $issuer,
	]);

	return "otpauth://totp/{$label}?{$query}";
}
