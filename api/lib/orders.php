<?php
/**
 * Заказы. Переходы статусов атомарные (UPDATE … WHERE status = старый): повторное уведомление
 * платёжки или параллельный запуск cron не приведут к двойной закупке.
 */

defined( 'IGD' ) || exit;

const ORDER_PUBLIC_STATUS = array(
	'new'       => 'Ждёт оплаты',
	'paid'      => 'Оплачен, передаём поставщику',
	'supplying' => 'Зачисляем',
	'delivered' => 'Готово',
	'failed'    => 'Задержка: заказ проверяет оператор',
);

function product_by_slug( string $slug ): ?array {
	$p = q( 'SELECT * FROM products WHERE slug = ? AND active = 1', array( $slug ) )->fetch();
	return $p ?: null;
}

function product_public( array $p ): array {
	$packages = q( 'SELECT id, name, price_kop FROM packages WHERE product_id = ? AND active = 1 ORDER BY sort, price_kop', array( $p['id'] ) )->fetchAll();
	$fields   = array_map( static fn( $f ) => array_intersect_key( $f, array_flip( array( 'name', 'label', 'placeholder', 'pattern', 'hint' ) ) ), json_decode( $p['fields_json'], true ) ?: array() );
	return array(
		'slug'         => $p['slug'],
		'name'         => $p['name'],
		'fields'       => $fields,
		'accountCheck' => (bool) $p['account_check'],
		'packages'     => array_map( static fn( $k ) => array( 'id' => (int) $k['id'], 'name' => $k['name'], 'price' => (int) $k['price_kop'] / 100 ), $packages ),
	);
}

/** Поля аккаунта строго по описанию товара: лишние отбрасываются, обязательные проверяются шаблоном. */
function clean_account( array $product, $input ): ?array {
	$out = array();
	foreach ( json_decode( $product['fields_json'], true ) ?: array() as $f ) {
		$v = trim( (string) ( is_array( $input ) ? ( $input[ $f['name'] ] ?? '' ) : '' ) );
		if ( '' === $v || mb_strlen( $v ) > 64 || ( ! empty( $f['pattern'] ) && ! preg_match( '/' . str_replace( '/', '\/', $f['pattern'] ) . '/u', $v ) ) ) {
			return null;
		}
		$out[ $f['name'] ] = $v;
	}
	return $out ?: null;
}

function order_create( array $in ): array {
	$pkg = q( 'SELECT k.*, p.slug, p.name AS product_name FROM packages k JOIN products p ON p.id = k.product_id WHERE k.id = ? AND k.active = 1 AND p.active = 1', array( (int) ( $in['package_id'] ?? 0 ) ) )->fetch();
	if ( ! $pkg ) {
		return array( 'error' => 'Пакет не найден' );
	}
	$product = product_by_slug( $pkg['slug'] );
	$account = clean_account( $product, $in['account'] ?? null );
	if ( ! $account ) {
		return array( 'error' => 'Проверьте данные аккаунта' );
	}
	$email = trim( (string) ( $in['email'] ?? '' ) );
	if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) || strlen( $email ) > 190 ) {
		return array( 'error' => 'Укажите почту: на неё придёт чек' );
	}
	// Защита от перебора: не больше 8 новых заказов с одного адреса за 10 минут.
	$recent = (int) q( "SELECT COUNT(*) FROM orders WHERE ip = ? AND created_at > NOW() - INTERVAL 10 MINUTE", array( client_ip() ) )->fetchColumn();
	if ( $recent >= 8 ) {
		return array( 'error' => 'Слишком много заказов подряд. Попробуйте через несколько минут', 'code' => 429 );
	}
	$uuid = bin2hex( random_bytes( 16 ) );
	q(
		'INSERT INTO orders (uuid, package_id, title, price_kop, account_json, email, pay_provider, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
		array( $uuid, $pkg['id'], $pkg['product_name'] . ': ' . $pkg['name'], $pkg['price_kop'], json_encode( $account, JSON_UNESCAPED_UNICODE ), $email, cfg( 'pay_provider', 'robokassa' ), client_ip() )
	);
	$id = (int) db()->lastInsertId();
	event( $id, 'created' );
	return array( 'uuid' => $uuid, 'pay_url' => cfg( 'site_url' ) . '/api/pay/' . $uuid );
}

function order_by_uuid( string $uuid ): ?array {
	if ( ! preg_match( '/^[0-9a-f]{32}$/', $uuid ) ) {
		return null;
	}
	$o = q( 'SELECT * FROM orders WHERE uuid = ?', array( $uuid ) )->fetch();
	return $o ?: null;
}

function order_public( array $o ): array {
	return array(
		'title'    => $o['title'],
		'price'    => (int) $o['price_kop'] / 100,
		'status'   => $o['status'],
		'label'    => ORDER_PUBLIC_STATUS[ $o['status'] ] ?? $o['status'],
		'delivery' => 'delivered' === $o['status'] ? $o['delivery'] : null,
		'created'  => $o['created_at'],
	);
}

function order_move( int $id, string $from, string $to, array $set = array() ): bool {
	$cols = '';
	foreach ( array_keys( $set ) as $c ) {
		$cols .= ", $c = ?";
	}
	$st = q( "UPDATE orders SET status = ?$cols WHERE id = ? AND status = ?", array_merge( array( $to ), array_values( $set ), array( $id, $from ) ) );
	return 1 === $st->rowCount();
}

/** Уведомление об оплате: проверить сумму, перевести в paid. Повторное уведомление — тот же ответ. */
function order_paid( int $id, int $sum_kop, string $provider, string $ref = '' ): bool {
	$o = q( 'SELECT * FROM orders WHERE id = ?', array( $id ) )->fetch();
	if ( ! $o || $o['pay_provider'] !== $provider ) {
		log_line( 'pay', "unknown order $id from $provider" );
		return false;
	}
	if ( $sum_kop < (int) $o['price_kop'] ) {
		log_line( 'pay', "order $id underpaid: $sum_kop < {$o['price_kop']}" );
		notify_owner( "Заказ $id: сумма оплаты меньше цены ($sum_kop < {$o['price_kop']} коп.)" );
		return false;
	}
	if ( order_move( $id, 'new', 'paid', array( 'paid_at' => gmdate( 'Y-m-d H:i:s' ), 'pay_ref' => $ref ?: null ) ) ) {
		event( $id, 'paid', array( 'sum' => $sum_kop ) );
	}
	return true;
}

/** Закупка у поставщика. Вызывается после оплаты и из cron для зависших. */
function order_fulfill( int $id ): void {
	if ( ! order_move( $id, 'paid', 'supplying' ) ) {
		return; // уже обрабатывается или не оплачен
	}
	$o       = q( 'SELECT * FROM orders WHERE id = ?', array( $id ) )->fetch();
	$package = q( 'SELECT * FROM packages WHERE id = ?', array( $o['package_id'] ) )->fetch();
	$product = q( 'SELECT * FROM products WHERE id = ?', array( $package['product_id'] ) )->fetch();
	try {
		$r = supply_place( $o, $product, $package );
	} catch ( Throwable $e ) {
		$r = array( 'state' => 'failed', 'error' => 'exception: ' . $e->getMessage() );
	}
	event( $id, 'supply', $r );
	if ( 'delivered' === $r['state'] ) {
		order_move( $id, 'supplying', 'delivered', array( 'supply_ref' => $r['ref'] ?? null, 'delivery' => $r['delivery'] ?? '', 'delivered_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	} elseif ( 'pending' === $r['state'] ) {
		q( 'UPDATE orders SET supply_ref = ? WHERE id = ?', array( $r['ref'] ?? null, $id ) );
	} else {
		order_move( $id, 'supplying', 'failed', array( 'error' => mb_substr( (string) ( $r['error'] ?? '' ), 0, 500 ) ) );
		notify_owner( "Заказ $id ({$o['title']}, " . rub( (int) $o['price_kop'] ) . " ₽) оплачен, но поставщик не выполнил: " . ( $r['error'] ?? '' ) . '. Нужен ручной разбор или возврат.' );
	}
}
