<?php
/**
 * API магазина: /api/…
 *   GET  /api/product/{slug}            товар, пакеты, поля аккаунта
 *   POST /api/check-account             проверка ID у поставщика (если умеет)
 *   POST /api/orders                    создать заказ → ссылка на оплату
 *   GET  /api/orders/{uuid}             статус заказа для покупателя
 *   GET  /api/pay/{uuid}                форма перехода в платёжку (автоотправка)
 *   POST /api/pay/{provider}/result     уведомление платёжки об оплате
 *   GET  /api/cron?t=…                  повтор зависших закупок
 */

define( 'IGD', true );
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/payment.php';
require __DIR__ . '/lib/supply.php';
require __DIR__ . '/lib/orders.php';

$path   = trim( (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
$path   = preg_replace( '#^api/?#', '', $path );
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body   = static function (): array {
	$in = json_decode( (string) file_get_contents( 'php://input' ), true );
	return is_array( $in ) ? $in : array();
};

try {
	if ( 'GET' === $method && preg_match( '#^product/([a-z0-9-]{1,64})$#', $path, $m ) ) {
		$p = product_by_slug( $m[1] );
		$p ? json_out( product_public( $p ) ) : fail( 'Товар не найден', 404 );

	} elseif ( 'POST' === $method && 'check-account' === $path ) {
		$in = $body();
		$p  = product_by_slug( (string) ( $in['slug'] ?? '' ) );
		$a  = $p ? clean_account( $p, $in['account'] ?? null ) : null;
		if ( ! $a ) {
			fail( 'Проверьте данные аккаунта' );
		} else {
			$r = supply_check_account( $p, $a );
			json_out( $r ?? array( 'ok' => null ) );
		}

	} elseif ( 'POST' === $method && 'orders' === $path ) {
		$r = order_create( $body() );
		isset( $r['error'] ) ? fail( $r['error'], $r['code'] ?? 400 ) : json_out( $r, 201 );

	} elseif ( 'GET' === $method && preg_match( '#^orders/([0-9a-f]{32})$#', $path, $m ) ) {
		$o = order_by_uuid( $m[1] );
		$o ? json_out( order_public( $o ) ) : fail( 'Заказ не найден', 404 );

	} elseif ( 'GET' === $method && preg_match( '#^pay/([0-9a-f]{32})$#', $path, $m ) ) {
		$o = order_by_uuid( $m[1] );
		if ( ! $o || 'new' !== $o['status'] ) {
			header( 'Location: ' . cfg( 'site_url' ) . '/zakaz/?id=' . $m[1], true, 302 );
			exit;
		}
		$f = pay_form( $o );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		echo '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex"><title>Переход к оплате</title>';
		echo '<form id="f" method="' . $f['method'] . '" action="' . htmlspecialchars( $f['action'] ) . '">';
		foreach ( $f['fields'] as $k => $v ) {
			echo '<input type="hidden" name="' . htmlspecialchars( $k ) . '" value="' . htmlspecialchars( (string) $v ) . '">';
		}
		echo '<noscript><button>Перейти к оплате</button></noscript></form><script>document.getElementById("f").submit()</script>';

	} elseif ( 'POST' === $method && preg_match( '#^pay/([a-z]{2,20})/result$#', $path, $m ) ) {
		$v = pay_verify( $m[1], $_POST ?: $body() );
		if ( ! $v ) {
			log_line( 'pay', "bad signature from {$m[1]} " . client_ip() );
			http_response_code( 400 );
			echo 'bad sign';
		} elseif ( order_paid( $v[0], $v[1], $m[1] ) ) {
			echo $v[2]; // платёжка ждёт ровно этот ответ
			if ( function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request(); // закупка после ответа, чтобы платёжка не ждала поставщика
			}
			order_fulfill( $v[0] );
		} else {
			http_response_code( 400 );
			echo 'rejected';
		}

	} elseif ( 'GET' === $method && 'cron' === $path ) {
		if ( ! hash_equals( (string) cfg( 'cron_token', random_bytes( 16 ) ), (string) ( $_GET['t'] ?? '' ) ) ) {
			fail( 'not found', 404 );
		} else {
			$ids = q( "SELECT id FROM orders WHERE status = 'paid' AND updated_at < NOW() - INTERVAL 1 MINUTE LIMIT 20" )->fetchAll( PDO::FETCH_COLUMN );
			foreach ( $ids as $id ) {
				order_fulfill( (int) $id );
			}
			json_out( array( 'retried' => count( $ids ) ) );
		}

	} else {
		fail( 'Не найдено', 404 );
	}
} catch ( Throwable $e ) {
	$ref = bin2hex( random_bytes( 4 ) );
	log_line( 'exception', "$ref {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}" );
	fail( "Ошибка сервера, код $ref", 500 );
}
