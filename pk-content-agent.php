<?php
/**
 * Plugin Name: PK Content Agent
 * Plugin URI: https://github.com/Pageking/pk-content-agent
 * Description: Bewerk ACF Flexible Content via een veilige chat-preview op de frontend.
 * Version: 0.24.3
 * Author: Pageking
 * Author URI: https://pageking.nl
 * License: GPL-2.0-or-later
 * Update URI: https://github.com/Pageking/pk-content-agent
 * Requires PHP: 8.0
 * Text Domain: pk-content-agent
 */

defined( 'ABSPATH' ) || exit;

define( 'PKCA_VERSION', '0.24.3' );
define( 'PKCA_FILE', __FILE__ );
define( 'PKCA_DIR', plugin_dir_path( __FILE__ ) );

require_once PKCA_DIR . 'plugin-update-checker/plugin-update-checker.php';

$pkca_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/Pageking/pk-content-agent/',
	__FILE__,
	'pk-content-agent'
);

require_once PKCA_DIR . 'src/class-pkca-plugin.php';

PKCA_Plugin::instance()->boot();
