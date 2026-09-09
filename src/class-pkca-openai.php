<?php

defined( 'ABSPATH' ) || exit;

final class PKCA_OpenAI {
	public static function api_key(): string {
		if ( defined( 'PK_CONTENT_AGENT_OPENAI_API_KEY' ) ) {
			return trim( (string) PK_CONTENT_AGENT_OPENAI_API_KEY );
		}
		return trim( (string) get_option( 'pkca_openai_api_key', '' ) );
	}

	public static function interpret( string $message, array $context, array $history = array() ): array|WP_Error {
		$key = self::api_key();
		if ( '' === $key ) {
			return new WP_Error( 'pkca_key_missing', 'Stel eerst een OpenAI API-key in via Instellingen → PK Content Agent.', array( 'status' => 503 ) );
		}

		$payload = array(
			'model'        => (string) get_option( 'pkca_openai_model', 'gpt-5.4-mini' ),
			'store'        => false,
			'instructions' => 'Je bent een Nederlandse WordPress content-assistent. Kies exact één veld met editable=true uit de meegegeven pagina-inventaris. De value van ieder veld is altijd de laatst zichtbare previewwaarde en is dus leidend voor iedere volgende bewerking, ook wanneer de gebruiker daarna naar een andere context of een ander veld gaat. original_value is uitsluitend achtergrondinformatie en mag nooit als actuele tekst worden gebruikt. Velden met editable=false zijn alleen context. Verzin nooit secties of veldpaden. Een selection met een exact veldpad is definitief en heeft altijd voorrang, behalve wanneer de gebruiker expliciet de link of URL van een geselecteerde knop wil wijzigen: kies dan het naastgelegen button/url-veld. Gebruik link_targets om een genoemde paginatitel direct naar de juiste relatieve URL om te zetten; vraag niet om een URL als de pagina daar eenduidig staat. Een korte vervolgopdracht zoals "langer", "korter", "vul aan", "zakelijker" of "nog een variant" verwijst naar het laatst besproken veld en zijn huidige value. Genereer dan altijd zelf een concrete nieuwe volledige value die aantoonbaar aan die opdracht voldoet. Herhaal nooit ongewijzigd dezelfde value en geef nooit alleen een belofte zoals "ik pas het aan". Laat message kort benoemen wat daadwerkelijk is gewijzigd. Woorden als "dit", "deze tekst" en "deze knop" verwijzen naar de volledige selectie: "pas dit aan naar X" is operation=set met value=X. Alleen wanneer de gebruiker een concreet woord of tekstdeel uit de huidige value noemt, gebruik je operation=replace. Een afbeelding vereist een reeds geupload attachment-ID. Bij twijfel over de nieuwe inhoud geef je action=clarify en stel je één gerichte vraag. Gebruik action=change alleen als de gebruiker expliciet een wijziging vraagt en de concrete doelwaarde in value staat.',
			'input'        => "PAGINA-INVENTARIS EN OPENSTAANDE WIJZIGINGEN:\n" . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n\nCHATGESCHIEDENIS:\n" . wp_json_encode( $history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n\nLAATSTE OPDRACHT:\n" . $message,
			'text'         => array(
				'format' => array(
					'type'   => 'json_schema',
					'name'   => 'pk_content_action',
					'strict' => true,
					'schema' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => array(
							'action'     => array( 'type' => 'string', 'enum' => array( 'change', 'clarify', 'answer' ) ),
							'message'    => array( 'type' => 'string' ),
							'section'    => array( 'type' => array( 'integer', 'null' ) ),
							'field_path' => array( 'type' => array( 'array', 'null' ), 'items' => array( 'type' => 'string' ) ),
							'field_name' => array( 'type' => array( 'string', 'null' ) ),
							'field_type' => array( 'type' => array( 'string', 'null' ), 'enum' => array( 'text', 'image', 'post', 'link', 'number', 'boolean', 'option', 'email', 'color', null ) ),
							'value'       => array( 'type' => array( 'string', 'integer', 'null' ) ),
							'operation'   => array( 'type' => array( 'string', 'null' ), 'enum' => array( 'set', 'replace', null ) ),
							'search'      => array( 'type' => array( 'string', 'null' ) ),
							'replacement' => array( 'type' => array( 'string', 'null' ) ),
						),
						'required'             => array( 'action', 'message', 'section', 'field_path', 'field_name', 'field_type', 'value', 'operation', 'search', 'replacement' ),
					),
				),
			),
		);

		$response = wp_remote_post(
			'https://api.openai.com/v1/responses',
			array(
				'timeout' => 45,
				'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( wp_remote_retrieve_response_code( $response ) >= 400 ) {
			return new WP_Error( 'pkca_openai_error', (string) ( $body['error']['message'] ?? 'OpenAI gaf een fout terug.' ), array( 'status' => 502 ) );
		}

		$text = (string) ( $body['output_text'] ?? '' );
		if ( '' === $text && isset( $body['output'] ) ) {
			foreach ( $body['output'] as $item ) {
				foreach ( $item['content'] ?? array() as $content ) {
					if ( isset( $content['text'] ) ) {
						$text .= $content['text'];
					}
				}
			}
		}
		$action = json_decode( $text, true );
		return is_array( $action ) ? $action : new WP_Error( 'pkca_invalid_response', 'De agent gaf geen geldig antwoord terug.', array( 'status' => 502 ) );
	}
}
