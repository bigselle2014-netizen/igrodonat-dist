<?php
/**
 * Поставщики. Контракт supply_place(): ['state' => 'delivered'|'pending'|'failed', 'ref' => id у поставщика,
 * 'delivery' => что показать покупателю (коды), 'error' => причина].
 * mock — для проверки цепочки. Реальные (SEAGM, WATA, DonatBank…) подключаются, когда будут доступы.
 */

defined( 'IGD' ) || exit;

function supply_place( array $order, array $product, array $package ): array {
	return match ( $product['supplier'] ) {
		'mock'  => supply_mock( $order, $package ),
		default => array( 'state' => 'failed', 'error' => 'Поставщик ' . $product['supplier'] . ' не подключён' ),
	};
}

/** Проверка ID игрока у поставщика до оплаты. null — поставщик не умеет проверять. */
function supply_check_account( array $product, array $account ): ?array {
	return match ( $product['supplier'] ) {
		'mock'  => array( 'ok' => ! str_starts_with( (string) reset( $account ), '000' ), 'name' => 'Тестовый игрок' ),
		default => null,
	};
}

function supply_mock( array $order, array $package ): array {
	if ( ! cfg( 'mock_enabled' ) ) {
		return array( 'state' => 'failed', 'error' => 'mock выключен' );
	}
	$acc = json_decode( $order['account_json'], true );
	if ( str_starts_with( (string) reset( $acc ), '999' ) ) {
		return array( 'state' => 'failed', 'error' => 'mock: поставщик отказал' );
	}
	return array( 'state' => 'delivered', 'ref' => 'MOCK-' . $order['id'], 'delivery' => 'Зачислено: ' . $package['name'] );
}
