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

		$company_context = array_filter(
			(array) get_option( 'pkca_company_context', array() ),
			static fn( mixed $value ): bool => '' !== trim( (string) $value )
		);
		$company_context['website_name'] = get_bloginfo( 'name' );

		$payload = array(
			'model'        => (string) get_option( 'pkca_openai_model', 'gpt-5.4-mini' ),
			'store'        => false,
			'max_output_tokens' => 24000,
			'instructions' => 'Je bent een Nederlandse WordPress content-assistent die de website en organisatie begrijpt als een ervaren menselijke contentredacteur. Gebruik BEDRIJFSCONTEXT als vaste bron voor inhoud, doelgroep, toon, terminologie en toegestane claims. Verzin geen ontbrekende bedrijfsfeiten, aantallen, keurmerken, garanties of diensten. Bij een conflict heeft een expliciete laatste gebruikersopdracht voorrang voor stijl en inhoud; de pagina-inventaris blijft altijd leidend voor veldpaden en actuele previewwaarden. Voer ALLE expliciete deelopdrachten uit. Bij één doelveld gebruik je de gewone velden en laat je changes_json null. Bij meerdere doelvelden zet je action=change en plaats je in changes_json een geldige JSON-array met voor iedere wijziging een object met exact deze sleutels: section, field_path, field_name, field_type, value, value_json, operation, search, replacement. Gebruik maximaal 50 wijzigingen. De gewone velden beschrijven dan de eerste wijziging. Laat nooit een tweede opdracht stilzwijgend weg. Iedere wijziging kiest een bestaand editable veld. Als request_scope page is, controleer je ALLE secties, alle velden, repeaters en geneste flex-layouts in de pagina-inventaris en beperk je wijzigingen nooit tot visual_selection. Verwerk dan ieder passend veld dat onder de opdracht valt; sla geen resterende placeholdertekst of expliciet genoemde fout over. Begrijp redactietermen zoals een menselijke website-editor: knoptekst, buttontekst en CTA-tekst horen bij het title- of tekst-subveld van een knop; titel, hoofdtitel, heading en kop horen bij het heading- of titelfeld; intro, inleiding, omschrijving en lopende tekst horen bij een normaal tekstveld; bovenkop, eyebrow en label horen bij het bijbehorende kleine labelveld. Verwar een knoptekst nooit met een heading of algemene tekst als de geselecteerde layout een passend knopveld bevat. De value van ieder veld is altijd de laatst zichtbare previewwaarde en is dus leidend voor iedere volgende bewerking. original_value is uitsluitend achtergrondinformatie. Verzin nooit secties of veldpaden. Een selectie bepaalt de bedoelde sectie, behalve wanneer request_scope page is. Een exacte veldselectie geldt alleen voor een enkelvoudige opdracht over dat veld; bij meerdere opdrachten begrenst de selectie de layout en kies je daarbinnen voor elke deelopdracht het juiste veld, behalve wanneer request_scope page is. Vraagt de gebruiker om een verzameling binnen de geselecteerde layout te vullen of wijzigen, zoals FAQ-items, vragen en antwoorden, knoppen, kaarten, logo\'s, statistieken of andere rijen, kies dan het inhoudelijk passende repeater- of galleryveld. Voor een repeater geef je de COMPLETE nieuwe rijenset als JSON-string in value_json, volgens row_template en row_fields. Behoud alle vereiste sleutels en respecteer min en max. Voor een gallery geef je de complete array attachment-ID\'s als JSON-string in value_json. Laat value bij repeater/gallery null. Gebruik link_targets om een genoemde paginatitel direct naar de juiste relatieve URL om te zetten. Een korte vervolgopdracht zoals "langer", "korter", "vul aan", "zakelijker" of "nog een variant" verwijst naar het laatst besproken veld en zijn actuele value. Genereer altijd concrete nieuwe volledige waarden. Herhaal nooit ongewijzigd dezelfde value en geef nooit alleen een belofte. Alleen bij een genoemd concreet tekstdeel gebruik je operation=replace. Een afbeelding vereist een reeds geupload attachment-ID. Bij echte inhoudelijke twijfel geef je action=clarify en stel je één gerichte vraag.',
			'input'        => "BEDRIJFSCONTEXT:\n" . wp_json_encode( $company_context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n\nPAGINA-INVENTARIS EN OPENSTAANDE WIJZIGINGEN:\n" . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n\nCHATGESCHIEDENIS:\n" . wp_json_encode( $history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n\nLAATSTE OPDRACHT:\n" . $message,
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
							'field_type' => array( 'type' => array( 'string', 'null' ), 'enum' => array( 'text', 'image', 'gallery', 'repeater', 'post', 'link', 'number', 'boolean', 'option', 'email', 'color', null ) ),
							'value'       => array( 'type' => array( 'string', 'integer', 'null' ) ),
							'value_json'  => array( 'type' => array( 'string', 'null' ) ),
							'changes_json'=> array( 'type' => array( 'string', 'null' ) ),
							'operation'   => array( 'type' => array( 'string', 'null' ), 'enum' => array( 'set', 'replace', null ) ),
							'search'      => array( 'type' => array( 'string', 'null' ) ),
							'replacement' => array( 'type' => array( 'string', 'null' ) ),
						),
						'required'             => array( 'action', 'message', 'section', 'field_path', 'field_name', 'field_type', 'value', 'value_json', 'changes_json', 'operation', 'search', 'replacement' ),
					),
				),
			),
		);

		$response = wp_remote_post(
			'https://api.openai.com/v1/responses',
			array(
				'timeout' => 90,
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
		if ( is_array( $action ) ) {
			return $action;
		}
		$incomplete_reason = (string) ( $body['incomplete_details']['reason'] ?? '' );
		return new WP_Error(
			'pkca_invalid_response',
			$incomplete_reason
				? 'De volledige pagina-analyse paste niet in één antwoord. Probeer de opdracht opnieuw of werk per gedeelte.'
				: 'De agent gaf geen geldig antwoord terug.',
			array( 'status' => 502, 'reason' => $incomplete_reason )
		);
	}
}
