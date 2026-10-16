<?php
/** Local-only regression: isolated per-user drafts survive cache eviction and migrate old previews. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) exit;
$assert = static function( bool $ok, string $message ): void {
 if ( ! $ok ) throw new RuntimeException( $message );
 echo "PASS: $message\n";
};
$original_user = get_current_user_id();
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$user_id = (int) $admins[0];
wp_set_current_user( $user_id );
$target = 'archive:pkca-storage-' . wp_generate_uuid4();
$other_target = $target . '-other';
$key_method = new ReflectionMethod( PKCA_Content::class, 'transient_key' );
$save = new ReflectionMethod( PKCA_Content::class, 'save_changes' );
$key = $key_method->invoke( null, $target );
$first = array( 'id' => 'gallery-test', 'type' => 'gallery', 'old_value' => array( 1, 2 ), 'new_value' => array( 2, 1 ) );
$second = array( 'id' => 'title-test', 'type' => 'text', 'old_value' => 'Before', 'new_value' => 'After' );
$other_user = null;
$reject = static fn() => false;
try {
 set_transient( $key, array( $first, $second ), 120 );
 $assert( PKCA_Content::get_changes( $target ) === array( $first, $second ), 'Existing gallery preview remains available during migration' );
 $assert( get_user_meta( $user_id, '_' . $key, true ) === array( $first, $second ), 'Legacy preview is stored persistently for its owner' );
 $assert( false === get_transient( $key ), 'Migration removes the redundant transient' );
 wp_cache_delete( $user_id, 'user_meta' );
 $assert( PKCA_Content::get_changes( $target ) === array( $first, $second ), 'Gallery preview survives eviction from object cache' );
 $assert( PKCA_Content::get_changes( $other_target ) === array(), 'Another page or archive has an independent draft' );
 $other_user = wp_create_user( 'pkca-storage-' . wp_generate_uuid4(), wp_generate_password() );
 if ( is_wp_error( $other_user ) ) throw new RuntimeException( $other_user->get_error_message() );
 wp_set_current_user( $other_user );
 $assert( PKCA_Content::get_changes( $target ) === array(), 'Other users cannot see the owner draft' );
 $assert( $save->invoke( null, $target, array( $second ) ), 'Other user can save an independent draft' );
 wp_set_current_user( $user_id );
 $assert( PKCA_Content::get_changes( $target ) === array( $first, $second ), 'Other user edits leave the original draft intact' );
 $assert( PKCA_Content::discard_change( $target, $first['id'] ), 'One pending gallery edit can be removed' );
 $assert( PKCA_Content::get_changes( $target ) === array( $second ), 'Removing a gallery edit preserves other pending changes' );
 $assert( $save->invoke( null, $target, array( $second ) ), 'Saving an identical draft succeeds' );
 add_filter( 'update_user_metadata', $reject );
 $assert( ! $save->invoke( null, $other_target, array( $first ) ), 'A failed durable write is reported instead of confirming success' );
 remove_filter( 'update_user_metadata', $reject );
 PKCA_Content::discard( $target );
 wp_cache_delete( $user_id, 'user_meta' );
 $assert( PKCA_Content::get_changes( $target ) === array(), 'Discard removes durable storage and does not resurrect a legacy preview' );
 wp_set_current_user( $other_user );
 $assert( PKCA_Content::get_changes( $target ) === array( $second ), 'Discarding one owner draft leaves the other user draft intact' );
} finally {
 remove_filter( 'update_user_metadata', $reject );
 foreach ( array_filter( array( $user_id, is_int( $other_user ) ? $other_user : null ) ) as $owner ) {
  wp_set_current_user( $owner );
  PKCA_Content::discard( $target ); PKCA_Content::discard( $other_target );
 }
 if ( is_int( $other_user ) ) {
  require_once ABSPATH . 'wp-admin/includes/user.php';
  wp_delete_user( $other_user );
 }
 wp_set_current_user( $original_user );
}
