<?php
/** Read-only regression test: preserve formatted numeric content during transport and sanitization. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) exit;
$assert = static function( bool $ok, string $message ): void {
 if ( ! $ok ) throw new RuntimeException( $message );
 echo "PASS: $message\n";
};
$sanitize = new ReflectionMethod( PKCA_Content::class, 'sanitize_nested' );
$transport = new ReflectionMethod( PKCA_Content::class, 'transport_value' );
$before = array(
 array( 'number' => '10.000', 'suffix' => '+' ),
 array( 'number' => '1.700', 'suffix' => '+' ),
 array( 'number' => '9', 'suffix' => 'jaar' ),
 array( 'number' => '60', 'suffix' => '%' ),
);
$next = $before;
$next[0]['number'] = '10001';
$clean = $sanitize->invoke( null, $next );
$assert( $clean === $next, 'Editing 10.000 to 10001 preserves all other rows exactly, including 1.700' );
foreach ( array( '10.000', '1.700', '1,7', '9.00', '00123', '+310201234567', '1.234,56' ) as $value ) {
 $assert( $sanitize->invoke( null, array( 'nested' => array( 'value' => $value ) ) )['nested']['value'] === $value, 'Nested numeric string remains exact: ' . $value );
}
foreach ( array( '10.000', '1.700', '9.00', '0', '10001', 9, 1.7 ) as $value ) {
 $assert( $transport->invoke( null, $value, 'number' ) === $value, 'Standalone number transport preserves value and type: ' . $value );
}
$assert( $sanitize->invoke( null, array( 'value' => '<script>alert(1)</script><strong>10.000</strong>' ) )['value'] === 'alert(1)<strong>10.000</strong>', 'Preserving numeric formatting retains HTML sanitization' );
$assert( $sanitize->invoke( null, array( 9, 1.7, true, null ) ) === array( 9, 1.7, true, null ), 'Native scalar values retain their original types' );
