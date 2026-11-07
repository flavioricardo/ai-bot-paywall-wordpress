<?php
/**
 * Database access. Stores daily aggregates and payments, never IPs or raw User-Agents.
 *
 * @package Portaria
 */

namespace Portaria;

defined( 'ABSPATH' ) || exit;

final class Store {

	const DB_VERSION = '1';

	/** Counter columns of the daily table. */
	const COUNTERS = array( 'hits', 'priced', 'priced_atomic', 'challenged', 'attempts', 'paid', 'paid_atomic' );

	private static function daily(): string {
		global $wpdb;
		return $wpdb->prefix . 'portaria_daily';
	}

	private static function payments(): string {
		global $wpdb;
		return $wpdb->prefix . 'portaria_payments';
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'portaria_db_version' ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			'CREATE TABLE ' . self::daily() . " (
				day date NOT NULL,
				bot varchar(40) NOT NULL,
				class varchar(20) NOT NULL,
				identity varchar(12) NOT NULL,
				hits bigint(20) unsigned NOT NULL DEFAULT 0,
				priced bigint(20) unsigned NOT NULL DEFAULT 0,
				priced_atomic bigint(20) unsigned NOT NULL DEFAULT 0,
				challenged bigint(20) unsigned NOT NULL DEFAULT 0,
				attempts bigint(20) unsigned NOT NULL DEFAULT 0,
				paid bigint(20) unsigned NOT NULL DEFAULT 0,
				paid_atomic bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (day,bot,identity)
			) {$charset};"
		);
		dbDelta(
			'CREATE TABLE ' . self::payments() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				kind varchar(8) NOT NULL,
				status varchar(8) NOT NULL,
				bot varchar(40) NOT NULL,
				path varchar(255) NOT NULL,
				amount_atomic bigint(20) unsigned NOT NULL,
				network varchar(32) NOT NULL,
				payer varchar(64) NOT NULL,
				tx varchar(100) NOT NULL,
				reason varchar(80) NOT NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY kind_status (kind,status)
			) {$charset};"
		);
		update_option( 'portaria_db_version', self::DB_VERSION, false );
	}

	/**
	 * Adds to today's counters for a bot. Failing to count must never break a page,
	 * so database errors stay quiet here.
	 *
	 * @param array<string,int|string> $increments Counter column to amount.
	 */
	public static function bump( string $bot, string $class, string $identity, array $increments ): void {
		global $wpdb;

		$values = array_fill_keys( self::COUNTERS, 0 );
		foreach ( $increments as $column => $amount ) {
			if ( isset( $values[ $column ] ) ) {
				$values[ $column ] = (int) $amount;
			}
		}

		$updates = array();
		foreach ( self::COUNTERS as $column ) {
			$updates[] = "{$column} = {$column} + VALUES({$column})";
		}

		$suppress = $wpdb->suppress_errors( true );
		// phpcs:disable WordPress.DB.PreparedSQL -- Table and column names come from constants.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . self::daily() . ' (day, bot, class, identity, ' . implode( ', ', self::COUNTERS ) . ')
				VALUES (%s, %s, %s, %s, %d, %d, %d, %d, %d, %d, %d)
				ON DUPLICATE KEY UPDATE ' . implode( ', ', $updates ),
				array_merge( array( gmdate( 'Y-m-d' ), $bot, $class, $identity ), array_values( $values ) )
			)
		);
		// phpcs:enable
		$wpdb->suppress_errors( $suppress );
	}

	/** @param array<string,string> $payment kind, status, bot, path, amount_atomic, network, payer, tx, reason. */
	public static function record_payment( array $payment ): void {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$wpdb->insert(
			self::payments(),
			array(
				'created_at'    => gmdate( 'Y-m-d H:i:s' ),
				'kind'          => substr( $payment['kind'], 0, 8 ),
				'status'        => substr( $payment['status'], 0, 8 ),
				'bot'           => substr( $payment['bot'], 0, 40 ),
				'path'          => substr( $payment['path'], 0, 255 ),
				'amount_atomic' => $payment['amount_atomic'],
				'network'       => substr( $payment['network'], 0, 32 ),
				'payer'         => substr( $payment['payer'], 0, 64 ),
				'tx'            => substr( $payment['tx'], 0, 100 ),
				'reason'        => substr( $payment['reason'], 0, 80 ),
			)
		);
		$wpdb->suppress_errors( $suppress );
	}

	private static function since( int $days ): string {
		return gmdate( 'Y-m-d', time() - ( max( 1, $days ) - 1 ) * DAY_IN_SECONDS );
	}

	/**
	 * Totals for the payment funnel.
	 *
	 * @return array<string,int>
	 */
	public static function funnel( int $days ): array {
		global $wpdb;
		$sums = array();
		foreach ( self::COUNTERS as $column ) {
			$sums[] = "COALESCE(SUM({$column}), 0) AS {$column}";
		}
		// phpcs:disable WordPress.DB.PreparedSQL
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT ' . implode( ', ', $sums ) . ' FROM ' . self::daily() . ' WHERE day >= %s', self::since( $days ) ),
			ARRAY_A
		);
		// phpcs:enable
		$totals = array_fill_keys( self::COUNTERS, 0 );
		foreach ( (array) $row as $column => $value ) {
			$totals[ $column ] = (int) $value;
		}
		$totals['fake'] = self::fake_hits( $days );
		return $totals;
	}

	private static function fake_hits( int $days ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(hits), 0) FROM ' . self::daily() . " WHERE day >= %s AND identity = 'fake'", self::since( $days ) ) );
	}

	/**
	 * Visits per bot and identity, busiest first.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function bots( int $days, int $limit = 50 ): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT bot, class, identity, SUM(hits) AS hits, SUM(challenged) AS challenged, SUM(paid) AS paid, SUM(paid_atomic) AS paid_atomic
				FROM ' . self::daily() . '
				WHERE day >= %s
				GROUP BY bot, class, identity
				ORDER BY hits DESC
				LIMIT %d',
				self::since( $days ),
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable
		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<string,string>|null The newest successful test payment. */
	public static function last_test(): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL
		$row = $wpdb->get_row( 'SELECT * FROM ' . self::payments() . " WHERE kind = 'test' AND status IN ('paid', 'pending') ORDER BY id DESC LIMIT 1", ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Newest payments, optionally only real money movements.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function recent_payments( int $limit, bool $settled_only = false ): array {
		global $wpdb;
		$where = $settled_only ? "WHERE status IN ('paid', 'pending')" : '';
		// phpcs:ignore WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::payments() . " {$where} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** Applies retention. Settled payments are financial records and are kept. */
	public static function prune( int $days ): void {
		global $wpdb;
		$days = max( 30, $days );
		// phpcs:disable WordPress.DB.PreparedSQL
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::daily() . ' WHERE day < %s', self::since( $days ) ) );
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::payments() . " WHERE status = 'failed' AND created_at < %s",
				gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS )
			)
		);
		// phpcs:enable
	}

	public static function drop_tables(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::daily() . ', ' . self::payments() );
	}
}
