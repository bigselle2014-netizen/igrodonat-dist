<?php
/**
 * Платёжные системы. Сейчас — Робокасса (docs.robokassa.ru/ru/pay-interface, notifications-and-redirects,
 * fiscalization; сверено 09.10.2026) и mock для проверки цепочки без реальной оплаты.
 * Новая платёжка = ещё одна пара функций pay_form_* / pay_verify_*.
 */

defined( 'IGD' ) || exit;

/** Поля и адрес формы оплаты для заказа. */
function pay_form( array $order ): array {
	return match ( $order['pay_provider'] ) {
		'robokassa' => pay_form_robokassa( $order ),
		'mock'      => array( 'action' => cfg( 'site_url' ) . '/api/pay/mock/result', 'fields' => array( 'InvId' => $order['id'], 'OutSum' => rub( (int) $order['price_kop'] ) ), 'method' => 'post' ),
	};
}

/**
 * Проверка серверного уведомления об оплате. Возвращает [order_id, сумма в копейках, ответ платёжке] или null.
 */
function pay_verify( string $provider, array $req ): ?array {
	return match ( $provider ) {
		'robokassa' => pay_verify_robokassa( $req ),
		'mock'      => cfg( 'mock_enabled' ) ? array( (int) $req['InvId'], (int) round( (float) $req['OutSum'] * 100 ), 'OK' . (int) $req['InvId'] ) : null,
		default     => null,
	};
}

function rk_hash( string $s ): string {
	return hash( cfg( 'rk_hash', 'md5' ), $s );
}

function pay_form_robokassa( array $order ): array {
	$sum   = rub( (int) $order['price_kop'] );
	$inv   = (string) $order['id'];
	// Чек 54-ФЗ: одна позиция, полный расчёт. Предмет расчёта и СНО — из настроек (уточнить у бухгалтера).
	$receipt = json_encode(
		array(
			'items' => array(
				array(
					'name'           => mb_substr( $order['title'], 0, 128 ),
					'quantity'       => 1,
					'sum'            => (float) $sum,
					'payment_method' => 'full_payment',
					'payment_object' => cfg( 'rk_payment_object', 'commodity' ),
					'tax'            => cfg( 'rk_tax', 'none' ),
				),
			),
		),
		JSON_UNESCAPED_UNICODE
	);
	$receipt_enc = rawurlencode( $receipt );
	$shp         = array( 'Shp_uuid' => $order['uuid'] );
	ksort( $shp );
	$sig_base = implode( ':', array( cfg( 'rk_login' ), $sum, $inv, $receipt_enc, cfg( 'rk_pass1' ) ) );
	foreach ( $shp as $k => $v ) {
		$sig_base .= ":$k=$v";
	}
	$fields = array(
		'MerchantLogin'  => cfg( 'rk_login' ),
		'OutSum'         => $sum,
		'InvId'          => $inv,
		'Description'    => mb_substr( preg_replace( '/[^\p{L}\p{N} .,+\-]/u', '', $order['title'] ), 0, 100 ),
		'Receipt'        => $receipt_enc,
		'Email'          => $order['email'],
		'Culture'        => 'ru',
		'SignatureValue' => rk_hash( $sig_base ),
	) + $shp;
	if ( cfg( 'rk_test' ) ) {
		$fields['IsTest'] = '1';
	}
	return array( 'action' => 'https://auth.robokassa.ru/Merchant/Index.aspx', 'fields' => $fields, 'method' => 'post' );
}

function pay_verify_robokassa( array $req ): ?array {
	$sum = (string) ( $req['OutSum'] ?? '' );
	$inv = (string) ( $req['InvId'] ?? '' );
	$sig = (string) ( $req['SignatureValue'] ?? '' );
	if ( '' === $sum || ! ctype_digit( $inv ) || '' === $sig ) {
		return null;
	}
	$shp = array_filter( $req, static fn( $k ) => str_starts_with( (string) $k, 'Shp_' ), ARRAY_FILTER_USE_KEY );
	ksort( $shp );
	$base = "$sum:$inv:" . cfg( 'rk_pass2' );
	foreach ( $shp as $k => $v ) {
		$base .= ":$k=$v";
	}
	if ( ! hash_equals( strtolower( rk_hash( $base ) ), strtolower( $sig ) ) ) {
		return null;
	}
	return array( (int) $inv, (int) round( (float) $sum * 100 ), 'OK' . $inv );
}
