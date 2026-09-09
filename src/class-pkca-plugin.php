<?php

defined( 'ABSPATH' ) || exit;

require_once PKCA_DIR . 'src/class-pkca-content.php';
require_once PKCA_DIR . 'src/class-pkca-openai.php';
require_once PKCA_DIR . 'src/class-pkca-rest.php';

final class PKCA_Plugin {
	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'rest_api_init', array( new PKCA_REST(), 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend' ), 100 );
		add_filter( 'acf/load_value/name=content_repeater', array( $this, 'apply_raw_preview' ), 100, 3 );
		add_filter( 'acf/format_value/name=content_repeater', array( $this, 'apply_preview' ), 100, 3 );
		add_filter( 'acf/load_value', array( $this, 'apply_sub_field_preview' ), 100, 3 );
	}

	public function enqueue_frontend(): void {
		if ( is_admin() || ! is_singular() || ! is_user_logged_in() ) {
			return;
		}

		$post_id = (int) get_queried_object_id();
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		wp_enqueue_style( 'pk-content-agent', plugins_url( 'assets/agent.css', PKCA_FILE ), array(), PKCA_VERSION );
		wp_enqueue_media();
		wp_enqueue_script( 'pk-content-agent', plugins_url( 'assets/agent.js', PKCA_FILE ), array(), PKCA_VERSION, true );
		wp_add_inline_script(
			'pk-content-agent',
			'window.PKContentAgent=' . wp_json_encode(
				array(
					// Keep requests on the current browser origin. Local development may
					// expose WordPress through a different frontend/proxy port.
					'restUrl' => esc_url_raw( wp_make_link_relative( rest_url( 'pk-content-agent/v1/' ) ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'postId'  => $post_id,
					'title'   => get_the_title( $post_id ),
					'version' => PKCA_VERSION,
				)
			) . ';',
			'before'
		);
	}

	public function apply_preview( mixed $value, int|string $post_id, array $field ): mixed {
		if ( PKCA_Content::preview_is_suppressed() || is_admin() || ! is_user_logged_in() || ! is_array( $value ) ) {
			return $value;
		}

		$inspection = PKCA_Content::inspect( (int) $post_id );
		$changes = is_wp_error( $inspection ) ? array() : $inspection['changes'];
		foreach ( $changes as $change ) {
			$value = PKCA_Content::apply_change_to_value( $value, $change );
		}

		return $value;
	}

	public function apply_raw_preview( mixed $value, mixed $post_id, array $field ): mixed {
		if ( is_admin() || ! is_user_logged_in() || ! is_numeric( $post_id ) || (int) $post_id < 1 ) {
			return $value;
		}
		return PKCA_Content::apply_changes_to_raw_value( $value, (int) $post_id );
	}

	public function apply_sub_field_preview( mixed $value, mixed $post_id, array $field ): mixed {
		if ( is_admin() || ! is_user_logged_in() || ! is_numeric( $post_id ) || (int) $post_id < 1 || 'content_repeater' === ( $field['name'] ?? '' ) ) {
			return $value;
		}
		return PKCA_Content::preview_sub_field_value( $value, (int) $post_id, $field );
	}

	public function register_settings(): void {
		register_setting(
			'pkca_settings',
			'pkca_openai_api_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => false,
			)
		);
		register_setting(
			'pkca_settings',
			'pkca_openai_model',
			array(
				'type'              => 'string',
				'default'           => 'gpt-5.4-mini',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);
		register_setting(
			'pkca_settings',
			'pkca_company_context',
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => array( $this, 'sanitize_company_context' ),
				'show_in_rest'      => false,
			)
		);
	}

	public function sanitize_company_context( mixed $value ): array {
		$allowed = array( 'profile', 'audiences', 'services', 'positioning', 'tone', 'terminology', 'facts' );
		$result = array();
		foreach ( $allowed as $key ) {
			$result[ $key ] = mb_substr( sanitize_textarea_field( (string) ( is_array( $value ) ? ( $value[ $key ] ?? '' ) : '' ) ), 0, 12000 );
		}
		return $result;
	}

	public function register_settings_page(): void {
		add_options_page(
			'PK Content Agent',
			'PK Content Agent',
			'manage_options',
			'pk-content-agent',
			array( $this, 'render_settings_page' )
		);
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$key_source = defined( 'PK_CONTENT_AGENT_OPENAI_API_KEY' ) ? 'wp-config.php' : 'database';
		$company_context = wp_parse_args(
			(array) get_option( 'pkca_company_context', array() ),
			array_fill_keys( array( 'profile', 'audiences', 'services', 'positioning', 'tone', 'terminology', 'facts' ), '' )
		);
		$context_fields = array(
			'profile'     => array( 'Bedrijfsprofiel', 'Wie is het bedrijf, wat doet het en wat is de missie? Beschrijf ook regio, omvang en markt als dat relevant is.', 'Bijvoorbeeld: NOVA is een landelijk advies- en accountancykantoor dat ondernemers helpt met...' ),
			'audiences'   => array( 'Doelgroepen', 'Voor wie schrijven we? Benoem per doelgroep de belangrijkste behoeften, vragen en bezwaren.', 'Bijvoorbeeld: mkb-ondernemers die behoefte hebben aan grip op cijfers; starters die...' ),
			'services'    => array( 'Diensten en expertise', 'Welke diensten, specialismen en oplossingen biedt het bedrijf? Gebruik bij voorkeur de officiële benamingen.', 'Bijvoorbeeld: accountancy, belastingadvies, corporate finance, IT-advies...' ),
			'positioning' => array( 'Positionering en kernboodschappen', 'Wat maakt het bedrijf onderscheidend en welke boodschappen moeten in teksten herkenbaar terugkomen?', 'Bijvoorbeeld: persoonlijke aandacht gecombineerd met specialistische kennis...' ),
			'tone'        => array( 'Tone of voice', 'Beschrijf schrijfstijl, aanspreekvorm en gewenste uitstraling. Voeg eventueel korte goede voorbeelden toe.', 'Bijvoorbeeld: deskundig maar toegankelijk; actief Nederlands; spreek de lezer aan met je...' ),
			'terminology' => array( 'Terminologie en schrijfregels', 'Welke woorden, schrijfwijzen en CTA-stijlen gebruiken of vermijden we?', 'Bijvoorbeeld: schrijf NOVA altijd in hoofdletters; gebruik adviseur in plaats van consultant; vermijd...' ),
			'facts'       => array( 'Feiten, bewijs en beperkingen', 'Noteer controleerbare feiten en claims die gebruikt mogen worden. Zet hier ook wat de agent nooit mag aannemen of verzinnen.', 'Bijvoorbeeld: actief vanuit 8 vestigingen; geen aantallen of garanties noemen zonder bron...' ),
		);
		?>
		<div class="wrap">
			<h1>PK Content Agent</h1>
			<p>De chat verschijnt op singular frontendpagina’s voor gebruikers die de betreffende pagina mogen bewerken.</p>
			<form action="options.php" method="post">
				<?php settings_fields( 'pkca_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pkca_openai_api_key">OpenAI API-key</label></th>
						<td>
							<input class="regular-text" id="pkca_openai_api_key" name="pkca_openai_api_key" type="password" autocomplete="new-password" value="<?= esc_attr( get_option( 'pkca_openai_api_key', '' ) ); ?>" <?= defined( 'PK_CONTENT_AGENT_OPENAI_API_KEY' ) ? 'disabled' : ''; ?>>
							<p class="description">Huidige bron: <?= esc_html( $key_source ); ?>. Voor productie heeft een constante of environment secret de voorkeur.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pkca_openai_model">Model</label></th>
						<td><input class="regular-text" id="pkca_openai_model" name="pkca_openai_model" value="<?= esc_attr( get_option( 'pkca_openai_model', 'gpt-5.4-mini' ) ); ?>" pattern="[A-Za-z0-9._-]+"></td>
					</tr>
				</table>
				<h2>Bedrijfscontext</h2>
				<p>Deze briefing wordt bij iedere chatopdracht gebruikt. Vul alleen actuele informatie in; lege onderdelen worden genegeerd.</p>
				<table class="form-table" role="presentation">
					<?php foreach ( $context_fields as $key => $field ) : ?>
						<tr>
							<th scope="row"><label for="pkca_context_<?= esc_attr( $key ); ?>"><?= esc_html( $field[0] ); ?></label></th>
							<td>
								<textarea class="large-text" rows="5" id="pkca_context_<?= esc_attr( $key ); ?>" name="pkca_company_context[<?= esc_attr( $key ); ?>]" placeholder="<?= esc_attr( $field[2] ); ?>"><?= esc_textarea( $company_context[ $key ] ); ?></textarea>
								<p class="description"><?= esc_html( $field[1] ); ?></p>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
