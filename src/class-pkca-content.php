<?php

defined( 'ABSPATH' ) || exit;

final class PKCA_Content {
	private const TRANSIENT_PREFIX = 'pkca_changes_';
	private static bool $suppress_preview = false;

	public static function preview_is_suppressed(): bool {
		return self::$suppress_preview;
	}

	public static function inspect( int|string $post_id ): array|WP_Error {
		if ( ! function_exists( 'get_field' ) ) {
			return new WP_Error( 'pkca_acf_missing', 'ACF is niet actief.', array( 'status' => 500 ) );
		}

		$target = self::target( $post_id );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		self::$suppress_preview = true;
		$rows = get_field( $target['field'], $target['acf_id'] );
		self::$suppress_preview = false;
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$layout_schemas = self::layout_schemas( $post_id );
		$base_sections = self::build_sections( $rows, $layout_schemas );
		$pending_changes = self::with_required_condition_changes( self::get_changes( $post_id ), $base_sections );
		$preview_rows = $rows;
		foreach ( $pending_changes as $pending ) {
			$preview_rows = self::apply_change_to_value( $preview_rows, $pending, false );
		}
		$sections = self::build_sections( $preview_rows, $layout_schemas );
		foreach ( $sections as &$section ) {
			foreach ( $section['fields'] as &$field ) {
				$original = self::find_section_field( $base_sections, (int) $section['row_index'], (int) $section['layout_index'], (array) $field['path'] );
				if ( $original && ! self::stored_values_match( $original['value'] ?? null, $field['value'] ?? null, (string) ( $field['type'] ?? '' ) ) ) {
					$field['original_value'] = $original['value'];
				}
			}
			unset( $field );
		}
		unset( $section );

		return array(
			'post'     => array(
				'id'        => $target['key'],
				'title'     => $target['title'],
				'post_type' => $target['post_type'],
				'url'       => $target['url'],
				'modified'  => self::target_version( $target, $rows ),
			),
			'sections' => $sections,
			'link_targets' => self::link_targets(),
			'changes'  => array_map( array( self::class, 'decorate_change' ), $pending_changes ),
		);
	}

	private static function build_sections( array $rows, array $layout_schemas ): array {
		$sections = array();
		$nested_sections = array();
		foreach ( $rows as $row_index => $row ) {
			$layouts = isset( $row['flex_content'] ) && is_array( $row['flex_content'] ) ? $row['flex_content'] : array();
			foreach ( $layouts as $layout_index => $layout ) {
				if ( ! is_array( $layout ) ) {
					continue;
				}

				$fields = array();
				$nested_roots = array();
				$layout_name = (string) ( $layout['acf_fc_layout'] ?? '' );
				if ( isset( $layout_schemas[ $layout_name ] ) ) {
					self::apply_field_schema( $layout_schemas[ $layout_name ], $layout, array(), $fields, array() );
					$nested_roots = self::collect_nested_flexible_sections(
						$layout_schemas[ $layout_name ],
						$layout,
						array(),
						array(),
						$row_index,
						$layout_index,
						count( $sections ) + 1,
						$nested_sections
					);
				}
				// Keep the exact ACF definition order. Values that exist outside the
				// registered schema are appended afterwards as a backwards-compatible fallback.
				self::flatten_fields( $layout, array(), $fields );
				if ( $nested_roots ) {
					$fields = array_values(
						array_filter(
							$fields,
							static fn( array $field ): bool => ! in_array( (string) ( $field['path'][0] ?? '' ), $nested_roots, true )
						)
					);
				}
				$sections[] = array(
					'number'       => count( $sections ) + 1,
					'row_index'    => $row_index,
					'layout_index' => $layout_index,
					'block_id'     => (string) ( $row['block_id'] ?? '' ),
					'layout'       => (string) ( $layout['acf_fc_layout'] ?? 'onbekend' ),
					'fields'       => $fields,
				);
			}
		}
		$sections = array_merge( $sections, $nested_sections );
		foreach ( $sections as $section_index => &$section ) {
			$section['number'] = $section_index + 1;
		}
		unset( $section );
		return $sections;
	}

	private static function find_section_field( array $sections, int $row_index, int $layout_index, array $path ): ?array {
		foreach ( $sections as $section ) {
			if ( (int) ( $section['row_index'] ?? -1 ) !== $row_index || (int) ( $section['layout_index'] ?? -1 ) !== $layout_index ) {
				continue;
			}
			foreach ( (array) ( $section['fields'] ?? array() ) as $field ) {
				if ( ( $field['path'] ?? array() ) === $path ) {
					return $field;
				}
			}
		}
		return null;
	}

	private static function collect_nested_flexible_sections( array $schema, array $values, array $path, array $raw_path, int $row_index, int $layout_index, int $parent_section, array &$sections ): array {
		$nested_roots = array();
		foreach ( $schema as $definition ) {
			$name = (string) ( $definition['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$type = (string) ( $definition['type'] ?? '' );
			$value = $values[ $name ] ?? null;
			$current_path = array_merge( $path, array( $name ) );
			$current_raw_path = array_merge( $raw_path, array( (string) ( $definition['key'] ?? $name ) ) );
			if ( 'flexible_content' === $type && is_array( $value ) ) {
				$nested_roots[] = (string) ( $current_path[0] ?? $name );
				$layouts = array();
				foreach ( (array) ( $definition['layouts'] ?? array() ) as $layout_definition ) {
					$layouts[ (string) ( $layout_definition['name'] ?? '' ) ] = (array) ( $layout_definition['sub_fields'] ?? array() );
				}
				foreach ( $value as $nested_index => $nested_layout ) {
					if ( ! is_array( $nested_layout ) ) {
						continue;
					}
					$nested_name = (string) ( $nested_layout['acf_fc_layout'] ?? '' );
					$nested_schema = $layouts[ $nested_name ] ?? array();
					$nested_path = array_merge( $current_path, array( (string) $nested_index ) );
					$nested_raw_path = array_merge( $current_raw_path, array( (string) $nested_index ) );
					$fields = array();
					self::apply_field_schema( $nested_schema, $nested_layout, $nested_path, $fields, $nested_raw_path );
					self::flatten_fields( $nested_layout, $nested_path, $fields );
					$sections[] = array(
						'number'         => 0,
						'row_index'      => $row_index,
						'layout_index'   => $layout_index,
						'block_id'       => '',
						'layout'         => $nested_name ?: 'onbekend',
						'fields'         => $fields,
						'nested'         => true,
						'parent_section' => $parent_section,
						'nested_path'    => $nested_path,
					);
					self::collect_nested_flexible_sections( $nested_schema, $nested_layout, $nested_path, $nested_raw_path, $row_index, $layout_index, $parent_section, $sections );
				}
				continue;
			}
			if ( 'repeater' === $type && is_array( $value ) ) {
				foreach ( $value as $item_index => $item ) {
					if ( is_array( $item ) ) {
						$roots = self::collect_nested_flexible_sections( (array) ( $definition['sub_fields'] ?? array() ), $item, array_merge( $current_path, array( (string) $item_index ) ), array_merge( $current_raw_path, array( (string) $item_index ) ), $row_index, $layout_index, $parent_section, $sections );
						$nested_roots = array_merge( $nested_roots, $roots );
					}
				}
			} elseif ( in_array( $type, array( 'group', 'clone' ), true ) && is_array( $value ) ) {
				$roots = self::collect_nested_flexible_sections( (array) ( $definition['sub_fields'] ?? array() ), $value, $current_path, $current_raw_path, $row_index, $layout_index, $parent_section, $sections );
				$nested_roots = array_merge( $nested_roots, $roots );
			}
		}
		return array_values( array_unique( $nested_roots ) );
	}

	private static function layout_schemas( int|string $post_id ): array {
		$target = self::target( $post_id );
		if ( is_wp_error( $target ) ) {
			return array();
		}
		$root = get_field_object( $target['field'], $target['acf_id'], false, false );
		$schemas = array();
		foreach ( (array) ( $root['sub_fields'] ?? array() ) as $sub_field ) {
			if ( 'flex_content' !== ( $sub_field['name'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $sub_field['layouts'] ?? array() ) as $layout ) {
				$schemas[ (string) ( $layout['name'] ?? '' ) ] = (array) ( $layout['sub_fields'] ?? array() );
			}
		}
		return $schemas;
	}

	private static function apply_field_schema( array $schema, array $values, array $path, array &$fields, array $raw_path ): void {
		foreach ( $schema as $definition ) {
			$name = (string) ( $definition['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$current_path = array_merge( $path, array( $name ) );
			$current_raw_path = array_merge( $raw_path, array( (string) ( $definition['key'] ?? $name ) ) );
			$value = $values[ $name ] ?? null;
			$acf_type = (string) ( $definition['type'] ?? 'text' );
			if ( 'link' === $acf_type ) {
				$link_value = is_array( $value ) ? $value : array();
				self::apply_field_schema(
					array(
						array( 'name' => 'title', 'label' => (string) ( $definition['label'] ?? $name ) . ' - tekst', 'type' => 'text' ),
						array( 'name' => 'url', 'label' => (string) ( $definition['label'] ?? $name ) . ' - URL', 'type' => 'url' ),
						array( 'name' => 'target', 'label' => (string) ( $definition['label'] ?? $name ) . ' - openen in', 'type' => 'select', 'choices' => array( '' => 'Hetzelfde venster', '_blank' => 'Nieuw venster' ) ),
					),
					$link_value,
					$current_path,
					$fields,
					$current_raw_path
				);
				continue;
			}
			if ( in_array( $acf_type, array( 'group', 'clone' ), true ) && ! empty( $definition['sub_fields'] ) ) {
				self::apply_field_schema( (array) $definition['sub_fields'], is_array( $value ) ? $value : array(), $current_path, $fields, $current_raw_path );
				continue;
			}
			if ( 'repeater' === $acf_type && ! empty( $definition['sub_fields'] ) ) {
				$row_fields = array();
				self::apply_field_schema( (array) $definition['sub_fields'], self::schema_defaults( (array) $definition['sub_fields'] ), array(), $row_fields, array() );
				$fields[] = array( 'key' => (string) ( $definition['key'] ?? '' ), 'path' => $current_path, 'raw_path' => $current_raw_path, 'name' => $name, 'label' => (string) ( $definition['label'] ?? $name ), 'type' => 'repeater', 'acf_type' => 'repeater', 'preview' => count( is_array( $value ) ? $value : array() ) . ' rijen', 'value' => is_array( $value ) ? $value : array(), 'row_template' => self::schema_defaults( (array) $definition['sub_fields'] ), 'row_fields' => $row_fields, 'min' => max( 0, (int) ( $definition['min'] ?? 0 ) ), 'max' => max( 0, (int) ( $definition['max'] ?? 0 ) ), 'editable' => true, 'choices' => array(), 'images' => array(), 'instructions' => (string) ( $definition['instructions'] ?? '' ), 'placeholder' => '', 'required' => ! empty( $definition['required'] ), 'conditional_logic' => is_array( $definition['conditional_logic'] ?? null ) ? $definition['conditional_logic'] : array() );
				foreach ( is_array( $value ) ? $value : array() as $row_index => $row ) {
					self::apply_field_schema( (array) $definition['sub_fields'], is_array( $row ) ? $row : array(), array_merge( $current_path, array( (string) $row_index ) ), $fields, array_merge( $current_raw_path, array( (string) $row_index ) ) );
				}
				continue;
			}

			$type = self::schema_transport_type( $acf_type, $current_path );
			if ( 'textarea' === $acf_type && is_string( $value ) ) {
				// ACF may already have formatted new lines as <br>. Never feed that
				// presentation HTML back into the raw textarea value on save.
				$value = preg_replace( '/(?:<br\s*\/?>\s*)+/i', "\n", $value );
			}
			$index = null;
			foreach ( $fields as $field_index => $field ) {
				if ( $field['path'] === $current_path ) {
					$index = $field_index;
					break;
				}
			}
			$id = 'image' === $type ? ( is_array( $value ) ? (int) ( $value['ID'] ?? 0 ) : (int) $value ) : null;
			$schema_field = array(
				'key'        => (string) ( $definition['key'] ?? '' ),
				'path'       => $current_path,
				'raw_path'   => $current_raw_path,
				'name'       => $name,
				'label'      => (string) ( $definition['label'] ?? $name ),
				'type'       => $type,
				'acf_type'   => $acf_type,
				'preview'    => self::preview_value( $value, $type ),
				'value'      => self::transport_value( $value, $type ),
				'url'        => 'image' === $type && $id ? (string) wp_get_attachment_image_url( $id, 'medium' ) : '',
				'choices'    => (array) ( $definition['choices'] ?? array() ),
				'images'     => 'gallery' === $acf_type ? self::gallery_images( $value ) : array(),
				'instructions' => (string) ( $definition['instructions'] ?? '' ),
				'placeholder'  => (string) ( $definition['placeholder'] ?? '' ),
				'required'     => ! empty( $definition['required'] ),
				'conditional_logic' => is_array( $definition['conditional_logic'] ?? null ) ? $definition['conditional_logic'] : array(),
				'editable'   => ! in_array( $type, array( 'post', 'unsupported' ), true ) || 'gallery' === $acf_type,
			);
			if ( null === $index ) {
				$fields[] = $schema_field;
			} else {
				$fields[ $index ] = array_merge( $fields[ $index ], $schema_field );
			}
		}
	}

	private static function schema_defaults( array $schema ): array {
		$row = array();
		foreach ( $schema as $field ) {
			$name = (string) ( $field['name'] ?? '' );
			if ( ! $name ) continue;
			if ( 'link' === ( $field['type'] ?? '' ) ) {
				$row[ $name ] = array( 'title' => '', 'url' => '', 'target' => '' );
			} else {
				$row[ $name ] = ! empty( $field['sub_fields'] ) && in_array( $field['type'] ?? '', array( 'group', 'clone' ), true ) ? self::schema_defaults( (array) $field['sub_fields'] ) : ( $field['default_value'] ?? ( in_array( $field['type'] ?? '', array( 'repeater', 'gallery', 'relationship' ), true ) ? array() : '' ) );
			}
		}
		return $row;
	}

	public static function apply_changes_to_raw_value( mixed $value, int|string $post_id ): mixed {
		if ( self::$suppress_preview || ! is_array( $value ) ) {
			return $value;
		}
		$changes = self::get_changes( $post_id );
		if ( ! $changes ) {
			return $value;
		}
		$target = self::target( $post_id );
		if ( is_wp_error( $target ) ) {
			return $value;
		}
		$root = get_field_object( $target['field'], $target['acf_id'], false, false );
		$flex_key = '';
		foreach ( (array) ( $root['sub_fields'] ?? array() ) as $field ) {
			if ( 'flex_content' === ( $field['name'] ?? '' ) ) {
				$flex_key = (string) $field['key'];
			}
		}
		if ( ! $flex_key ) {
			return $value;
		}
		$inspection = self::inspect( $post_id );
		if ( is_wp_error( $inspection ) ) {
			return $value;
		}
		$changes = $inspection['changes'];
		foreach ( $changes as $change ) {
			$section = $inspection['sections'][ (int) $change['section'] - 1 ] ?? null;
			$field = null;
			foreach ( (array) ( $section['fields'] ?? array() ) as $candidate ) {
				if ( $candidate['path'] === $change['field_path'] ) { $field = $candidate; break; }
			}
			$row = (int) $change['row_index'];
			$layout = (int) $change['layout_index'];
			if ( $field && isset( $value[ $row ][ $flex_key ][ $layout ] ) ) {
				$replacement = $change['new_value'];
				if ( 'repeater' === ( $change['type'] ?? '' ) && ! empty( $field['key'] ) ) {
					$definition = acf_get_field( $field['key'] );
					$replacement = self::repeater_rows_for_acf_load( (array) $replacement, (array) ( $definition['sub_fields'] ?? array() ) );
				}
				self::set_nested_value( $value[ $row ][ $flex_key ][ $layout ], $field['raw_path'], $replacement );
			}
		}
		return $value;
	}

	public static function preview_sub_field_value( mixed $value, int|string $post_id, array $acf_field ): mixed {
		if ( self::$suppress_preview || empty( $acf_field['key'] ) ) {
			return $value;
		}
		foreach ( self::get_changes( $post_id ) as $change ) {
			$layout_marker = '_flex_content_' . (int) $change['layout_index'] . '_';
			$field_instance = (string) ( $acf_field['name'] ?? '' );
			$field_path = array_map( 'strval', (array) ( $change['field_path'] ?? array() ) );
			$link_property = $field_path ? end( $field_path ) : '';
			$link_parent_path = array_slice( $field_path, 0, -1 );
			$link_instance_suffix = '_' . implode( '_', $link_parent_path );
			$section_marker = 'content_repeater_' . (int) $change['row_index'] . '_flex_content_' . (int) $change['layout_index'] . '_';
			if (
				'link' === ( $acf_field['type'] ?? '' )
				&& is_array( $value )
				&& in_array( $link_property, array( 'title', 'url', 'target' ), true )
				&& str_contains( $field_instance, $section_marker )
				&& str_ends_with( $field_instance, $link_instance_suffix )
			) {
				$value[ $link_property ] = $change['new_value'];
				return $value;
			}
			$instance_suffix = '_' . implode( '_', array_map( 'strval', (array) ( $change['field_path'] ?? array() ) ) );
			if ( ! empty( $change['field_key'] ) && $change['field_key'] === $acf_field['key'] && ( ! str_contains( $field_instance, '_flex_content_' ) || str_contains( $field_instance, $layout_marker ) ) && str_ends_with( $field_instance, $instance_suffix ) ) {
				return 'repeater' === ( $acf_field['type'] ?? '' )
					? self::repeater_rows_for_acf_load( (array) $change['new_value'], (array) ( $acf_field['sub_fields'] ?? array() ) )
					: $change['new_value'];
			}
		}
		return $value;
	}

	/**
	 * ACF load_value works with internal field keys, while the frontend editor
	 * deliberately transports readable field names. Convert complete repeater
	 * rows back to their raw ACF shape before ACF formats and renders them.
	 */
	private static function repeater_rows_for_acf_load( array $rows, array $schema ): array {
		$result = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$raw_row = array();
			foreach ( $schema as $field ) {
				$name = (string) ( $field['name'] ?? '' );
				$key  = (string) ( $field['key'] ?? $name );
				if ( '' === $name || '' === $key ) {
					continue;
				}
				$field_value = array_key_exists( $name, $row ) ? $row[ $name ] : ( $row[ $key ] ?? ( $field['default_value'] ?? '' ) );
				$type = (string) ( $field['type'] ?? '' );
				if ( in_array( $type, array( 'group', 'clone' ), true ) && is_array( $field_value ) ) {
					$groups = self::repeater_rows_for_acf_load( array( $field_value ), (array) ( $field['sub_fields'] ?? array() ) );
					$field_value = $groups[0] ?? array();
				} elseif ( 'repeater' === $type ) {
					$field_value = self::repeater_rows_for_acf_load( is_array( $field_value ) ? $field_value : array(), (array) ( $field['sub_fields'] ?? array() ) );
				} elseif ( 'image' === $type && is_array( $field_value ) ) {
					$field_value = (int) ( $field_value['ID'] ?? $field_value['id'] ?? 0 );
				} elseif ( 'gallery' === $type ) {
					$field_value = array_values( array_filter( array_map( static fn( $image ) => is_array( $image ) ? (int) ( $image['ID'] ?? $image['id'] ?? 0 ) : (int) $image, is_array( $field_value ) ? $field_value : array() ) ) );
				}
				$raw_row[ $key ] = $field_value;
			}
			$result[] = $raw_row;
		}
		return $result;
	}

	private static function gallery_images( mixed $value ): array {
		$images = array();
		foreach ( is_array( $value ) ? $value : array() as $image ) {
			$id = is_array( $image ) ? (int) ( $image['ID'] ?? 0 ) : (int) $image;
			$url = $id ? wp_get_attachment_image_url( $id, 'thumbnail' ) : '';
			if ( $url ) {
				$images[] = array( 'id' => $id, 'url' => $url, 'title' => (string) get_the_title( $id ) );
			}
		}
		return $images;
	}

	private static function schema_transport_type( string $acf_type, array $path ): string {
		return match ( $acf_type ) {
			'image' => 'image',
			'gallery' => 'gallery',
			'url', 'link' => 'link',
			'number', 'range' => 'number',
			'true_false' => 'boolean',
			'select', 'radio', 'button_group' => 'option',
			'email' => 'email',
			'color_picker' => 'color',
			'post_object', 'relationship', 'page_link', 'taxonomy', 'user' => 'post',
			'text', 'textarea', 'wysiwyg', 'oembed' => 'text',
			default => 'url' === (string) end( $path ) && in_array( 'button', $path, true ) ? 'link' : 'unsupported',
		};
	}

	private static function link_targets(): array {
		$targets = array();
		$post_types = array_values( get_post_types( array( 'public' => true ), 'names' ) );
		$post_types = array_values( array_diff( $post_types, array( 'attachment' ) ) );
		foreach ( get_posts( array( 'post_type' => $post_types, 'post_status' => 'publish', 'posts_per_page' => 500, 'orderby' => 'title', 'order' => 'ASC' ) ) as $post ) {
			$url = get_permalink( $post );
			if ( $url ) {
				$targets[] = array( 'title' => get_the_title( $post ), 'url' => wp_make_link_relative( $url ), 'post_type' => $post->post_type );
			}
		}
		return $targets;
	}

	private static function decorate_change( array $change ): array {
		if ( 'image' === ( $change['type'] ?? '' ) ) {
			$change['old_url'] = wp_get_attachment_image_url( (int) $change['old_value'], 'full' ) ?: '';
			$change['new_url'] = wp_get_attachment_image_url( (int) $change['new_value'], 'full' ) ?: '';
			$change['new_html'] = wp_get_attachment_image( (int) $change['new_value'], 'large', false, array( 'loading' => 'eager' ) );
		}
		if ( 'gallery' === ( $change['type'] ?? '' ) ) {
			$change['new_images'] = self::gallery_images( (array) ( $change['new_value'] ?? array() ) );
		}
		return $change;
	}

	private static function flatten_fields( array $data, array $path, array &$result ): void {
		foreach ( $data as $key => $value ) {
			if ( 'acf_fc_layout' === $key ) {
				continue;
			}
			$current_path = array_merge( $path, array( (string) $key ) );
			if ( is_array( $value ) && ! self::is_media_value( $value ) && ! self::is_post_value( $value ) ) {
				self::flatten_fields( $value, $current_path, $result );
				continue;
			}

			$type = 'url' === (string) $key && in_array( 'button', $current_path, true ) ? 'link' : self::detect_type( $value );
			if ( ! in_array( $type, array( 'text', 'image', 'post', 'link' ), true ) || ! self::is_editable_field( $current_path, $type ) ) {
				continue;
			}

			foreach ( $result as $existing ) {
				if ( ( $existing['path'] ?? array() ) === $current_path ) {
					continue 2;
				}
			}

			$result[] = array(
				'path'    => $current_path,
				'name'    => (string) $key,
				'type'    => $type,
				'preview' => self::preview_value( $value, $type ),
				'value'   => self::transport_value( $value, $type ),
				'url'     => 'image' === $type ? ( is_array( $value ) ? (string) ( $value['url'] ?? '' ) : (string) wp_get_attachment_url( (int) $value ) ) : '',
				'editable'=> 'post' !== $type,
			);
		}
	}

	private static function is_editable_field( array $path, string $type ): bool {
		// Related posts remain visible as context, but editing their relationship is
		// intentionally outside this MVP. Technical presentation fields must never
		// be changed from an innocent content instruction.
		if ( 'post' === $type ) {
			return true;
		}
		$leaf = (string) end( $path );
		return ! in_array( $leaf, array( 'tag', 'variant', 'color', 'icon', 'background' ), true );
	}

	private static function detect_type( mixed $value ): string {
		if ( self::is_media_value( $value ) || ( is_int( $value ) && wp_attachment_is_image( $value ) ) ) {
			return 'image';
		}
		if ( self::is_post_value( $value ) || $value instanceof WP_Post ) {
			return 'post';
		}
		return is_string( $value ) && '' !== trim( wp_strip_all_tags( $value ) ) ? 'text' : 'other';
	}

	private static function is_media_value( mixed $value ): bool {
		return is_array( $value ) && isset( $value['ID'], $value['url'] ) && str_starts_with( (string) ( $value['mime_type'] ?? '' ), 'image/' );
	}

	private static function is_post_value( mixed $value ): bool {
		return is_array( $value ) && isset( $value['ID'], $value['post_type'] );
	}

	private static function preview_value( mixed $value, string $type ): string {
		if ( 'image' === $type ) {
			$id = is_array( $value ) ? (int) ( $value['ID'] ?? 0 ) : (int) $value;
			return (string) ( get_the_title( $id ) ?: basename( (string) wp_get_attachment_url( $id ) ) );
		}
		if ( 'post' === $type ) {
			if ( is_array( $value ) && array_is_list( $value ) ) {
				$titles = array_map( static fn( $item ) => get_the_title( $item instanceof WP_Post ? $item->ID : (int) ( is_array( $item ) ? ( $item['ID'] ?? 0 ) : $item ) ), $value );
				return implode( ', ', array_filter( $titles ) );
			}
			$id = $value instanceof WP_Post ? $value->ID : (int) ( $value['ID'] ?? 0 );
			return get_the_title( $id );
		}
		if ( 'boolean' === $type ) {
			return $value ? 'Ja' : 'Nee';
		}
		if ( is_array( $value ) ) {
			return array_is_list( $value )
				? count( $value ) . ' item(s)'
				: implode( ', ', array_keys( $value ) );
		}
		return wp_trim_words( wp_strip_all_tags( (string) $value ), 24 );
	}

	private static function transport_value( mixed $value, string $type ): mixed {
		if ( 'image' === $type ) {
			return is_array( $value ) ? (int) $value['ID'] : (int) $value;
		}
		if ( 'post' === $type ) {
			if ( is_array( $value ) && array_is_list( $value ) ) {
				return array_map( static fn( $item ) => $item instanceof WP_Post ? $item->ID : (int) ( is_array( $item ) ? ( $item['ID'] ?? 0 ) : $item ), $value );
			}
			return $value instanceof WP_Post ? $value->ID : (int) ( $value['ID'] ?? 0 );
		}
		if ( 'gallery' === $type ) {
			return array_values( array_filter( array_map( static fn( $image ) => is_array( $image ) ? (int) ( $image['ID'] ?? 0 ) : (int) $image, is_array( $value ) ? $value : array() ) ) );
		}
		if ( 'repeater' === $type ) {
			return is_array( $value ) ? $value : array();
		}
		if ( 'boolean' === $type ) {
			return (bool) $value;
		}
		if ( 'number' === $type ) {
			return is_numeric( $value ) ? $value + 0 : '';
		}
		return is_scalar( $value ) || null === $value ? (string) $value : '';
	}

	public static function add_change( int|string $post_id, array $change ): array|WP_Error {
		$inspection = self::inspect( $post_id );
		if ( is_wp_error( $inspection ) ) {
			return $inspection;
		}

		$target = self::find_target( $inspection, $change );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		if ( 'post' === $target['field']['type'] ) {
			return new WP_Error( 'pkca_post_read_only', 'Gekoppelde berichten zijn nu alleen context en kunnen nog niet veilig worden vervangen.', array( 'status' => 422 ) );
		}

		$changes = self::get_changes( $post_id );
		$current_value = $target['field']['value'];
		foreach ( $changes as $existing ) {
			if ( $existing['row_index'] === $target['row_index'] && $existing['layout_index'] === $target['layout_index'] && $existing['field_path'] === $target['field']['path'] ) {
				$current_value = $existing['new_value'];
				break;
			}
		}
		if ( 'repeater' === $target['field']['type'] ) {
			$new_value = self::sanitize_nested( is_array( $change['value'] ?? null ) ? $change['value'] : array() );
			$count = count( $new_value );
			$minimum = (int) ( $target['field']['min'] ?? 0 );
			$maximum = (int) ( $target['field']['max'] ?? 0 );
			if ( $minimum > 0 && $count < $minimum ) {
				return new WP_Error( 'pkca_repeater_min', sprintf( 'Dit veld vereist minimaal %d rij(en).', $minimum ), array( 'status' => 422 ) );
			}
			if ( $maximum > 0 && $count > $maximum ) {
				return new WP_Error( 'pkca_repeater_max', sprintf( 'Dit veld staat maximaal %d rij(en) toe.', $maximum ), array( 'status' => 422 ) );
			}
		} elseif ( 'image' === $target['field']['type'] ) {
			$new_value = absint( $change['value'] ?? 0 );
		} elseif ( 'gallery' === $target['field']['type'] ) {
			$new_value = array_values( array_filter( array_map( 'absint', is_array( $change['value'] ?? null ) ? $change['value'] : array() ), 'wp_attachment_is_image' ) );
		} else {
			$new_value = wp_kses_post( (string) ( $change['value'] ?? '' ) );
		}
		if ( 'number' === $target['field']['type'] ) {
			$new_value = is_numeric( $change['value'] ?? null ) ? ( $change['value'] + 0 ) : null;
			if ( null === $new_value ) {
				return new WP_Error( 'pkca_invalid_number', 'Vul een geldig getal in.', array( 'status' => 422 ) );
			}
		} elseif ( 'boolean' === $target['field']['type'] ) {
			$new_value = empty( $change['value'] ) || 'false' === $change['value'] ? 0 : 1;
		} elseif ( 'email' === $target['field']['type'] ) {
			$new_value = sanitize_email( (string) ( $change['value'] ?? '' ) );
		} elseif ( 'color' === $target['field']['type'] ) {
			$new_value = sanitize_hex_color( (string) ( $change['value'] ?? '' ) ) ?: '';
		} elseif ( 'option' === $target['field']['type'] ) {
			$new_value = sanitize_text_field( (string) ( $change['value'] ?? '' ) );
		}
		if ( 'link' === $target['field']['type'] ) {
			$new_value = self::sanitize_link( (string) ( $change['value'] ?? '' ) );
			if ( '' === $new_value ) {
				return new WP_Error( 'pkca_invalid_link', 'De opgegeven link is niet geldig.', array( 'status' => 422 ) );
			}
		}
		if ( 'image' === $target['field']['type'] && ( ! $new_value || ! wp_attachment_is_image( $new_value ) ) ) {
			return new WP_Error( 'pkca_invalid_image', 'De gekozen afbeelding bestaat niet of is geen geldig afbeeldingsbestand.', array( 'status' => 422 ) );
		}
		if ( 'text' === $target['field']['type'] && 'replace' === ( $change['operation'] ?? '' ) ) {
			$search = sanitize_text_field( (string) ( $change['search'] ?? '' ) );
			$replacement = wp_kses_post( (string) ( $change['replacement'] ?? '' ) );
			if ( '' === $search ) {
				return new WP_Error( 'pkca_replace_missing', 'Ik weet niet welk deel van de tekst vervangen moet worden.', array( 'status' => 422 ) );
			}
			$new_value = preg_replace( '/' . preg_quote( $search, '/' ) . '/iu', $replacement, (string) $current_value, 1, $replace_count );
			if ( 0 === $replace_count ) {
				return new WP_Error( 'pkca_replace_not_found', 'Ik kan dat woord of tekstdeel niet in het aangewezen veld vinden.', array( 'status' => 422 ) );
			}
		}
		if ( self::stored_values_match( $current_value, $new_value, (string) $target['field']['type'] ) ) {
			return new WP_Error( 'pkca_no_change', 'De voorgestelde inhoud is gelijk aan de huidige preview. Geef een concretere richting of vraag om een andere variant.', array( 'status' => 422 ) );
		}

		$new_change = array(
			'id'           => wp_generate_uuid4(),
			'row_index'    => $target['row_index'],
			'layout_index' => $target['layout_index'],
			'section'      => $target['section'],
			'layout'       => $target['layout'],
			'field_path'   => $target['field']['path'],
			'field_name'   => $target['field']['name'],
			'field_key'    => (string) ( $target['field']['key'] ?? '' ),
			'type'         => $target['field']['type'],
			'old_value'    => $target['field']['value'],
			'new_value'    => $new_value,
			'created_at'   => current_time( 'mysql', true ),
			'base_modified'=> (string) $inspection['post']['modified'],
		);

		foreach ( $changes as $index => $existing ) {
			if ( $existing['row_index'] === $new_change['row_index'] && $existing['layout_index'] === $new_change['layout_index'] && $existing['field_path'] === $new_change['field_path'] ) {
				$new_change['old_value'] = $existing['old_value'];
				$changes[ $index ] = $new_change;
				self::save_changes( $post_id, $changes );
				return $new_change;
			}
		}

		$changes[] = $new_change;
		self::save_changes( $post_id, $changes );
		return $new_change;
	}

	private static function sanitize_nested( array $value ): array {
		foreach ( $value as $key => $item ) $value[ $key ] = is_array( $item ) ? self::sanitize_nested( $item ) : ( is_numeric( $item ) ? $item + 0 : wp_kses_post( (string) $item ) );
		return $value;
	}

	private static function sanitize_link( string $url ): string {
		$url = trim( $url );
		if ( preg_match( '#^https?://#i', $url ) ) {
			$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
			$url_host = wp_parse_url( $url, PHP_URL_HOST );
			if ( $site_host && $url_host && in_array( $url_host, array( $site_host, 'localhost' ), true ) ) {
				$path = wp_parse_url( $url, PHP_URL_PATH ) ?: '/';
				$query = wp_parse_url( $url, PHP_URL_QUERY );
				return $path . ( $query ? '?' . $query : '' );
			}
			return esc_url_raw( $url, array( 'http', 'https' ) );
		}
		return str_starts_with( $url, '/' ) || str_starts_with( $url, '#' ) ? esc_url_raw( $url ) : '';
	}

	private static function find_target( array $inspection, array $change ): array|WP_Error {
		$section_number = absint( $change['section'] ?? 0 );
		$field_path     = isset( $change['field_path'] ) && is_array( $change['field_path'] ) ? array_map( 'strval', $change['field_path'] ) : array();
		$field_name     = sanitize_key( (string) ( $change['field_name'] ?? '' ) );

		foreach ( $inspection['sections'] as $section ) {
			if ( $section_number && $section['number'] !== $section_number ) {
				continue;
			}
			foreach ( $section['fields'] as $field ) {
				if ( $field_path && $field['path'] !== $field_path ) {
					continue;
				}
				if ( ! $field_path && $field_name && $field['name'] !== $field_name ) {
					continue;
				}
				if ( isset( $change['type'] ) && $change['type'] !== $field['type'] ) {
					continue;
				}
				return array(
					'row_index'    => $section['row_index'],
					'layout_index' => $section['layout_index'],
					'section'      => $section['number'],
					'layout'       => $section['layout'],
					'field'        => $field,
				);
			}
		}

		return new WP_Error( 'pkca_target_not_found', 'Ik kan het bedoelde veld niet eenduidig vinden.', array( 'status' => 422 ) );
	}

	public static function apply_change_to_value( array $rows, array $change, bool $format_for_preview = true ): array {
		$row = (int) $change['row_index'];
		$layout = (int) $change['layout_index'];
		if ( ! isset( $rows[ $row ]['flex_content'][ $layout ] ) ) {
			return $rows;
		}
		$replacement = $format_for_preview ? self::format_preview_value( $change ) : $change['new_value'];
		self::set_nested_value( $rows[ $row ]['flex_content'][ $layout ], $change['field_path'], $replacement );
		return $rows;
	}

	private static function format_preview_value( array $change ): mixed {
		if ( 'image' === $change['type'] ) {
			return function_exists( 'acf_get_attachment' ) ? acf_get_attachment( (int) $change['new_value'] ) : (int) $change['new_value'];
		}
		if ( 'gallery' === $change['type'] ) {
			return array_map( static fn( $id ) => function_exists( 'acf_get_attachment' ) ? acf_get_attachment( (int) $id ) : (int) $id, (array) $change['new_value'] );
		}
		return $change['new_value'];
	}

	private static function set_nested_value( array &$value, array $path, mixed $replacement ): void {
		$key = array_shift( $path );
		if ( null === $key || ! array_key_exists( $key, $value ) ) {
			return;
		}
		if ( array() === $path ) {
			$value[ $key ] = $replacement;
			return;
		}
		if ( is_array( $value[ $key ] ) ) {
			self::set_nested_value( $value[ $key ], $path, $replacement );
		}
	}

	public static function publish( int|string $post_id ): array|WP_Error {
		$inspection = self::inspect( $post_id );
		if ( is_wp_error( $inspection ) ) {
			return $inspection;
		}
		$changes = $inspection['changes'];
		if ( array() === $changes ) {
			return new WP_Error( 'pkca_no_changes', 'Er zijn geen wijzigingen om te publiceren.', array( 'status' => 400 ) );
		}

		$target = self::target( $post_id );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		self::$suppress_preview = true;
		$current_rows = get_field( $target['field'], $target['acf_id'] );
		self::$suppress_preview = false;
		$current_modified = self::target_version( $target, is_array( $current_rows ) ? $current_rows : array() );
		$base_modified    = (string) ( $changes[0]['base_modified'] ?? '' );
		if ( $base_modified && $base_modified !== $current_modified ) {
			return new WP_Error(
				'pkca_edit_conflict',
				'De pagina is ondertussen door iemand anders gewijzigd. Verwijder deze preview en maak de wijziging opnieuw.',
				array( 'status' => 409 )
			);
		}

		if ( 'post' === $target['type'] ) {
			wp_save_post_revision( (int) $target['acf_id'] );
		}

		self::$suppress_preview = true;
		$rows = get_field( $target['field'], $target['acf_id'] );
		self::$suppress_preview = false;
		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'pkca_publish_failed', 'De ACF-content kon niet worden gelezen.', array( 'status' => 500 ) );
		}

		$original_rows = $rows;
		foreach ( $changes as $change ) {
			$rows = self::apply_change_to_value( $rows, $change, false );
		}

		update_field( $target['field'], $rows, $target['acf_id'] );
		if ( function_exists( 'acf_flush_value_cache' ) ) {
			acf_flush_value_cache( $target['acf_id'], $target['field'] );
		}
		self::$suppress_preview = true;
		$stored_rows = get_field( $target['field'], $target['acf_id'] );
		self::$suppress_preview = false;

		if ( ! is_array( $stored_rows ) || ! self::changes_are_stored( $stored_rows, $changes ) ) {
			update_field( $target['field'], $original_rows, $target['acf_id'] );
			return new WP_Error( 'pkca_publish_failed', 'De wijzigingen konden niet in ACF worden opgeslagen.', array( 'status' => 500 ) );
		}

		if ( 'post' === $target['type'] ) {
			wp_update_post( array( 'ID' => (int) $target['acf_id'] ) );
		}
		delete_transient( self::transient_key( $post_id ) );
		return array( 'published' => count( $changes ), 'url' => $target['url'] );
	}

	/**
	 * Add the simple equality conditions required to make a changed field active.
	 * ACF uses these controller fields while rendering, so a pending child value
	 * without its matching mode (for example FAQ = manual) would stay invisible.
	 */
	private static function with_required_condition_changes( array $changes, array $sections ): array {
		foreach ( $changes as $change ) {
			$section_index = (int) ( $change['section'] ?? 0 ) - 1;
			$section = $sections[ $section_index ] ?? null;
			if ( ! is_array( $section ) ) {
				continue;
			}
			$target = null;
			foreach ( $section['fields'] as $field ) {
				if ( ( $field['path'] ?? array() ) === ( $change['field_path'] ?? array() ) ) {
					$target = $field;
					break;
				}
			}
			$condition_group = $target['conditional_logic'][0] ?? array();
			foreach ( $condition_group as $condition ) {
				if ( '==' !== ( $condition['operator'] ?? '' ) || ! isset( $condition['field'], $condition['value'] ) ) {
					continue;
				}
				foreach ( $section['fields'] as $controller ) {
					if ( ( $controller['key'] ?? '' ) !== $condition['field'] || false === ( $controller['editable'] ?? true ) ) {
						continue;
					}
					$required_value = $condition['value'];
					$already_queued = false;
					foreach ( $changes as $queued ) {
						if ( ( $queued['section'] ?? 0 ) === ( $change['section'] ?? 0 ) && ( $queued['field_path'] ?? array() ) === ( $controller['path'] ?? array() ) ) {
							$already_queued = true;
							break;
						}
					}
					if ( $already_queued || (string) ( $controller['value'] ?? '' ) === (string) $required_value ) {
						continue;
					}
					$changes[] = array(
						'id'           => 'condition-' . md5( wp_json_encode( array( $change['section'], $controller['path'], $required_value ) ) ),
						'row_index'    => $section['row_index'],
						'layout_index' => $section['layout_index'],
						'section'      => $section['number'],
						'layout'       => $section['layout'],
						'field_path'   => $controller['path'],
						'field_name'   => $controller['name'],
						'field_key'    => $controller['key'] ?? '',
						'type'         => $controller['type'],
						'old_value'    => $controller['value'] ?? '',
						'new_value'    => $required_value,
						'created_at'   => $change['created_at'] ?? current_time( 'mysql', true ),
						'base_modified'=> $change['base_modified'] ?? '',
					);
				}
			}
		}
		return $changes;
	}

	private static function changes_are_stored( array $rows, array $changes ): bool {
		foreach ( $changes as $change ) {
			$row_index    = (int) $change['row_index'];
			$layout_index = (int) $change['layout_index'];
			if ( ! isset( $rows[ $row_index ]['flex_content'][ $layout_index ] ) ) {
				return false;
			}
			$stored = self::get_nested_value( $rows[ $row_index ]['flex_content'][ $layout_index ], $change['field_path'] );
			$stored = self::transport_value( $stored, (string) $change['type'] );
			if ( ! self::stored_values_match( $stored, $change['new_value'], (string) $change['type'] ) ) {
				return false;
			}
		}
		return true;
	}

	private static function stored_values_match( mixed $stored, mixed $expected, string $type ): bool {
		if ( 'text' === $type ) {
			$normalize = static fn( mixed $value ): string => trim( str_replace( array( "\r\n", "\r" ), "\n", (string) $value ) );
			if ( $normalize( $stored ) === $normalize( $expected ) ) {
				return true;
			}
			$markup = static function ( mixed $value ): string {
				$value = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$value = wpautop( trim( str_replace( array( "\r\n", "\r" ), "\n", $value ) ) );
				$value = preg_replace( '/>\s+</u', '><', $value );
				return trim( (string) $value );
			};
			// Preserve semantic WYSIWYG differences such as strong, em and lists.
			// wpautop keeps plain text and ACF's harmless paragraph wrappers equal.
			return $markup( $stored ) === $markup( $expected );
		}
		if ( 'image' === $type ) {
			$stored_id = is_array( $stored ) ? (int) ( $stored['ID'] ?? $stored['id'] ?? 0 ) : (int) $stored;
			return $stored_id === (int) $expected;
		}
		if ( 'gallery' === $type ) {
			$ids = static fn( mixed $items ): array => array_values( array_map( static fn( $item ) => is_array( $item ) ? (int) ( $item['ID'] ?? 0 ) : (int) $item, is_array( $items ) ? $items : array() ) );
			return $ids( $stored ) === $ids( $expected );
		}
		if ( 'repeater' === $type ) {
			return self::normalize_nested_for_compare( $stored ) == self::normalize_nested_for_compare( $expected );
		}
		if ( 'boolean' === $type ) {
			return (bool) $stored === (bool) $expected;
		}
		if ( is_array( $stored ) || is_array( $expected ) ) {
			return self::normalize_nested_for_compare( $stored ) == self::normalize_nested_for_compare( $expected );
		}
		return (string) $stored === (string) $expected;
	}

	private static function normalize_nested_for_compare( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::normalize_nested_for_compare( $item );
			}
			return $value;
		}
		if ( is_string( $value ) ) {
			// ACF formats WYSIWYG subfields with wpautop() when they are read. Compare
			// the canonical rendered form so harmless paragraph wrappers do not make
			// a successfully stored repeater look like a failed publication.
			$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$value = wpautop( trim( str_replace( array( "\r\n", "\r" ), "\n", $value ) ) );
			$value = preg_replace( '/>\s+</u', '><', $value );
			return trim( (string) $value );
		}
		return $value;
	}

	private static function get_nested_value( array $value, array $path ): mixed {
		foreach ( $path as $key ) {
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
				return null;
			}
			$value = $value[ $key ];
		}
		return $value;
	}

	public static function discard( int|string $post_id ): void {
		delete_transient( self::transient_key( $post_id ) );
	}

	public static function discard_change( int|string $post_id, string $change_id ): bool {
		$changes = self::get_changes( $post_id );
		$remaining = array_values(
			array_filter(
				$changes,
				static fn( array $change ): bool => (string) ( $change['id'] ?? '' ) !== $change_id
			)
		);
		if ( count( $remaining ) === count( $changes ) ) {
			return false;
		}
		if ( $remaining ) {
			self::save_changes( $post_id, $remaining );
		} else {
			self::discard( $post_id );
		}
		return true;
	}

	public static function get_changes( int|string $post_id ): array {
		$value = get_transient( self::transient_key( $post_id ) );
		return is_array( $value ) ? $value : array();
	}

	private static function save_changes( int|string $post_id, array $changes ): void {
		set_transient( self::transient_key( $post_id ), $changes, 2 * HOUR_IN_SECONDS );
	}

	private static function transient_key( int|string $post_id ): string {
		$suffix = is_numeric( $post_id ) ? (string) (int) $post_id : md5( (string) $post_id );
		return self::TRANSIENT_PREFIX . get_current_blog_id() . '_' . get_current_user_id() . '_' . $suffix;
	}

	public static function target( int|string $target ): array|WP_Error {
		if ( is_numeric( $target ) && (int) $target > 0 ) {
			$post_id = (int) $target;
			return array( 'type' => 'post', 'key' => (string) $post_id, 'acf_id' => $post_id, 'field' => 'content_repeater', 'title' => get_the_title( $post_id ), 'post_type' => (string) get_post_type( $post_id ), 'url' => get_permalink( $post_id ) );
		}
		if ( preg_match( '/^archive:([a-z0-9_-]+)$/', (string) $target, $matches ) ) {
			$post_type = get_post_type_object( $matches[1] );
			if ( $post_type && $post_type->public && $post_type->has_archive ) {
				return array( 'type' => 'archive', 'key' => 'archive:' . $post_type->name, 'acf_id' => 'option', 'field' => $post_type->name . '_content_repeater', 'title' => $post_type->labels->name, 'post_type' => $post_type->name, 'url' => get_post_type_archive_link( $post_type->name ) ?: home_url( '/' ) );
			}
		}
		return new WP_Error( 'pkca_target_invalid', 'Deze contentbron wordt niet ondersteund.', array( 'status' => 400 ) );
	}

	private static function target_version( array $target, array $rows ): string {
		return 'post' === $target['type'] ? (string) get_post_modified_time( 'c', true, (int) $target['acf_id'] ) : hash( 'sha256', wp_json_encode( $rows ) ?: '' );
	}
}
