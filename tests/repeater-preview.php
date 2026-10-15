<?php
/** Run with wp eval-file tests/repeater-preview.php [source-page-id]. Uses an isolated draft. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) exit;
$assert = static function ( bool $ok, string $message ): void {
 if ( ! $ok ) throw new RuntimeException( $message );
 echo "PASS: $message\n";
};
$original_user = get_current_user_id();
wp_set_current_user( (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );
$source_id = (int) ( $args[0] ?? get_option( 'page_on_front' ) );
$id = wp_insert_post( array( 'post_title' => 'PKCA isolated repeater preview fixture', 'post_status' => 'draft', 'post_type' => get_post_type( $source_id ) ) );
try {
 update_field( 'content_repeater', get_field( 'content_repeater', $source_id ), $id );
 $context = PKCA_Content::inspect( $id );
 $chosen = null;
 foreach ( $context['sections'] as $section ) {
  foreach ( $section['fields'] as $field ) {
   if ( 'repeater' === $field['type'] && count( $field['path'] ) === 1 && isset( $field['value'][0]['title'] ) && is_string( $field['value'][0]['title'] ) ) {
    $chosen = array( $section, $field ); break 2;
   }
  }
 }
 $assert( null !== $chosen, 'Fixture contains a repeater with a title' );
 [$section, $field] = $chosen;
 $original_title = $field['value'][0]['title'];
 $next = $field['value'];
 $next[0]['title'] = 'PKCA preview title';
 $result = PKCA_Content::add_change( $id, array( 'section' => $section['number'], 'field_path' => $field['path'], 'type' => 'repeater', 'value' => $next ) );
 $assert( ! is_wp_error( $result ), 'Repeater change is queued' );
 $store = acf_get_store( 'values' );
 $store->reset();
 $store->set( 'pkca-unrelated', 'retained' );
 PKCA_Content::inspect( $id );
 $assert( 'retained' === $store->get( 'pkca-unrelated' ), 'Inspection preserves existing ACF cache entries' );
 $rendered = null;
 while ( have_rows( 'content_repeater', $id ) ) {
  the_row();
  while ( have_rows( 'flex_content' ) ) {
   the_row();
   if ( get_row_index() - 1 === $section['layout_index'] && get_row_layout() === $section['layout'] ) $rendered = get_sub_field( $field['path'][0] );
  }
 }
 $assert( 'PKCA preview title' === ( $rendered[0]['title'] ?? null ), 'Nested get_sub_field renders the pending repeater after inspection' );
 $again = PKCA_Content::inspect( $id );
 $assert( $original_title === $again['changes'][0]['old_value'][0]['title'], 'Warm preview cache does not become the saved source' );
 $assert( 'PKCA preview title' === $again['changes'][0]['new_value'][0]['title'], 'Repeated inspection retains the pending edit' );
 $published = PKCA_Content::publish( $id );
 $assert( ! is_wp_error( $published ) && 1 === $published['published'], 'Repeater publishes and verifies successfully after a warm preview' );
 $saved = PKCA_Content::inspect( $id );
 $assert( empty( $saved['changes'] ), 'Publish clears the pending change' );
} finally {
 PKCA_Content::discard( $id );
 wp_delete_post( $id, true );
 wp_set_current_user( $original_user );
}
