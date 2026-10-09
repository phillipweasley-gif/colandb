<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tools → Claude Connector: the server address, how to connect Claude, which
 * users have the Claude Agent role, and the recent tool calls.
 */
class COLANDB_MCP_Admin {

	const PAGE = 'colandb-mcp';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
	}

	public static function menu() {
		add_management_page(
			__( 'Claude Connector', 'colandb-mcp' ),
			__( 'Claude Connector', 'colandb-mcp' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$agents  = get_users( array( 'role' => COLANDB_MCP_Role::ROLE ) );
		$entries = array_reverse( array_slice( COLANDB_MCP_Log::entries(), -50 ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Claude Connector', 'colandb-mcp' ); ?></h1>
			<p><?php esc_html_e( 'Claude can read this site\'s published events, events awaiting review and plugin versions through the address below. It cannot publish, change or delete anything, or read member data.', 'colandb-mcp' ); ?></p>

			<h2><?php esc_html_e( 'Server address', 'colandb-mcp' ); ?></h2>
			<p><code><?php echo esc_html( COLANDB_MCP_Server::url() ); ?></code></p>

			<h2><?php esc_html_e( 'Connect Claude', 'colandb-mcp' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'Users → Add New: username "claude-agent", any email address you control, role "Claude Agent". Let WordPress generate the password; it is never needed.', 'colandb-mcp' ); ?></li>
				<li><?php esc_html_e( 'Open that user, and under Application Passwords add one named "Claude". Copy it; WordPress shows it only once.', 'colandb-mcp' ); ?></li>
				<li><?php esc_html_e( 'In the Claude Code environment\'s settings, add a secret holding the base64 of "claude-agent:<the application password>" (COLANDB_STG_AUTH on staging). Never paste it into chat or into the repository.', 'colandb-mcp' ); ?></li>
				<li><?php esc_html_e( 'To cut Claude off at once, revoke that application password here. Nothing else changes.', 'colandb-mcp' ); ?></li>
			</ol>

			<h2><?php esc_html_e( 'Claude Agent users', 'colandb-mcp' ); ?></h2>
			<?php if ( ! $agents ) : ?>
				<p><?php esc_html_e( 'None yet.', 'colandb-mcp' ); ?></p>
			<?php else : ?>
				<ul>
					<?php foreach ( $agents as $agent ) : ?>
						<?php $passwords = class_exists( 'WP_Application_Passwords' ) ? WP_Application_Passwords::get_user_application_passwords( $agent->ID ) : array(); ?>
						<li>
							<a href="<?php echo esc_url( get_edit_user_link( $agent->ID ) . '#application-passwords-section' ); ?>"><?php echo esc_html( $agent->user_login ); ?></a>
							<?php
							/* translators: %d: number of application passwords */
							echo esc_html( sprintf( _n( '(%d application password)', '(%d application passwords)', count( $passwords ), 'colandb-mcp' ), count( $passwords ) ) );
							?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Recent tool calls', 'colandb-mcp' ); ?></h2>
			<?php if ( ! $entries ) : ?>
				<p><?php esc_html_e( 'None yet.', 'colandb-mcp' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'When', 'colandb-mcp' ); ?></th>
							<th><?php esc_html_e( 'User', 'colandb-mcp' ); ?></th>
							<th><?php esc_html_e( 'Tool', 'colandb-mcp' ); ?></th>
							<th><?php esc_html_e( 'Input', 'colandb-mcp' ); ?></th>
							<th><?php esc_html_e( 'Result', 'colandb-mcp' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $entries as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', $entry['time'] ) ); ?></td>
								<td><?php echo esc_html( $entry['user'] ); ?></td>
								<td><code><?php echo esc_html( $entry['tool'] ); ?></code></td>
								<td><code><?php echo esc_html( $entry['input'] ); ?></code></td>
								<td><?php echo esc_html( $entry['outcome'] . ' (' . $entry['ms'] . ' ms)' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
