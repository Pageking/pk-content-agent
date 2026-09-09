<?php

defined( 'ABSPATH' ) || exit;

final class PKCA_REST {
	public function register_routes(): void {
		foreach ( array( 'context', 'chat', 'change', 'upload', 'publish', 'discard' ) as $route ) {
			register_rest_route(
				'pk-content-agent/v1',
				'/' . $route,
				array(
					'methods'             => 'upload' === $route ? WP_REST_Server::CREATABLE : ( 'context' === $route ? WP_REST_Server::READABLE : WP_REST_Server::CREATABLE ),
					'callback'            => array( $this, $route ),
					'permission_callback' => array( $this, 'can_edit' ),
				)
			);
		}
	}

	public function change( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = absint( $request['post_id'] );
		$items = $request->get_param( 'changes' );
		if ( ! is_array( $items ) || array() === $items ) {
			return new WP_Error( 'pkca_changes_missing', 'Er zijn geen velden gewijzigd.', array( 'status' => 400 ) );
		}
		$queued = array();
		foreach ( array_slice( $items, 0, 50 ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$type = sanitize_key( (string) ( $item['type'] ?? '' ) );
			if ( ! in_array( $type, array( 'text', 'link', 'image', 'gallery', 'repeater', 'number', 'boolean', 'option', 'email', 'color' ), true ) ) {
				return new WP_Error( 'pkca_change_type', 'Dit veldtype kan niet veilig worden bewerkt.', array( 'status' => 422 ) );
			}
			$result = PKCA_Content::add_change(
				$post_id,
				array(
					'section'    => absint( $item['section'] ?? 0 ),
					'field_path' => is_array( $item['field_path'] ?? null ) ? array_map( 'sanitize_key', $item['field_path'] ) : array(),
					'field_name' => sanitize_key( (string) ( $item['field_name'] ?? '' ) ),
					'type'       => $type,
					'value'      => $item['value'] ?? '',
					'operation'  => 'set',
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$queued[] = $result;
		}
		$context = PKCA_Content::inspect( $post_id );
		return is_wp_error( $context ) ? $context : rest_ensure_response( array( 'queued' => count( $queued ), 'changes' => $context['changes'] ) );
	}

	public function can_edit( WP_REST_Request $request ): bool|WP_Error {
		$post_id = absint( $request->get_param( 'post_id' ) );
		return $post_id && current_user_can( 'edit_post', $post_id )
			? true
			: new WP_Error( 'pkca_forbidden', 'Je mag deze pagina niet bewerken.', array( 'status' => 403 ) );
	}

	public function context( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = PKCA_Content::inspect( absint( $request['post_id'] ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public function chat( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = absint( $request['post_id'] );
		$message = sanitize_textarea_field( (string) $request['message'] );
		$original_message = sanitize_textarea_field( (string) ( $request->get_param( 'original_message' ) ?: $message ) );
		if ( '' === $message ) {
			return new WP_Error( 'pkca_empty_message', 'Typ eerst een opdracht.', array( 'status' => 400 ) );
		}
		$context = PKCA_Content::inspect( $post_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$selection = $this->sanitize_selection( $request->get_param( 'selection' ) );
		if ( $selection ) {
			$selection = $this->resolve_selection( $selection, $context );
			$section_context = $context['sections'][ $selection['section'] - 1 ] ?? array();
			$selection['collection_candidates'] = array_values(
				array_map(
					static fn( array $field ): array => array(
						'label'        => $field['label'] ?? $field['name'] ?? '',
						'name'         => $field['name'] ?? '',
						'path'         => $field['path'] ?? array(),
						'type'         => $field['type'] ?? '',
						'row_template' => $field['row_template'] ?? null,
						'row_fields'   => $field['row_fields'] ?? null,
						'min'          => $field['min'] ?? 0,
						'max'          => $field['max'] ?? 0,
					),
					array_filter(
						(array) ( $section_context['fields'] ?? array() ),
						static fn( array $field ): bool => in_array( $field['type'] ?? '', array( 'repeater', 'gallery' ), true ) && false !== ( $field['editable'] ?? true )
					)
				)
			);
			$context['selection'] = $selection;
		}
		$history = array();
		foreach ( (array) $request->get_param( 'history' ) as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['text'], $item['kind'] ) ) {
				continue;
			}
			$history[] = array(
				'role' => 'user' === $item['kind'] ? 'user' : 'assistant',
				'text' => sanitize_textarea_field( (string) $item['text'] ),
			);
		}
		$action = PKCA_OpenAI::interpret( $message, $context, array_slice( $history, -20 ) );
		if ( is_wp_error( $action ) ) {
			return $action;
		}
		if ( ! empty( $selection ) && 'change' === ( $action['action'] ?? '' ) ) {
			$is_structural = in_array( $action['field_type'] ?? '', array( 'repeater', 'gallery' ), true );
			if ( empty( $selection['field_path'] ) && ! $is_structural ) {
				return rest_ensure_response(
					array(
						'action'  => 'clarify',
						'message' => 'Dit onderdeel komt meerdere keren voor en kon niet veilig aan één veld worden gekoppeld. Wijs de tekst zelf nogmaals aan.',
					)
				);
			}
			$action['section'] = $selection['section'];
			if ( $is_structural ) {
				// The click identifies the layout; the model identifies its collection field.
			} elseif ( preg_match( '/\b(link|linken|url|verwijs|doorstuur)/iu', $original_message ) && 'title' === end( $selection['field_path'] ) ) {
				$link_path = $selection['field_path'];
				$link_path[ count( $link_path ) - 1 ] = 'url';
				$action['field_path'] = $link_path;
				$action['field_name'] = 'url';
				$action['field_type'] = 'link';
			} else {
				$action['field_path'] = $selection['field_path'];
				$action['field_name'] = $selection['field_name'];
				$action['field_type'] = $selection['type'];
			}
		}
		$action = $this->enforce_selected_partial_replacement( $action, $original_message, $selection );
		if ( 'change' === ( $action['action'] ?? '' ) ) {
			if ( in_array( $action['field_type'] ?? '', array( 'repeater', 'gallery' ), true ) ) {
				$decoded_value = json_decode( (string) ( $action['value_json'] ?? '' ), true );
				if ( ! is_array( $decoded_value ) ) {
					return new WP_Error( 'pkca_structured_value', 'De voorgestelde rijen konden niet veilig worden verwerkt.', array( 'status' => 422 ) );
				}
				$action['value'] = $decoded_value;
			}
			$change = PKCA_Content::add_change(
				$post_id,
				array(
					'section'    => $action['section'],
					'field_path' => $action['field_path'],
					'field_name' => $action['field_name'],
					'type'       => $action['field_type'],
					'value'      => $action['value'],
					'operation'  => $action['operation'] ?? 'set',
					'search'     => $action['search'] ?? null,
					'replacement'=> $action['replacement'] ?? null,
				)
			);
			if ( is_wp_error( $change ) ) {
				return $change;
			}
			$action['change'] = $change;
			$updated_context = PKCA_Content::inspect( $post_id );
			$action['changes'] = is_wp_error( $updated_context ) ? array() : $updated_context['changes'];
		}
		return rest_ensure_response( $action );
	}

	private function sanitize_selection( mixed $selection ): array {
		if ( ! is_array( $selection ) ) {
			return array();
		}
		return array(
			'section'    => absint( $selection['section'] ?? 0 ),
			'type'       => in_array( $selection['type'] ?? '', array( 'text', 'image' ), true ) ? $selection['type'] : '',
			'value'      => sanitize_textarea_field( (string) ( $selection['value'] ?? '' ) ),
			'alt'        => sanitize_text_field( (string) ( $selection['alt'] ?? '' ) ),
			'occurrence' => absint( $selection['occurrence'] ?? 0 ),
			'field_path' => is_array( $selection['fieldPath'] ?? null ) ? array_map( 'sanitize_key', $selection['fieldPath'] ) : null,
			'field_name' => sanitize_key( (string) ( $selection['fieldName'] ?? '' ) ),
			'layout'     => sanitize_key( (string) ( $selection['layout'] ?? '' ) ),
			'scope'      => 'field' === ( $selection['scope'] ?? '' ) ? 'field' : 'layout',
		);
	}

	private function resolve_selection( array $selection, array $context ): array {
		if ( ! empty( $selection['field_path'] ) || empty( $selection['section'] ) || empty( $selection['type'] ) ) {
			return $selection;
		}
		$section = $context['sections'][ $selection['section'] - 1 ] ?? null;
		if ( ! is_array( $section ) ) {
			return $selection;
		}
		$selected_value = $this->normalize_selection_text( $selection['value'] );
		$matches = array_values(
			array_filter(
				$section['fields'] ?? array(),
				function ( array $field ) use ( $selection, $selected_value ): bool {
					if ( ( $field['type'] ?? '' ) !== $selection['type'] || false === ( $field['editable'] ?? true ) ) {
						return false;
					}
					if ( 'image' === $selection['type'] ) {
						return $this->image_key( (string) ( $field['url'] ?? '' ) ) === $this->image_key( $selection['value'] );
					}
					return $this->normalize_selection_text( (string) ( $field['value'] ?? '' ) ) === $selected_value;
				}
			)
		);
		$match = 1 === count( $matches ) ? $matches[0] : ( $matches[ $selection['occurrence'] ] ?? null );
		if ( $match ) {
			$selection['field_path'] = $match['path'];
			$selection['field_name'] = $match['name'];
		}
		return $selection;
	}

	private function normalize_selection_text( string $value ): string {
		$value = html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
	}

	private function image_key( string $url ): string {
		$filename = rawurldecode( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		return strtolower( (string) preg_replace( '/-\d+x\d+(?=\.[^.]+$)/i', '', $filename ) );
	}

	private function enforce_selected_partial_replacement( array $action, string $message, array $selection ): array {
		if ( 'change' !== ( $action['action'] ?? '' ) || 'text' !== ( $selection['type'] ?? '' ) || empty( $selection['value'] ) ) {
			return $action;
		}
		if ( preg_match( '/^(?:kun je\s+)?(?:(?:dit|deze(?:\s+\w+)?)\s+)?(?:pas\s+aan|aanpassen|verander|veranderen|wijzig|wijzigen)\s+naar\s+["“”\']?(.+?)["“”\']?[.!?]?$/iu', trim( $message ), $whole_match ) ) {
			$action['operation'] = 'set';
			$action['value'] = trim( $whole_match[1], " \t\n\r\0\x0B\"'“”" );
			$action['search'] = null;
			$action['replacement'] = null;
			return $action;
		}
		if ( ! preg_match( '/\b(?:pas|verander|wijzig)\s+["“”\']?(.+?)["“”\']?\s+(?:aan\s+)?naar\s+["“”\']?(.+?)["“”\']?[.!?]?$/iu', trim( $message ), $matches ) ) {
			return $action;
		}
		$search = trim( $matches[1], " \t\n\r\0\x0B\"'“”" );
		$replacement = trim( $matches[2], " \t\n\r\0\x0B\"'“”" );
		if ( '' === $search || '' === $replacement || false === mb_stripos( $selection['value'], $search ) || 0 === mb_stripos( $selection['value'], $search ) && mb_strlen( $search ) === mb_strlen( $selection['value'] ) ) {
			return $action;
		}
		$action['operation'] = 'replace';
		$action['search'] = $search;
		$action['replacement'] = $replacement;
		return $action;
	}

	public function upload( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( empty( $_FILES['file'] ) ) {
			return new WP_Error( 'pkca_upload_missing', 'Selecteer eerst een afbeelding.', array( 'status' => 400 ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$id = media_handle_upload( 'file', absint( $request['post_id'] ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( ! wp_attachment_is_image( $id ) ) {
			wp_delete_attachment( $id, true );
			return new WP_Error( 'pkca_upload_type', 'Alleen afbeeldingen zijn toegestaan.', array( 'status' => 415 ) );
		}
		return rest_ensure_response( array( 'id' => $id, 'url' => wp_get_attachment_image_url( $id, 'medium' ), 'title' => get_the_title( $id ) ) );
	}

	public function publish( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = PKCA_Content::publish( absint( $request['post_id'] ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public function discard( WP_REST_Request $request ): WP_REST_Response {
		PKCA_Content::discard( absint( $request['post_id'] ) );
		return rest_ensure_response( array( 'discarded' => true ) );
	}
}
