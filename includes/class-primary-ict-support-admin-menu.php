<?php
/**
 * Shared Primary ICT Support admin menu.
 *
 * Copy this file into other Primary ICT Support plugins, include it, and call
 * Primary_ICT_Support_Admin_Menu::register_plugin() from admin_menu.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Primary_ICT_Support_Admin_Menu' ) ) {
	class Primary_ICT_Support_Admin_Menu {

		const MENU_SLUG = 'primary-ict-support';

		/**
		 * Registered Primary ICT Support plugins for the dashboard.
		 *
		 * @var array
		 */
		private static $plugins = array();

		/**
		 * Shared branding assets.
		 *
		 * @var array
		 */
		private static $assets = array(
			'icon_url' => '',
			'logo_url' => '',
		);

		/**
		 * Tracks whether the admin CSS has already been hooked.
		 *
		 * @var bool
		 */
		private static $admin_css_hooked = false;

		/**
		 * Registers the shared top-level menu and one plugin submenu.
		 *
		 * @param array $args Menu arguments.
		 * @return void
		 */
		public static function register_plugin( $args ) {
			$args = wp_parse_args(
				$args,
				array(
					'page_title'  => '',
					'menu_title'  => '',
					'description' => '',
					'slug'        => '',
					'callback'    => '',
					'capability'  => 'manage_options',
					'icon_url'    => '',
					'logo_url'    => '',
					'position'    => 58,
				)
			);

			if ( empty( $args['slug'] ) || empty( $args['callback'] ) ) {
				return;
			}

			self::remember_assets( $args );
			self::remember_plugin( $args );
			self::hook_admin_css();

			if ( ! self::menu_exists() ) {
				add_menu_page(
					__( 'Primary ICT Support', 'primary-ict-plugin-suite' ),
					__( 'Primary ICT Support', 'primary-ict-plugin-suite' ),
					$args['capability'],
					self::MENU_SLUG,
					array( __CLASS__, 'render_dashboard' ),
					self::$assets['icon_url'],
					$args['position']
				);

				add_submenu_page(
					self::MENU_SLUG,
					__( 'Primary ICT Support Dashboard', 'primary-ict-plugin-suite' ),
					__( 'Dashboard', 'primary-ict-plugin-suite' ),
					$args['capability'],
					self::MENU_SLUG,
					array( __CLASS__, 'render_dashboard' )
				);
			}

			add_submenu_page(
				self::MENU_SLUG,
				$args['page_title'],
				$args['menu_title'],
				$args['capability'],
				$args['slug'],
				$args['callback']
			);
		}

		/**
		 * Renders the shared Primary ICT Support dashboard.
		 *
		 * @return void
		 */
		public static function render_dashboard() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			echo '<div class="wrap picts-dashboard">';
			echo '<div class="picts-dashboard__hero">';
			echo '<div>';
			if ( ! empty( self::$assets['logo_url'] ) ) {
				echo '<img class="picts-dashboard__logo" src="' . esc_url( self::$assets['logo_url'] ) . '" alt="' . esc_attr__( 'Primary ICT Support', 'primary-ict-plugin-suite' ) . '">';
			} else {
				echo '<h1>' . esc_html__( 'Primary ICT Support', 'primary-ict-plugin-suite' ) . '</h1>';
			}
			echo '<p class="picts-dashboard__intro">' . esc_html__( 'Welcome, and thank you for using a Primary ICT Support plugin. This area brings together our tools, support links, and future updates in one place.', 'primary-ict-plugin-suite' ) . '</p>';
			echo '</div>';
			echo '<div class="picts-dashboard__actions">';
			echo '<a class="button button-primary" href="https://primaryictsupport.co.uk/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Visit Website', 'primary-ict-plugin-suite' ) . '</a>';
			echo '<a class="button" href="https://portal.primaryictsupport.co.uk/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Support Portal', 'primary-ict-plugin-suite' ) . '</a>';
			echo '</div>';
			echo '</div>';

			echo '<div class="picts-dashboard__grid">';
			echo '<section class="picts-dashboard__panel">';
			echo '<h2>' . esc_html__( 'Plugins On This Website', 'primary-ict-plugin-suite' ) . '</h2>';
			self::render_plugin_list();
			echo '</section>';

			echo '<section class="picts-dashboard__panel picts-dashboard__panel--updates">';
			echo '<h2>' . esc_html__( 'Updates', 'primary-ict-plugin-suite' ) . '</h2>';
			echo '<p>' . esc_html__( 'Company news, plugin updates, and helpful notices will appear here in a future release.', 'primary-ict-plugin-suite' ) . '</p>';
			echo '<div class="picts-dashboard__notice">' . esc_html__( 'Coming soon', 'primary-ict-plugin-suite' ) . '</div>';
			echo '</section>';
			echo '</div>';
			echo '</div>';
		}

		/**
		 * Renders the current Primary ICT Support plugin list.
		 *
		 * @return void
		 */
		private static function render_plugin_list() {
			if ( empty( self::$plugins ) ) {
				echo '<p>' . esc_html__( 'No Primary ICT Support plugins have registered yet.', 'primary-ict-plugin-suite' ) . '</p>';
				return;
			}

			echo '<div class="picts-plugin-list">';
			foreach ( self::$plugins as $plugin ) {
				echo '<a class="picts-plugin-card" href="' . esc_url( admin_url( 'admin.php?page=' . $plugin['slug'] ) ) . '">';
				echo '<span class="picts-plugin-card__title">' . esc_html( $plugin['menu_title'] ) . '</span>';
				if ( ! empty( $plugin['description'] ) ) {
					echo '<span class="picts-plugin-card__description">' . esc_html( $plugin['description'] ) . '</span>';
				}
				echo '</a>';
			}
			echo '</div>';
		}

		/**
		 * Remembers dashboard assets supplied by the first available plugin.
		 *
		 * @param array $args Menu arguments.
		 * @return void
		 */
		private static function remember_assets( $args ) {
			if ( empty( self::$assets['icon_url'] ) && ! empty( $args['icon_url'] ) ) {
				self::$assets['icon_url'] = $args['icon_url'];
			}

			if ( empty( self::$assets['logo_url'] ) && ! empty( $args['logo_url'] ) ) {
				self::$assets['logo_url'] = $args['logo_url'];
			}
		}

		/**
		 * Remembers a plugin for the dashboard list.
		 *
		 * @param array $args Menu arguments.
		 * @return void
		 */
		private static function remember_plugin( $args ) {
			self::$plugins[ $args['slug'] ] = array(
				'menu_title'  => $args['menu_title'],
				'description' => $args['description'],
				'slug'        => $args['slug'],
			);
		}

		/**
		 * Hooks the shared admin styling once per request.
		 *
		 * @return void
		 */
		private static function hook_admin_css() {
			if ( self::$admin_css_hooked ) {
				return;
			}

			add_action( 'admin_head', array( __CLASS__, 'render_admin_css' ) );
			self::$admin_css_hooked = true;
		}

		/**
		 * Renders shared admin styling for the menu icon and dashboard.
		 *
		 * @return void
		 */
		public static function render_admin_css() {
			?>
			<style>
				#adminmenu .toplevel_page_primary-ict-support .wp-menu-image img {
					box-sizing: border-box;
					height: 20px;
					object-fit: contain;
					padding: 2px 0 0;
					width: 20px;
				}

				.picts-dashboard {
					--picts-navy: #193255;
					--picts-red: #e10713;
					--picts-teal: #00809b;
					--picts-border: #d9e2ec;
					--picts-soft: #f5f8fb;
					color: var(--picts-navy);
					max-width: 1120px;
				}

				.picts-dashboard__hero {
					align-items: center;
					background: #fff;
					border-left: 6px solid var(--picts-teal);
					box-shadow: 0 10px 28px rgba(25, 50, 85, 0.08);
					display: flex;
					gap: 28px;
					justify-content: space-between;
					margin: 24px 0;
					padding: 28px;
				}

				.picts-dashboard__logo {
					display: block;
					height: auto;
					max-width: 360px;
					width: 100%;
				}

				.picts-dashboard__intro {
					font-size: 16px;
					line-height: 1.6;
					margin: 18px 0 0;
					max-width: 760px;
				}

				.picts-dashboard__actions {
					display: flex;
					flex: 0 0 auto;
					flex-wrap: wrap;
					gap: 10px;
				}

				.picts-dashboard__actions .button-primary {
					background: var(--picts-teal);
					border-color: var(--picts-teal);
				}

				.picts-dashboard__grid {
					display: grid;
					gap: 20px;
					grid-template-columns: minmax(0, 1.35fr) minmax(280px, 0.65fr);
				}

				.picts-dashboard__panel {
					background: #fff;
					border-top: 4px solid var(--picts-navy);
					box-shadow: 0 8px 22px rgba(25, 50, 85, 0.06);
					padding: 22px;
				}

				.picts-dashboard__panel h2 {
					color: var(--picts-navy);
					font-size: 18px;
					margin: 0 0 16px;
				}

				.picts-dashboard__panel--updates {
					border-top-color: var(--picts-red);
				}

				.picts-plugin-list {
					display: grid;
					gap: 12px;
				}

				.picts-plugin-card {
					background: var(--picts-soft);
					border: 1px solid var(--picts-border);
					border-left: 4px solid var(--picts-teal);
					color: var(--picts-navy);
					display: block;
					padding: 16px;
					text-decoration: none;
					transition: border-color 160ms ease, box-shadow 160ms ease, transform 160ms ease;
				}

				.picts-plugin-card:hover,
				.picts-plugin-card:focus {
					border-left-color: var(--picts-red);
					box-shadow: 0 8px 18px rgba(25, 50, 85, 0.12);
					color: var(--picts-navy);
					transform: translateY(-1px);
				}

				.picts-plugin-card__title {
					display: block;
					font-size: 15px;
					font-weight: 700;
				}

				.picts-plugin-card__description {
					color: #526579;
					display: block;
					line-height: 1.5;
					margin-top: 6px;
				}

				.picts-dashboard__notice {
					background: rgba(0, 128, 155, 0.1);
					border: 1px solid rgba(0, 128, 155, 0.28);
					color: var(--picts-teal);
					display: inline-block;
					font-weight: 700;
					margin-top: 8px;
					padding: 8px 12px;
				}

				.picts-plugin-page {
					max-width: 1180px;
				}

				.picts-plugin-page__hero {
					align-items: center;
					background: #fff;
					border-left: 6px solid var(--picts-teal);
					box-shadow: 0 10px 28px rgba(25, 50, 85, 0.08);
					display: flex;
					gap: 24px;
					justify-content: space-between;
					margin: 24px 0 0;
					padding: 24px 28px;
				}

				.picts-plugin-page__hero h1 {
					color: var(--picts-navy);
					font-size: 26px;
					font-weight: 700;
					margin: 0;
					padding: 0;
				}

				.picts-plugin-page__eyebrow {
					color: var(--picts-teal);
					font-size: 12px;
					font-weight: 800;
					letter-spacing: 0;
					margin: 0 0 8px;
					text-transform: uppercase;
				}

				.picts-plugin-page__intro {
					color: #526579;
					font-size: 15px;
					line-height: 1.55;
					margin: 10px 0 0;
					max-width: 720px;
				}

				.picts-plugin-page__logo {
					flex: 0 0 auto;
					height: auto;
					max-width: 220px;
					width: 28%;
				}

				.picts-plugin-page .nav-tab-wrapper {
					flex-wrap: wrap;
					border-bottom: 0;
					display: flex;
					gap: 8px;
					margin: 18px 0 0;
					padding: 0;
				}

				.picts-plugin-page .nav-tab {
					background: #fff;
					border: 1px solid var(--picts-border);
					color: var(--picts-navy);
					font-weight: 700;
					margin: 0;
					padding: 10px 16px;
				}

				.picts-plugin-page .nav-tab:hover,
				.picts-plugin-page .nav-tab:focus {
					border-color: var(--picts-teal);
					color: var(--picts-teal);
				}

				.picts-plugin-page .nav-tab-active,
				.picts-plugin-page .nav-tab-active:hover,
				.picts-plugin-page .nav-tab-active:focus {
					background: var(--picts-navy);
					border-color: var(--picts-navy);
					color: #fff;
				}

				.picts-plugin-page__panel {
					background: #fff;
					border-top: 4px solid var(--picts-navy);
					box-shadow: 0 8px 22px rgba(25, 50, 85, 0.06);
					margin-top: 0;
					padding: 24px 28px;
				}

				.picts-plugin-page__panel h2,
				.picts-plugin-page__panel h3 {
					color: var(--picts-navy);
				}

				.picts-plugin-page__panel h2 {
					font-size: 20px;
					margin-top: 0;
				}

				.picts-plugin-page__panel h3 {
					font-size: 16px;
					margin-top: 24px;
				}

				.picts-plugin-page .form-table th {
					color: var(--picts-navy);
					font-weight: 700;
				}

				.picts-plugin-page input[type="text"],
				.picts-plugin-page input[type="number"],
				.picts-plugin-page input[type="search"],
				.picts-plugin-page input[type="password"],
				.picts-plugin-page input[type="date"],
				.picts-plugin-page select,
				.picts-plugin-page textarea {
					border-color: var(--picts-border);
				}

				.picts-plugin-page input:focus,
				.picts-plugin-page select:focus,
				.picts-plugin-page textarea:focus {
					border-color: var(--picts-teal);
					box-shadow: 0 0 0 1px var(--picts-teal);
				}

				.picts-plugin-page .button {
					color: var(--picts-teal);
					border-color: var(--picts-teal);
					background: #fff;
				}

				.picts-plugin-page .button:hover,
				.picts-plugin-page .button:focus {
					color: #006f86;
					border-color: #006f86;
					background: var(--picts-soft);
				}

				.picts-plugin-page .button:focus {
					box-shadow: 0 0 0 1px var(--picts-teal);
				}

				.picts-plugin-page input[type="file"],
				.picts-plugin-page input.regular-text {
					max-width: 100%;
				}

				.picts-plugin-page .button-primary {
					color: #fff;
					background: var(--picts-teal);
					border-color: var(--picts-teal);
				}

				.picts-plugin-page .button-primary:hover,
				.picts-plugin-page .button-primary:focus {
					color: #fff;
					background: #006f86;
					border-color: #006f86;
				}

				.picts-plugin-page .widefat {
					border-color: var(--picts-border);
				}

				.picts-plugin-page .widefat thead th {
					background: var(--picts-soft);
					color: var(--picts-navy);
					font-weight: 700;
				}

				.picts-plugin-page code {
					background: rgba(0, 128, 155, 0.08);
					color: var(--picts-navy);
				}

				.gdv-admin-graph-wrap,
				.gdv-dashboard-widget {
					max-width: 100%;
					overflow-x: auto;
				}

				.gdv-click-graph {
					display: block;
					height: auto;
					max-width: 100%;
				}

				.gdv-gemini-status {
					border-left: 4px solid var(--picts-teal);
					margin: 18px 0 8px;
					padding: 12px 14px;
				}

				.gdv-gemini-status--ready {
					background: rgba(0, 128, 155, 0.08);
					color: var(--picts-teal);
				}

				.gdv-gemini-status--missing {
					background: #fff8e5;
					border-left-color: #dba617;
					color: #7a5600;
				}

				.gdv-gemini-form {
					margin-top: 12px;
				}

				.gdv-gemini-result {
					background: var(--picts-soft);
					border: 1px solid var(--picts-border);
					margin-top: 24px;
					padding: 18px;
				}

				.gdv-gemini-result h3 {
					margin-top: 0;
				}

				@media (max-width: 900px) {
					.picts-dashboard__hero,
					.picts-plugin-page__hero,
					.picts-dashboard__grid {
						display: block;
					}

					.picts-dashboard__actions {
						margin-top: 20px;
					}

					.picts-dashboard__panel {
						margin-top: 20px;
					}

					.picts-plugin-page__logo {
						margin-top: 20px;
						width: 220px;
					}

					.picts-plugin-page .nav-tab-wrapper {
						flex-wrap: wrap;
					}
				}
			</style>
			<?php
		}

		/**
		 * Checks whether another plugin has already created the top-level menu.
		 *
		 * @return bool
		 */
		private static function menu_exists() {
			global $menu;

			if ( empty( $menu ) || ! is_array( $menu ) ) {
				return false;
			}

			foreach ( $menu as $item ) {
				if ( isset( $item[2] ) && self::MENU_SLUG === $item[2] ) {
					return true;
				}
			}

			return false;
		}
	}
}
