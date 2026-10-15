<?php
/** Run with wp eval-file tests/content-regressions.php on a local test site. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) exit;

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) throw new RuntimeException( $message );
	echo "PASS: $message\n";
};
$source_id = (int) ( $args[0] ?? get_option( 'page_on_front' ) );
$source = PKCA_Content::inspect( $source_id );
$assert( ! is_wp_error( $source ) && ! empty( $source['sections'] ), 'Source has ACF content' );
$original_user = get_current_user_id();
$admin_ids = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) $admin_ids[0] );
$id = wp_insert_post( array( 'post_type' => get_post_type( $source_id ), 'post_status' => 'draft', 'post_title' => 'PKCA isolated regression fixture' ) );
try {
	update_field( 'content_repeater', get_field( 'content_repeater', $source_id ), $id );
	$inspection = PKCA_Content::inspect( $id );
	$targets = array();
	foreach ( $inspection['sections'] as $section ) {
		foreach ( $section['fields'] as $field ) {
			if ( 'text' === $field['type'] && $field['editable'] && is_string( $field['value'] ) && '' !== $field['value'] ) {
				$targets[] = array( 'section' => $section['number'], 'field_path' => $field['path'], 'type' => 'text', 'value' => 'PKCA fixture value' );
			}
		}
	}
	$assert( count( $targets ) >= 2, 'Fixture has two editable text fields' );
	$invalid = $targets[1];
	$invalid['field_path'] = array( 'does_not_exist' );
	$result = PKCA_Content::add_changes( $id, array( $targets[0], $invalid ) );
	$assert( is_wp_error( $result ) && ! PKCA_Content::get_changes( $id ), 'Invalid batch leaves no partial preview' );
	$result = PKCA_Content::add_changes( $id, array_slice( $targets, 0, 2 ) );
	$assert( ! is_wp_error( $result ) && 2 === count( PKCA_Content::get_changes( $id ) ), 'Valid batch queues both fields' );
	$published = PKCA_Content::publish( $id );
	$assert( ! is_wp_error( $published ) && 2 === $published['published'], 'Valid batch publishes and verifies ACF values' );
	$assert( ! PKCA_Content::get_changes( $id ), 'Publish clears preview' );
	$replace = $targets[0];
	$replace['operation'] = 'replace';
	$replace['search'] = 'PKCA fixture value';
	$replace['replacement'] = 'Prijs $10';
	$result = PKCA_Content::add_change( $id, $replace );
	$assert( ! is_wp_error( $result ) && 'Prijs $10' === $result['new_value'], 'Literal replacement preserves dollar amounts' );
	PKCA_Content::discard( $id );
	$rows = get_field( 'content_repeater', $id );
	$result = PKCA_Content::add_change( $id, array_merge( $targets[0], array( 'value' => 'Pending first editor' ) ) );
	$assert( ! is_wp_error( $result ), 'Conflict fixture queues initial change' );
	$changed = PKCA_Content::apply_change_to_value( $rows, array_merge( PKCA_Content::get_changes( $id )[0], array( 'new_value' => 'Saved by another editor' ) ), false );
	update_field( 'content_repeater', $changed, $id );
	acf_flush_value_cache( $id, 'content_repeater' );
	$result = PKCA_Content::add_change( $id, array_merge( $targets[0], array( 'value' => 'Refined pending change' ) ) );
	$assert( is_wp_error( $result ) && 'pkca_edit_conflict' === $result->get_error_code(), 'Refining a preview detects changed ACF source' );
	$result = PKCA_Content::publish( $id );
	$assert( is_wp_error( $result ) && 'pkca_edit_conflict' === $result->get_error_code(), 'Publish does not overwrite a newer source' );
	$sanitize = new ReflectionMethod( PKCA_Content::class, 'sanitize_link' );
	$url = home_url( '/contact/?bron=test#formulier' );
	$assert( '/contact/?bron=test#formulier' === $sanitize->invoke( null, $url ), 'Internal links preserve query and anchor' );
	$globals = get_field( 'global_content_repeater', 'option' );
	if ( is_array( $globals ) && $globals ) {
		$global_id = $globals[0]['global_block_id'];
		$describe = new ReflectionMethod( PKCA_Content::class, 'describe_sections' );
		$nested = $describe->invoke( null, array( array( 'number' => 1, 'row_index' => 0, 'layout_index' => 0, 'layout' => 'global_blocks', 'fields' => array(), 'nested_path' => array( 'child', 0 ) ) ), array( array( 'flex_content' => array( array( 'child' => array( array( 'global_block_select' => $global_id ) ) ) ) ) ) );
		$assert( $global_id === ( $nested[0]['shared']['id'] ?? '' ), 'Nested shared block resolves its own source' );
		$assert( str_contains( $nested[0]['shared']['edit_url'], '#pkca-global-' ), 'Shared source has a direct editor link' );
	}
	$classes = new ReflectionMethod( PKCA_Content::class, 'layout_selectors' );
	$assert( in_array( 'hero_home-layout', $classes->invoke( null, 'hero_home' ), true ), 'Layout mapping includes exact class tokens' );
} finally {
	PKCA_Content::discard( $id );
	wp_delete_post( $id, true );
	wp_set_current_user( $original_user );
}
