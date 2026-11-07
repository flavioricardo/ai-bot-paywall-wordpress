<?php
/**
 * USD/BRL exchange rate from Banco Central do Brasil (PTAX). Only fetched when the
 * owner shows or prices in reais.
 *
 * @package Portaria
 */

namespace Portaria;

defined( 'ABSPATH' ) || exit;

final class Rates {

	const OPTION = 'portaria_usd_brl';

	/** The rate, or null when none has been fetched yet. */
	public static function usd_brl(): ?float {
		$stored = get_option( self::OPTION );
		$rate   = is_array( $stored ) ? (float) ( $stored['rate'] ?? 0 ) : 0.0;
		return $rate > 0 ? $rate : null;
	}

	public static function date(): string {
		$stored = get_option( self::OPTION );
		return is_array( $stored ) ? (string) ( $stored['date'] ?? '' ) : '';
	}

	public static function needed(): bool {
		$settings = Settings::get();
		return ! empty( $settings['show_brl'] ) || 'BRL' === $settings['currency'];
	}

	/** Reads the latest PTAX sell rate. A failure keeps the last known rate. */
	public static function refresh(): void {
		if ( ! self::needed() ) {
			return;
		}

		// A window covers weekends and holidays, when no rate is published.
		$url = sprintf(
			"https://olinda.bcb.gov.br/olinda/servico/PTAX/versao/v1/odata/CotacaoDolarPeriodo(dataInicial=@dataInicial,dataFinalCotacao=@dataFinalCotacao)?@dataInicial='%s'&@dataFinalCotacao='%s'&\$format=json",
			gmdate( 'm-d-Y', time() - 10 * DAY_IN_SECONDS ),
			gmdate( 'm-d-Y' )
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 10,
				'limit_response_size' => 65536,
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return;
		}

		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		$values = is_array( $data ) && is_array( $data['value'] ?? null ) ? $data['value'] : array();
		$latest = end( $values );
		$rate   = is_array( $latest ) ? (float) ( $latest['cotacaoVenda'] ?? 0 ) : 0.0;
		if ( $rate > 0 ) {
			update_option(
				self::OPTION,
				array(
					'rate' => $rate,
					'date' => substr( (string) ( $latest['dataHoraCotacao'] ?? '' ), 0, 10 ),
				),
				false
			);
		}
	}
}
