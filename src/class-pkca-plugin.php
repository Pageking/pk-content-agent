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

		$changes = PKCA_Content::get_changes( (int) $post_id );
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
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
