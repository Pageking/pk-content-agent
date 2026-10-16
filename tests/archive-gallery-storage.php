<?php
/** Local integration test with real ACF archive/options rendering; temporary schema and images are removed. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) exit;
$assert = static function( bool $ok, string $message ): void {
 if ( ! $ok ) throw new RuntimeException( $message );
 echo "PASS: $message\n";
};
$user = get_current_user_id();
wp_set_current_user( (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );
$type = 'pkca_gallery_test'; $root = $type . '_content_repeater'; $target = 'archive:' . $type;
$ids = array(); $query = $GLOBALS['wp_query'];
register_post_type( $type, array( 'public' => true, 'has_archive' => true, 'label' => 'PKCA gallery fixture' ) );
acf_add_local_field_group( array( 'key' => 'group_pkca_gallery_test', 'title' => 'PKCA gallery fixture', 'fields' => array(
 array( 'key' => 'field_pkca_gallery_root', 'name' => $root, 'type' => 'repeater', 'sub_fields' => array(
  array( 'key' => 'field_pkca_gallery_flex', 'name' => 'flex_content', 'type' => 'flexible_content', 'layouts' => array(
   array( 'key' => 'layout_pkca_gallery', 'name' => 'swiper_images', 'label' => 'Gallery', 'sub_fields' => array(
    array( 'key' => 'field_pkca_gallery_images', 'name' => 'images', 'label' => 'Images', 'type' => 'gallery', 'return_format' => 'array' ),
   ) ),
  ) ),
 ) ),
) ) );
try {
 for ( $i = 0; $i < 4; $i++ ) {
  $id = wp_insert_attachment( array( 'post_title' => 'PKCA gallery fixture ' . $i, 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ) );
  $ids[] = $id; update_post_meta( $id, '_wp_attached_file', 'pkca-gallery-test-' . $i . '.jpg' );
 }
 $base = array_slice( $ids, 0, 3 );
 update_field( 'field_pkca_gallery_root', array( array( 'flex_content' => array( array( 'acf_fc_layout' => 'swiper_images', 'images' => $base ) ) ) ), 'option' );
 $GLOBALS['wp_query'] = new WP_Query();
 $GLOBALS['wp_query']->is_post_type_archive = true;
 $GLOBALS['wp_query']->queried_object = get_post_type_object( $type );
 $GLOBALS['wp_query']->query_vars['post_type'] = $type;
 $render = static function() use ( $root ): array {
  acf_get_store( 'values' )->reset();
  $rendered = array();
  while ( have_rows( $root, 'option' ) ) {
   the_row();
   while ( have_rows( 'flex_content' ) ) { the_row(); $rendered = array_column( get_sub_field( 'images' ) ?: array(), 'ID' ); }
  }
  return $rendered;
 };
 $cases = array(
  'reorder only' => array( $ids[1], $ids[0], $ids[2] ),
  'add an image' => array( $ids[1], $ids[3], $ids[0], $ids[2] ),
  'remove images' => array( $ids[3], $ids[0] ),
  'one image' => array( $ids[0] ),
  'clear gallery' => array(),
  'restore gallery' => $base,
 );
 foreach ( $cases as $label => $next ) {
  $result = PKCA_Content::add_changes( $target, array( array( 'section' => 1, 'field_path' => array( 'images' ), 'type' => 'gallery', 'value' => $next ) ) );
  $assert( ! is_wp_error( $result ) && 1 === $result['queued'], $label . ': queues a gallery-only edit' );
  $assert( $render() === $next, $label . ': ACF archive template renders the exact pending order' );
  $result = PKCA_Content::publish( $target );
  $assert( ! is_wp_error( $result ) && 1 === $result['published'], $label . ': publishes without another field edit' );
  $assert( $render() === $next, $label . ': stored ACF order matches the preview' );
  $assert( ! PKCA_Content::get_changes( $target ), $label . ': clears pending changes after publish' );
 }
} finally {
 PKCA_Content::discard( $target );
 delete_field( 'field_pkca_gallery_root', 'option' );
 foreach ( $ids as $id ) wp_delete_attachment( $id, true );
 $GLOBALS['wp_query'] = $query;
 acf_remove_local_field_group( 'group_pkca_gallery_test' );
 unregister_post_type( $type );
 wp_set_current_user( $user );
}
