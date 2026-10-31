<?php
/**
 * Общие функции: настройки, база, ответы, журнал, уведомления владельцу.
 * Настройки и секреты лежат вне выкладки: /.private/config.php (его не трогает _pull.php).
 */

defined( 'IGD' ) || exit;

function cfg( string $key, $default = null ) {
	static $c = null;
	if ( null === $c ) {
		$file = getenv( 'IGD_CONFIG' ) ?: dirname( __DIR__, 2 ) . '/.private/config.php';
		$c    = is_file( $file ) ? require $file : array();
	}
	return $c[ $key ] ?? $default;
}

function db(): PDO {
	static $pdo = null;
	if ( null === $pdo ) {
		$pdo = new PDO(
			cfg( 'db_dsn' ),
			cfg( 'db_user' ),
			cfg( 'db_pass' ),
			array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false )
		);
	}
	return $pdo;
}

function q( string $sql, array $args = array() ): PDOStatement {
	$st = db()->prepare( $sql );
	$st->execute( $args );
	return $st;
}

function json_out( $data, int $code = 200 ): void {
	http_response_code( $code );
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Cache-Control: no-store' );
	echo json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}

/** Ошибка для покупателя без внутренностей; подробности — в журнал. */
function fail( string $public, int $code = 400, string $log = '' ): void {
	if ( $log ) {
		log_line( 'error', $log );
	}
	json_out( array( 'error' => $public ), $code );
}

function log_line( string $type, string $msg ): void {
	$dir = dirname( __DIR__, 2 ) . '/.private/logs';
	is_dir( $dir ) || @mkdir( $dir, 0700, true );
	$line = gmdate( 'c' ) . " [$type] " . str_replace( array( "\r", "\n" ), ' ', $msg ) . "\n";
	@file_put_contents( $dir . '/api-' . gmdate( 'Y-m' ) . '.log', $line, FILE_APPEND | LOCK_EX );
}

function event( int $order_id, string $type, $data = null ): void {
	q( 'INSERT INTO order_events (order_id, type, data) VALUES (?, ?, ?)', array( $order_id, $type, null === $data ? null : json_encode( $data, JSON_UNESCAPED_UNICODE ) ) );
}

/** Сообщение владельцу в Telegram: оплата, сбой поставщика, ручной разбор. Без токена — только журнал. */
function notify_owner( string $text ): void {
	log_line( 'notify', $text );
	$token = cfg( 'tg_token' );
	$chat  = cfg( 'tg_chat' );
	if ( ! $token || ! $chat ) {
		return;
	}
	$ch = curl_init( "https://api.telegram.org/bot$token/sendMessage" );
	curl_setopt_array( $ch, array( CURLOPT_POST => true, CURLOPT_POSTFIELDS => array( 'chat_id' => $chat, 'text' => $text ), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5 ) );
	curl_exec( $ch );
}

function client_ip(): string {
	return substr( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ), 0, 45 );
}

function rub( int $kop ): string {
	return number_format( $kop / 100, 2, '.', '' );
}
