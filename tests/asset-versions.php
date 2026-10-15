<?php
/** Read-only regression for themes that strip asset query strings. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) exit;
$strip = static fn( $src ) => strtok( $src, '?' );
foreach ( array( 'script_loader_src', 'style_loader_src' ) as $filter ) add_filter( $filter, $strip, 9999 );
try {
 foreach ( array( 'script_loader_src', 'style_loader_src' ) as $filter ) {
  $src=apply_filters($filter,'https://example.test/agent.js?ver=123','pk-content-agent');
  parse_str((string)parse_url($src,PHP_URL_QUERY),$query);
  if(($query['pkca_build']??'')!==PKCA_VERSION)throw new RuntimeException('Build marker stripped');
  if(str_contains(apply_filters($filter,'https://example.test/other.js?ver=123','other-plugin'),'pkca_build'))throw new RuntimeException('Unrelated plugin changed');
  echo "PASS: $filter preserves this plugin's version after theme stripping, without affecting other assets\n";
 }
} finally { foreach(array('script_loader_src','style_loader_src') as $filter)remove_filter($filter,$strip,9999); }
