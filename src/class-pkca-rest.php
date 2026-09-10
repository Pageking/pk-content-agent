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
		$post_id = $this->request_target( $request );
		$items = $request->get_param( 'changes' );
		if ( ! is_array( $items ) || array() === $items ) {
			return new WP_Error( 'pkca_changes_missing', 'Er zijn geen velden gewijzigd.', array( 'status' => 400 ) );
		}
		$queued = array();
		$unchanged = 0;
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
				if ( 'pkca_no_change' === $result->get_error_code() ) {
					$unchanged++;
					continue;
				}
				return $result;
			}
			$queued[] = $result;
		}
		$context = PKCA_Content::inspect( $post_id );
		return is_wp_error( $context ) ? $context : rest_ensure_response( array( 'queued' => count( $queued ), 'unchanged' => $unchanged, 'changes' => $context['changes'] ) );
	}

	public function can_edit( WP_REST_Request $request ): bool|WP_Error {
		$post_id = $this->request_target( $request );
		$target = PKCA_Content::target( $post_id );
		$allowed = ! is_wp_error( $target ) && ( 'archive' === $target['type'] ? current_user_can( 'edit_posts' ) : current_user_can( 'edit_post', (int) $target['acf_id'] ) );
		return $allowed
			? true
			: new WP_Error( 'pkca_forbidden', 'Je mag deze pagina niet bewerken.', array( 'status' => 403 ) );
	}

	public function context( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = PKCA_Content::inspect( $this->request_target( $request ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public function chat( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = $this->request_target( $request );
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
		$page_wide = $this->is_page_wide_request( $original_message );
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
			if ( $page_wide ) {
				// Keep the click as conversational context, but do not let it limit an
				// explicit request to inspect or update the complete page.
				$context['visual_selection'] = $selection;
				$context['request_scope'] = 'page';
			} else {
				$context['selection'] = $selection;
			}
		}
		if ( $page_wide ) {
			$selection = null;
			$context['request_scope'] = 'page';
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
		$message = $this->normalize_editor_language( $message, $selection, $context );
		$action = $page_wide
			? $this->interpret_page_wide( $original_message, $context, array_slice( $history, -20 ) )
			: PKCA_OpenAI::interpret( $message, $context, array_slice( $history, -20 ) );
		if ( is_wp_error( $action ) ) {
			return $action;
		}
		$multi_actions = json_decode( (string) ( $action['changes_json'] ?? '' ), true );
		if ( is_array( $multi_actions ) && count( $multi_actions ) > 1 ) {
			$prepared = array();
			foreach ( array_slice( $multi_actions, 0, 50 ) as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				if ( $selection ) {
					$item['section'] = $selection['section'];
				}
				$field = $this->find_action_field( $item, $context );
				if ( ! $field || ( $selection && (int) $item['section'] !== (int) $selection['section'] ) ) {
					return rest_ensure_response( array( 'action' => 'clarify', 'message' => 'Ik kan niet alle gevraagde velden veilig binnen de aangewezen sectie vinden. Benoem de betreffende velden iets specifieker.' ) );
				}
				$item['field_name'] = $field['name'];
				$item['field_type'] = $field['type'];
				if ( in_array( $item['field_type'], array( 'repeater', 'gallery' ), true ) ) {
					$item['value'] = json_decode( (string) ( $item['value_json'] ?? '' ), true );
					if ( ! is_array( $item['value'] ) ) {
						return new WP_Error( 'pkca_structured_value', 'Een van de voorgestelde verzamelvelden kon niet veilig worden verwerkt.', array( 'status' => 422 ) );
					}
				}
				$prepared[] = array(
					'field' => $field,
					'item'  => $item,
				);
			}
			if ( count( $prepared ) < 2 ) {
				return rest_ensure_response( array( 'action' => 'clarify', 'message' => 'Ik kon niet alle deelopdrachten veilig vertalen naar afzonderlijke velden.' ) );
			}
			$queued = array();
			$skipped = array();
			foreach ( $prepared as $prepared_change ) {
				$field = $prepared_change['field'];
				$item = $prepared_change['item'];
				$change = PKCA_Content::add_change(
					$post_id,
					array(
						'section'     => $item['section'],
						'field_path'  => $field['path'],
						'field_name'  => $item['field_name'],
						'type'        => $item['field_type'],
						'value'       => $item['value'] ?? '',
						'operation'   => $item['operation'] ?? 'set',
						'search'      => $item['search'] ?? null,
						'replacement' => $item['replacement'] ?? null,
					)
				);
				if ( is_wp_error( $change ) ) {
					$skipped[] = array(
						'field'   => (string) ( $field['label'] ?? $field['name'] ?? 'veld' ),
						'code'    => $change->get_error_code(),
						'message' => $change->get_error_message(),
					);
					continue;
				}
				$queued[] = $change;
			}
			if ( array() === $queued ) {
				$only_unchanged = array() !== $skipped && count(
					array_filter( $skipped, static fn( array $item ): bool => 'pkca_no_change' === $item['code'] )
				) === count( $skipped );
				if ( $only_unchanged ) {
					return rest_ensure_response(
						array(
							'action'  => 'answer',
							'message' => 'De controle is uitgevoerd, maar deze voorstellen waren al gelijk aan de actuele preview. Er zijn geen dubbele wijzigingen toegevoegd.',
							'changes' => $context['changes'] ?? array(),
						)
					);
				}
				return new WP_Error( 'pkca_batch_failed', (string) ( $skipped[0]['message'] ?? 'De voorgestelde wijzigingen konden niet worden verwerkt.' ), array( 'status' => 422, 'skipped' => $skipped ) );
			}
			$updated_context = PKCA_Content::inspect( $post_id );
			$action['change'] = $queued[0] ?? null;
			$action['queued'] = $queued;
			$action['changes'] = is_wp_error( $updated_context ) ? array() : $updated_context['changes'];
			$action['skipped'] = $skipped;
			$action['message'] = count( $queued ) . ' wijzigingen zijn klaargezet.';
			if ( $skipped ) {
				$action['message'] .= ' ' . count( $skipped ) . ' ongewijzigde of ongeldige voorstel(len) zijn overgeslagen; de overige velden zijn wel verwerkt.';
			}
			return rest_ensure_response( $action );
		}
		if ( ! empty( $selection ) && 'change' === ( $action['action'] ?? '' ) ) {
			$is_structural = in_array( $action['field_type'] ?? '', array( 'repeater', 'gallery' ), true );
			if ( empty( $selection['field_path'] ) && ! $is_structural ) {
				$intent_field = $this->resolve_field_from_layout_intent( $action, $original_message, $selection, $context );
				if ( ! $intent_field ) {
					return rest_ensure_response(
						array(
							'action'  => 'clarify',
							'message' => 'In deze sectie zijn meerdere passende velden. Klik de bedoelde tekst of knop zelf aan.',
						)
					);
				}
				$selection['field_path'] = $intent_field['path'];
				$selection['field_name'] = $intent_field['name'];
				$selection['type'] = $intent_field['type'];
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
		$action = $this->enforce_selected_partial_replacement( $action, $original_message, $selection ?? array() );
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

	private function interpret_page_wide( string $message, array $context, array $history ): array|WP_Error {
		$all_changes = array();
		$errors = array();
		$sections = (array) ( $context['sections'] ?? array() );
		foreach ( array_chunk( $sections, 5 ) as $chunk_index => $section_chunk ) {
			$chunk_context = $context;
			$chunk_context['sections'] = $section_chunk;
			$chunk_context['request_scope'] = 'page_chunk';
			$chunk_context['chunk'] = array(
				'number' => $chunk_index + 1,
				'total'  => (int) ceil( count( $sections ) / 5 ),
				'instruction' => 'Controleer ieder editable veld in deze secties. Geef alle concrete wijzigingen terug en sla niets over dat onder de gebruikersopdracht valt. Bij een redactionele pagina-audit betekent inconsistentie ook: tekst over een andere dienst of ander onderwerp dan de paginatitel, een afwijkende aanspreekvorm, foutieve CTA-copy en onderling niet-aansluitende koppen, intro\'s en alinea\'s. Een grammaticaal correcte maar inhoudelijk off-topic tekst moet dus ook worden herschreven. Houd terminologie en aanspreekvorm over alle secties gelijk aan de bedrijfscontext en de dominante correcte paginatekst.',
			);
			unset( $chunk_context['selection'], $chunk_context['visual_selection'] );
			$result = PKCA_OpenAI::interpret( $message, $chunk_context, $history );
			if ( is_wp_error( $result ) ) {
				$errors[] = $result;
				continue;
			}
			$chunk_changes = $this->response_changes( $result );
			foreach ( $chunk_changes as $candidate ) {
				if ( ! is_array( $candidate ) || empty( $candidate['section'] ) || empty( $candidate['field_path'] ) ) {
					continue;
				}
				$key = (int) $candidate['section'] . ':' . implode( '.', (array) $candidate['field_path'] );
				$all_changes[ $key ] = $candidate;
				if ( count( $all_changes ) >= 50 ) {
					break 2;
				}
			}
		}

		$this->complete_placeholder_changes( $message, $context, $history, $all_changes, $errors, 5 );
		// A model can still omit an item from a structured batch. Recalculate from
		// the actual proposals and retry only the missing fields one by one. The
		// second individual pass handles an occasional unchanged/invalid response.
		$this->complete_placeholder_changes( $message, $context, $history, $all_changes, $errors, 1 );
		$this->complete_placeholder_changes( $message, $context, $history, $all_changes, $errors, 1 );

		$quality_targets = $this->critical_quality_fields( $message, $context );
		if ( $quality_targets ) {
			$quality_context = $context;
			$quality_context['request_scope'] = 'quality_audit';
			$quality_context['required_targets'] = array_map(
				static fn( array $target ): array => array(
					'section'    => $target['section'],
					'field_path' => $target['field']['path'],
					'label'      => $target['field']['label'] ?? $target['field']['name'] ?? '',
					'value'      => $target['field']['value'] ?? '',
				),
				$quality_targets
			);
			$quality_context['proposed_changes'] = array_values( $all_changes );
			$quality_sections = array();
			foreach ( $quality_targets as $target ) {
				$number = (int) $target['section'];
				if ( ! isset( $quality_sections[ $number ] ) ) {
					$quality_sections[ $number ] = $target['section_data'];
					$quality_sections[ $number ]['fields'] = array();
				}
				$quality_sections[ $number ]['fields'][] = $target['field'];
			}
			$quality_context['sections'] = array_values( $quality_sections );
			unset( $quality_context['selection'], $quality_context['visual_selection'] );
			$quality_message = $message . "\n\nKRITIEKE REDACTIONELE CONTROLE: beoordeel de aangeleverde hero als één samenhangend blok binnen het paginaonderwerp. Corrigeer spelling en grammatica, controleer of intro en CTA inhoudelijk aansluiten op paginatitel en bedrijfscontext, en behoud correcte velden. Geef alleen werkelijk benodigde wijzigingen terug. Controleer ieder required_target expliciet; laat een correct veld weg.";
			$result = PKCA_OpenAI::interpret( $quality_message, $quality_context, $history );
			if ( is_wp_error( $result ) ) {
				$errors[] = $result;
			} else {
				foreach ( $this->response_changes( $result ) as $candidate ) {
					if ( ! is_array( $candidate ) || empty( $candidate['section'] ) || empty( $candidate['field_path'] ) ) {
						continue;
					}
					$key = (int) $candidate['section'] . ':' . implode( '.', (array) $candidate['field_path'] );
					$all_changes[ $key ] = $candidate;
				}
			}
		}

		if ( array() === $all_changes ) {
			if ( $errors ) {
				return $errors[0];
			}
			return array(
				'action'       => 'answer',
				'message'      => 'De volledige pagina is gecontroleerd, maar er zijn geen concrete nieuwe wijzigingen gevonden.',
				'section'      => null,
				'field_path'   => null,
				'field_name'   => null,
				'field_type'   => null,
				'value'        => null,
				'value_json'   => null,
				'changes_json' => null,
				'operation'    => null,
				'search'       => null,
				'replacement'  => null,
			);
		}

		if ( $this->is_text_quality_request( $message ) ) {
			$all_changes = array_filter(
				$all_changes,
				static fn( array $change ): bool => 'text' === ( $change['field_type'] ?? '' )
			);
		}

		if ( array() === $all_changes ) {
			return array(
				'action'       => 'answer',
				'message'      => 'De volledige pagina is gecontroleerd, maar er zijn geen concrete nieuwe tekstwijzigingen gevonden.',
				'section'      => null,
				'field_path'   => null,
				'field_name'   => null,
				'field_type'   => null,
				'value'        => null,
				'value_json'   => null,
				'changes_json' => null,
				'operation'    => null,
				'search'       => null,
				'replacement'  => null,
			);
		}

		$changes = array_values( $all_changes );
		return array(
			'action'       => 'change',
			'message'      => count( $changes ) . ' voorstellen gevonden in de volledige pagina.',
			'section'      => $changes[0]['section'] ?? null,
			'field_path'   => $changes[0]['field_path'] ?? null,
			'field_name'   => $changes[0]['field_name'] ?? null,
			'field_type'   => $changes[0]['field_type'] ?? null,
			'value'        => $changes[0]['value'] ?? null,
			'value_json'   => $changes[0]['value_json'] ?? null,
			'changes_json' => wp_json_encode( $changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'operation'    => $changes[0]['operation'] ?? null,
			'search'       => $changes[0]['search'] ?? null,
			'replacement'  => $changes[0]['replacement'] ?? null,
			'chunk_errors' => count( $errors ),
		);
	}

	private function complete_placeholder_changes( string $message, array $context, array $history, array &$all_changes, array &$errors, int $chunk_size ): void {
		$missing_placeholders = $this->uncovered_placeholder_fields( $context, $all_changes );
		foreach ( array_chunk( $missing_placeholders, $chunk_size ) as $required_chunk ) {
			$required_context = $context;
			$required_context['request_scope'] = 'required_fields';
			$required_context['required_targets'] = array_map(
				static fn( array $target ): array => array(
					'section'    => $target['section'],
					'field_path' => $target['field']['path'],
					'label'      => $target['field']['label'] ?? $target['field']['name'] ?? '',
					'value'      => $target['field']['value'] ?? '',
				),
				$required_chunk
			);
			$required_sections = array();
			foreach ( $required_chunk as $target ) {
				$section_number = (int) $target['section'];
				if ( ! isset( $required_sections[ $section_number ] ) ) {
					$required_sections[ $section_number ] = $target['section_data'];
					$required_sections[ $section_number ]['fields'] = array();
				}
				$required_sections[ $section_number ]['fields'][] = $target['field'];
			}
			$required_context['sections'] = array_values( $required_sections );
			unset( $required_context['selection'], $required_context['visual_selection'] );
			$required_message = $message . "\n\nVERPLICHTE VOLLEDIGHEIDSCONTROLE: ieder veld in required_targets bevat nog placeholdertekst die onder de opdracht valt. Geef voor IEDER veld een concrete, inhoudelijk passende nieuwe waarde terug. Gebruik exact section en field_path, neem geen ongewijzigde waarden op en bundel alle resultaten in changes_json.";
			if ( 1 === count( $required_chunk ) ) {
				$required_message .= ' Dit is exact één verplicht tekstveld: geef action=change met een concrete nieuwe volledige value en exact hetzelfde section en field_path. Geef geen answer of clarify en kies geen bovenliggend repeaterveld.';
			}
			$result = PKCA_OpenAI::interpret( $required_message, $required_context, $history );
			if ( is_wp_error( $result ) ) {
				$errors[] = $result;
				continue;
			}
			foreach ( $this->response_changes( $result ) as $candidate ) {
				if ( ! is_array( $candidate ) || empty( $candidate['section'] ) || empty( $candidate['field_path'] ) ) {
					continue;
				}
				$key = (int) $candidate['section'] . ':' . implode( '.', (array) $candidate['field_path'] );
				$all_changes[ $key ] = $candidate;
			}
		}
	}

	private function response_changes( array $result ): array {
		$changes = json_decode( (string) ( $result['changes_json'] ?? '' ), true );
		if ( ! is_array( $changes ) ) {
			$changes = array();
		}
		if ( 'change' !== ( $result['action'] ?? '' ) || ! isset( $result['section'], $result['field_path'] ) ) {
			return $changes;
		}
		$single = array_intersect_key(
			$result,
			array_flip( array( 'section', 'field_path', 'field_name', 'field_type', 'value', 'value_json', 'operation', 'search', 'replacement' ) )
		);
		$single_key = (int) ( $single['section'] ?? 0 ) . ':' . implode( '.', (array) ( $single['field_path'] ?? array() ) );
		foreach ( $changes as $candidate ) {
			$candidate_key = (int) ( $candidate['section'] ?? 0 ) . ':' . implode( '.', (array) ( $candidate['field_path'] ?? array() ) );
			if ( $candidate_key === $single_key ) {
				return $changes;
			}
		}
		$changes[] = $single;
		return $changes;
	}

	private function uncovered_placeholder_fields( array $context, array $changes ): array {
		$targets = array();
		foreach ( (array) ( $context['sections'] ?? array() ) as $section ) {
			foreach ( (array) ( $section['fields'] ?? array() ) as $field ) {
				if ( 'text' !== ( $field['type'] ?? '' ) || false === ( $field['editable'] ?? true ) ) {
					continue;
				}
				$value = html_entity_decode( wp_strip_all_tags( (string) ( $field['value'] ?? '' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( ! preg_match( '/\b(?:lorem\s+ipsum|dolor\s+sit\s+amet|placeholder(?:tekst)?)\b/iu', $value ) ) {
					continue;
				}
				$covered = false;
				$field_path = array_map( 'strval', (array) ( $field['path'] ?? array() ) );
				foreach ( $changes as $change ) {
					if ( (int) ( $change['section'] ?? 0 ) !== (int) ( $section['number'] ?? 0 ) ) {
						continue;
					}
					$change_path = array_map( 'strval', (array) ( $change['field_path'] ?? array() ) );
					$exact = $change_path === $field_path;
					$parent_repeater = 'repeater' === ( $change['field_type'] ?? '' )
						&& count( $change_path ) < count( $field_path )
						&& $change_path === array_slice( $field_path, 0, count( $change_path ) );
					if ( $exact || $parent_repeater ) {
						$covered = true;
						break;
					}
				}
				if ( ! $covered ) {
					$targets[] = array( 'section' => (int) $section['number'], 'section_data' => $section, 'field' => $field );
				}
			}
		}
		return $targets;
	}

	private function critical_quality_fields( string $message, array $context ): array {
		if ( ! preg_match( '/\b(?:spelling|spelfout|spelfouten|grammatica|inconsistent|inconsistenties|controleer|check|verbeter|opschonen)\b/iu', $message ) ) {
			return array();
		}

		$targets = array();
		foreach ( (array) ( $context['sections'] ?? array() ) as $section ) {
			$layout = mb_strtolower( (string) ( $section['layout'] ?? $section['name'] ?? '' ) );
			if ( ! str_contains( $layout, 'hero' ) ) {
				continue;
			}
			foreach ( (array) ( $section['fields'] ?? array() ) as $field ) {
				if ( 'text' !== ( $field['type'] ?? '' ) || false === ( $field['editable'] ?? true ) ) {
					continue;
				}
				$path_text = mb_strtolower( implode( '/', array_map( 'strval', (array) ( $field['path'] ?? array() ) ) ) );
				if ( ! preg_match( '#(?:heading(?:/text)?|title|titel|intro|text|tekst|subtitle|subtitel|button(?:s)?/.+/(?:title|text|label)|cta/.+/(?:title|text|label))$#u', $path_text ) ) {
					continue;
				}
				$targets[] = array( 'section' => (int) ( $section['number'] ?? 0 ), 'section_data' => $section, 'field' => $field );
			}
		}
		return $targets;
	}

	private function is_text_quality_request( string $message ): bool {
		return (bool) preg_match( '/\b(?:spelling|spelfout|spelfouten|grammatica|inconsistent|inconsistenties|lorem(?:\s+ipsum)?|placeholderteksten?|teksten?|content)\b/iu', $message )
			&& ! preg_match( '/\b(?:afbeelding|afbeeldingen|foto|foto(?:s|\'s)|beeld|icoon|iconen|kleur|kleuren|layout|opmaak)\b/iu', $message );
	}

	private function is_page_wide_request( string $message ): bool {
		$message = mb_strtolower( trim( $message ) );
		if ( '' === $message ) {
			return false;
		}

		return (bool) preg_match(
			'/\b(?:hele|gehele|volledige|complete)\s+pagina\b|\b(?:heel|volledig|compleet)\s+de\s+pagina\b|\bpagina\s+(?:volledig\s+)?(?:scannen|doorlopen|controleren|opschonen)\b|\b(?:scan|doorloop|controleer|schoon)\s+(?:deze\s+|de\s+)?(?:hele\s+|gehele\s+|volledige\s+)?pagina\b|\boveral\b|\balle\s+(?:resterende\s+|overige\s+)?(?:lorem(?:\s+ipsum)?|placeholder|foute|onjuiste)\b|\b(?:nog\s+)?meer\s+(?:lorem(?:\s+ipsum)?|placeholderteksten?|foute\s+teksten?|onjuiste\s+teksten?)\b|\b(?:rest|resterende deel)\s+van\s+de\s+pagina\b|\b(?:whole|entire|complete)\s+page\b|\bpage[- ]wide\b/iu',
			$message
		);
	}

	private function normalize_editor_language( string $message, ?array $selection, array $context ): string {
		if ( ! $selection ) {
			return $message;
		}
		$section = $context['sections'][ (int) $selection['section'] - 1 ] ?? null;
		if ( ! is_array( $section ) ) {
			return $message;
		}
		$has_button = false;
		foreach ( (array) ( $section['fields'] ?? array() ) as $field ) {
			$path = implode( '/', (array) ( $field['path'] ?? array() ) );
			if ( preg_match( '#(^|/)(buttons?|cta)(/|$)#iu', $path ) ) {
				$has_button = true;
				break;
			}
		}
		if ( $has_button ) {
			$message = preg_replace( '/\bkop\s+tekst\b/iu', 'knoptekst', $message ) ?? $message;
		}
		return $message;
	}

	private function find_action_field( array $action, array $context ): ?array {
		$section_number = absint( $action['section'] ?? 0 );
		$path = is_array( $action['field_path'] ?? null ) ? array_map( 'strval', $action['field_path'] ) : array();
		$section = $context['sections'][ $section_number - 1 ] ?? null;
		if ( ! is_array( $section ) || ! $path ) {
			return null;
		}
		foreach ( (array) ( $section['fields'] ?? array() ) as $field ) {
			if ( ( $field['path'] ?? array() ) === $path && false !== ( $field['editable'] ?? true ) ) {
				return $field;
			}
		}
		return null;
	}

	private function resolve_field_from_layout_intent( array $action, string $message, array $selection, array $context ): ?array {
		$section = $context['sections'][ (int) ( $selection['section'] ?? 0 ) - 1 ] ?? null;
		if ( ! is_array( $section ) ) {
			return null;
		}
		$fields = array_values(
			array_filter(
				(array) ( $section['fields'] ?? array() ),
				static fn( array $field ): bool => false !== ( $field['editable'] ?? true )
			)
		);

		if ( preg_match( '/\b(knoptekst|buttontekst|(?:knop|button|cta)[ -]?(?:tekst|titel))\b/iu', $message ) ) {
			$button_fields = array_values(
				array_filter(
					$fields,
					static function ( array $field ): bool {
						$path = array_map( 'strtolower', (array) ( $field['path'] ?? array() ) );
						return 'text' === ( $field['type'] ?? '' )
							&& 'title' === (string) end( $path )
							&& ( in_array( 'button', $path, true ) || in_array( 'buttons', $path, true ) );
					}
				)
			);
			if ( count( $button_fields ) > 1 && ! empty( $selection['value'] ) ) {
				$visible_matches = array_values(
					array_filter(
						$button_fields,
						static fn( array $field ): bool => '' !== (string) ( $field['value'] ?? '' ) && str_contains( (string) $selection['value'], (string) $field['value'] )
					)
				);
				if ( 1 === count( $visible_matches ) ) {
					return $visible_matches[0];
				}
			}
			if ( 1 === count( $button_fields ) ) {
				return $button_fields[0];
			}
		}

		$action_path = is_array( $action['field_path'] ?? null ) ? array_map( 'strval', $action['field_path'] ) : array();
		$matches = array_values(
			array_filter(
				$fields,
				static fn( array $field ): bool => $action_path && ( $field['path'] ?? array() ) === $action_path && ( empty( $action['field_type'] ) || ( $field['type'] ?? '' ) === $action['field_type'] )
			)
		);
		return 1 === count( $matches ) ? $matches[0] : null;
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
		$target = PKCA_Content::target( $this->request_target( $request ) );
		$id = media_handle_upload( 'file', is_array( $target ) && 'post' === $target['type'] ? (int) $target['acf_id'] : 0 );
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
		$result = PKCA_Content::publish( $this->request_target( $request ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public function discard( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = $this->request_target( $request );
		$change_id = sanitize_text_field( (string) $request->get_param( 'change_id' ) );
		if ( '' !== $change_id ) {
			if ( ! PKCA_Content::discard_change( $post_id, $change_id ) ) {
				return new WP_Error( 'pkca_change_not_found', 'Deze wijziging staat niet meer klaar.', array( 'status' => 404 ) );
			}
			$context = PKCA_Content::inspect( $post_id );
			return is_wp_error( $context ) ? $context : rest_ensure_response( array( 'discarded' => true, 'changes' => $context['changes'] ) );
		}
		PKCA_Content::discard( $post_id );
		return rest_ensure_response( array( 'discarded' => true, 'changes' => array() ) );
	}

	private function request_target( WP_REST_Request $request ): int|string {
		$value = (string) $request->get_param( 'post_id' );
		return ctype_digit( $value ) ? absint( $value ) : sanitize_text_field( $value );
	}
}
