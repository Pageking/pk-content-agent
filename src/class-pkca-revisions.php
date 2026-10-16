<?php

defined( 'ABSPATH' ) || exit;

/** Content snapshots stored as ordinary WordPress revisions, including ACF metadata. */
final class PKCA_Revisions {
 public static function snapshot( array $target ): int|WP_Error {
  $post = 'post' === $target['type'] ? get_post( (int) $target['acf_id'] ) : null;
  if ( ! $post || ! wp_revisions_enabled( $post ) ) return 0;
  // wp_save_post_revision() may skip an ACF-only change as title/content match.
  $source_rows = PKCA_Content::saved_rows( $target );
  $id = _wp_put_post_revision( $post, false );
  if ( is_wp_error( $id ) || ! $id ) return new WP_Error( 'pkca_revision_failed', 'De vorige versie kon niet veilig worden bewaard. Probeer het opnieuw.', array( 'status' => 500 ) );
  acf_copy_postmeta( $post->ID, $id );
  $revision_rows = PKCA_Content::saved_rows( array_merge( $target, array( 'acf_id' => $id ) ) );
  if ( wp_json_encode( $revision_rows ) !== wp_json_encode( $source_rows ) ) {
   wp_delete_post_revision( $id );
   return new WP_Error( 'pkca_revision_failed', 'De vorige inhoud kon niet veilig worden bewaard.', array( 'status' => 500 ) );
  }
  return (int) $id;
 }

 public static function listing( int|string $post_id ): array|WP_Error {
  $target = PKCA_Content::target( $post_id );
  if ( is_wp_error( $target ) ) return $target;
  $post = 'post' === $target['type'] ? get_post( (int) $target['acf_id'] ) : null;
  if ( ! $post || ! wp_revisions_enabled( $post ) ) return array( 'available' => false, 'revisions' => array(), 'message' => 'WordPress bewaart voor deze contentbron geen revisies.' );
  $items = array();
  foreach ( wp_get_post_revisions( $post->ID, array( 'posts_per_page' => 30, 'orderby' => 'ID', 'order' => 'DESC', 'check_enabled' => false ) ) as $revision ) {
   if ( wp_is_post_autosave( $revision->ID ) || ! metadata_exists( 'post', $revision->ID, $target['field'] ) ) continue;
   $author = get_userdata( $revision->post_author );
   $items[] = array( 'id' => $revision->ID, 'date' => get_post_time( 'c', false, $revision ), 'author' => $author ? $author->display_name : 'Onbekend' );
  }
  return array( 'available' => true, 'revisions' => $items );
 }

 public static function comparison( int|string $post_id, int $revision_id ): array|WP_Error {
  $target = PKCA_Content::target( $post_id );
  if ( is_wp_error( $target ) ) return $target;
  $revision = get_post( $revision_id );
  if ( 'post' !== $target['type'] || ! $revision || 'revision' !== $revision->post_type || (int) $revision->post_parent !== (int) $target['acf_id'] || wp_is_post_autosave( $revision_id ) || ! metadata_exists( 'post', $revision_id, $target['field'] ) ) {
   return new WP_Error( 'pkca_revision_invalid', 'Deze versie hoort niet bij deze pagina of bevat geen herstelbare inhoud.', array( 'status' => 422 ) );
  }
  $context = PKCA_Content::inspect( $post_id );
  if ( is_wp_error( $context ) ) return $context;
  if ( $context['changes'] ) return new WP_Error( 'pkca_revision_pending', 'Zet je wijzigingen eerst online of maak ze ongedaan voordat je een vorige versie terugzet.', array( 'status' => 409 ) );
  $before = PKCA_Content::saved_rows( $target );
  $after = PKCA_Content::saved_rows( array_merge( $target, array( 'acf_id' => $revision_id ) ) );
  if ( ! is_array( $before ) || ! is_array( $after ) ) return new WP_Error( 'pkca_revision_empty', 'Deze versie bevat geen herstelbare pagina-inhoud.', array( 'status' => 422 ) );
  return array( 'base_version' => $context['post']['modified'], 'changes' => PKCA_Content::revision_comparison( $post_id, $before, $after ) );
 }

 public static function restore( int|string $post_id, int $revision_id, string $base_version ): array|WP_Error {
  $target = PKCA_Content::target( $post_id );
  if ( is_wp_error( $target ) ) return $target;
  $revision = get_post( $revision_id );
  if ( 'post' !== $target['type'] || ! $revision || 'revision' !== $revision->post_type || (int) $revision->post_parent !== (int) $target['acf_id'] || wp_is_post_autosave( $revision_id ) || ! metadata_exists( 'post', $revision_id, $target['field'] ) ) {
   return new WP_Error( 'pkca_revision_invalid', 'Deze versie hoort niet bij deze pagina of bevat geen herstelbare inhoud.', array( 'status' => 422 ) );
  }
  $context = PKCA_Content::inspect( $post_id );
  if ( is_wp_error( $context ) ) return $context;
  if ( $context['changes'] ) return new WP_Error( 'pkca_revision_pending', 'Zet je wijzigingen eerst online of maak ze ongedaan voordat je een vorige versie terugzet.', array( 'status' => 409 ) );
  if ( ! $base_version || ! hash_equals( $context['post']['modified'], $base_version ) ) return new WP_Error( 'pkca_edit_conflict', 'De pagina is ondertussen gewijzigd. Open de vorige versies opnieuw.', array( 'status' => 409 ) );
  $revision_target = array_merge( $target, array( 'acf_id' => $revision_id ) );
  $rows = PKCA_Content::saved_rows( $revision_target );
  if ( ! is_array( $rows ) ) return new WP_Error( 'pkca_revision_empty', 'Deze versie bevat geen herstelbare pagina-inhoud.', array( 'status' => 422 ) );
  $before = PKCA_Content::saved_rows( $target );
  $backup = self::snapshot( $target );
  if ( is_wp_error( $backup ) ) return $backup;
  if ( ! $backup ) return new WP_Error( 'pkca_revisions_disabled', 'Revisies zijn uitgeschakeld voor deze pagina.', array( 'status' => 422 ) );
  // Restore the content managed by this tool. Title, SEO and other settings
  // remain outside this operation; the original WP revision remains intact.
  update_field( $target['field'], $rows, $target['acf_id'] );
  $restored = PKCA_Content::saved_rows( $target );
  if ( wp_json_encode( $restored ) !== wp_json_encode( $rows ) ) {
   update_field( $target['field'], $before, $target['acf_id'] );
   return new WP_Error( 'pkca_revision_restore_failed', 'Terugzetten is niet gelukt. Je huidige inhoud is behouden.', array( 'status' => 500 ) );
  }
  wp_update_post( array( 'ID' => (int) $target['acf_id'] ) );
  return array( 'restored' => $revision_id, 'backup_revision' => $backup, 'url' => $target['url'] );
 }
}
