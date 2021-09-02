<?php

namespace AncientWorks\Artifact\Modules\OxygenSandbox;

use AncientWorks\Artifact\Admin;
use AncientWorks\Artifact\Admin\DashboardController;
use AncientWorks\Artifact\Artifact;
use AncientWorks\Artifact\Module;
use AncientWorks\Artifact\Utils\DB;
use AncientWorks\Artifact\Utils\Notice;
use AncientWorks\Artifact\Utils\OxygenBuilder;
use AncientWorks\Artifact\Utils\Utils;
use Composer\Semver\Comparator;
use WP_Admin_Bar;

/**
 * @package AncientWorks\Artifact
 * @since 0.0.1
 * @author ancientworks <mail@ancient.works>
 */
class Sandbox extends Module
{
	public static $module_id = 'oxygen_sandbox';
	public static $module_version = '0.0.1';
	public static $module_name = 'Oxygen Builder Sandbox';

	protected string $option_name = 'artifact_sandbox_sessions';

	protected bool $active = false;

	public $selected_session;
	public $sessions = null;

	protected $string_prefix = [
		'options' => [
			'oxygen_vsb_', // oxygen builder
			'ct_',
			'bricks_', // bricks builder
			'theme_mods_bricks',
			'brizy', // brizy builder
			'zionbuilder_', // zion builder
			'_zionbuilder_',
		],
		'post_metadata' => [
			'ct_',  // oxygen builder
			'_bricks_', // bricks builder
			'brizy', // brizy builder
			'zionbuilder_', // zion builder
			'_zionbuilder_',
		],
	];

	protected function actions()
	{
		if (!function_exists('wp_get_current_user')) {
			include_once ABSPATH . 'wp-includes/pluggable.php';
		}

		$available_sessions = $this->get_sandbox_sessions();

		if (
			!empty($_REQUEST['page'])
			&& $_REQUEST['page'] === Artifact::$slug
			&& !empty($_REQUEST['route'])
			&& $_REQUEST['route'] === 'dashboard'
			&& !empty($_REQUEST['module_id'])
			&& $_REQUEST['module_id'] === self::$module_id
			&& current_user_can('manage_options')
		) {
			if (
				!empty($_REQUEST['action'])
				&& $_REQUEST['action'] === 'export'
				&& !empty($_REQUEST['session'])
				&& array_key_exists($_REQUEST['session'], $available_sessions['sessions'])
			) {
				$this->export_changes($_REQUEST['session']);
				exit;
			}
		}
	}

	public function register()
	{
		add_action('admin_enqueue_scripts', function () {
			wp_register_style(self::$module_id . '-admin', plugins_url("/Modules/OxygenSandbox/assets/css/admin.css", ARTIFACT_FILE));
			wp_register_style(self::$module_id . '-oygen-editor', plugins_url("/Modules/OxygenSandbox/assets/css/admin.css", ARTIFACT_FILE));
			wp_register_script(self::$module_id . '-admin', plugins_url("/Modules/OxygenSandbox/assets/js/admin.js", ARTIFACT_FILE), [
				'artifact/dashboard'
			], false, true);
			wp_register_script(self::$module_id . '-oygen-editor', plugins_url("/Modules/OxygenSandbox/assets/js/oxygen-editor.js", ARTIFACT_FILE));
		});

		$this->actions();
	}

	public function boot()
	{
		Admin::$enqueue_styles[] = self::$module_id . '-admin';
		Admin::$enqueue_scripts[] = self::$module_id . '-admin';
		Admin::$localize_scripts[] = [
			'handle' => self::$module_id . '-admin',
			'object_name' => 'sandbox',
			'l10n' => [$this, 'localize_script'],
		];

		add_action('plugins_loaded', [$this, 'add_action_plugins_loaded']);

		DashboardController::registerModulePanel('grid', self::$module_id, self::$module_id . '::panel', [$this, 'handlePanel']);
	}

	public function add_action_plugins_loaded()
	{
		if (!$this->get_sandbox_sessions()) {
			$this->selected_session = $this->init_sessions();
		}

		$this->selected_session = $this->get_sandbox_sessions()['selected'];

		$this->active = $this->is_active();

		add_action('init', [$this, 'add_action_init']);
	}

	public function add_action_init()
	{
		if (current_user_can('manage_options')) {
			if (Utils::is_request('ajax')) {
				add_action("wp_ajax_" . self::$module_id . "_update_session", [$this, 'ajax_update_session']);
				add_action("wp_ajax_" . self::$module_id . "_rename_session", [$this, 'ajax_rename_session']);
			}
		}

		if (!$this->active) {
			return;
		}

		if (Utils::is_request('frontend') && OxygenBuilder::is_oxygen_editor()) {
			$available_sessions = $this->get_sandbox_sessions();

			add_action('wp_enqueue_scripts', function () use ($available_sessions) {
				wp_enqueue_style(self::$module_id . "-oygen-editor");
				wp_enqueue_script(self::$module_id . "-oygen-editor");
				wp_localize_script(self::$module_id . "-oygen-editor", 'sandbox', [
					'session' => $available_sessions['sessions'][$this->selected_session],
				]);
			});
		}

		foreach (array_keys(wp_load_alloptions()) as $option) {
			if ($this->match_string_prefix('options', $option)) {
				add_filter("pre_option_{$option}", [$this, 'pre_get_option'], 0, 3);
				add_filter("pre_update_option_{$option}", [$this, 'pre_update_option'], 0, 3);
			}
		}

		add_filter('get_post_metadata', [$this, 'get_post_metadata'], 0, 4);
		add_filter('update_post_metadata', [$this, 'update_post_metadata'], 0, 5);
		add_filter('delete_post_metadata', [$this, 'delete_post_metadata'], 0, 5);

		add_action('admin_bar_menu', [$this, 'admin_bar_node'], 100);
		add_filter('body_class', function ($classes) {
			return array_merge($classes, [self::$module_id . "-{$this->selected_session}"]);
		});
		add_filter('admin_body_class', function ($classes) {
			return "{$classes} " . self::$module_id . "-{$this->selected_session}";
		});
	}

	public function localize_script()
	{
		return [
			'ajax_url'  => admin_url('admin-ajax.php'),
			'_wpnonce'  => \wp_create_nonce('artifact'),
			'module_id' => self::$module_id,
		];
	}

	public function ajax_rename_session()
	{
		wp_verify_nonce($_REQUEST['_wpnonce'], 'artifact');

		$session            = sanitize_text_field($_REQUEST['session']);
		$new_name           = sanitize_text_field($_REQUEST['new_name']);
		$available_sessions = $this->get_sandbox_sessions();

		if (array_key_exists($session, $available_sessions['sessions'])) {
			$old_name = $available_sessions['sessions'][$session]['name'];

			$available_sessions['sessions'][$session]['name'] = $new_name;

			update_option($this->option_name, $available_sessions);

			wp_send_json_success('Sandbox session renamed from ' . $old_name . ' to ' . $new_name);
		} else {
			wp_send_json_error('Session not available, could not rename', 422);
		}
		exit;
	}

	public function ajax_update_session()
	{
		wp_verify_nonce($_REQUEST['_wpnonce'], 'artifact');

		$session            = sanitize_text_field($_REQUEST['session']);
		$available_sessions = $this->get_sandbox_sessions();

		if ('false' === $session) {
			$this->unset_session();
			wp_send_json_success('Sandbox disabled');
		} elseif (array_key_exists($session, $available_sessions['sessions'])) {
			$this->set_session($session);
			wp_send_json_success('Sandbox session changed to ' . $available_sessions['sessions'][$session]['name']);
		} else {
			wp_send_json_error('Session not available', 422);
		}
		exit;
	}

	public function handlePanel()
	{
		$available_sessions = $this->get_sandbox_sessions();

		if ($_REQUEST['action'] === 'add') {
			$random_number = random_int(10000, 99999);

			$available_sessions['sessions'][$random_number] = [
				'id'     => $random_number,
				'name'   => "Sandbox #{$random_number}",
				'secret' => wp_generate_uuid4(),
			];

			update_option($this->option_name, $available_sessions);

			Notice::success("New sandbox session created with id: #{$random_number}");
			return true;
		} elseif (
			$_SERVER['REQUEST_METHOD'] === 'POST'
			&& $_REQUEST['action'] === 'import'
			&& isset($_FILES['sessionfile'])
		) {
			$this->import_changes($_FILES['sessionfile']);

			return true;
		} elseif (
			$_REQUEST['action'] === 'publish'
			&& !empty($_REQUEST['session'])
			&& array_key_exists($_REQUEST['session'], $available_sessions['sessions'])
		) {
			$session = sanitize_text_field($_REQUEST['session']);
			$session_name = $available_sessions['sessions'][$session]['name'];
			$this->publish_changes($session);
			$this->delete_changes($session);
			Notice::success("Sandbox session (name: {$session_name}) published succesfuly.");

			return true;
		} elseif (
			$_REQUEST['action'] === 'delete'
			&& !empty($_REQUEST['session'])
			&& array_key_exists($_REQUEST['session'], $available_sessions['sessions'])
		) {
			$session = sanitize_text_field($_REQUEST['session']);
			$session_name = $available_sessions['sessions'][$session]['name'];

			$this->delete_changes($session);
			Notice::success("Sandbox session (name: {$session_name}) deleted succesfuly.");

			return true;
		} elseif (
			$_REQUEST['action'] === 'reset_secret'
			&& !empty($_REQUEST['session'])
			&& array_key_exists($_REQUEST['session'], $available_sessions['sessions'])
		) {
			$session = sanitize_text_field($_REQUEST['session']);
			$session_name = $available_sessions['sessions'][$session]['name'];

			$this->reset_secret($session);
			Notice::success("Sandbox session (name: <u><span title=\"ID: {$session}\">{$session_name}</span></u>) token has been reset succesfuly.");

			return true;
		}
	}

	protected function is_active(): bool
	{
		if ($this->get_sandbox_sessions() && current_user_can('manage_options')) {
			return true;
		}

		if ($this->validate_cookie()) {
			return true;
		}

		$session = isset($_GET['session']) ? sanitize_text_field($_GET['session']) : false;
		$secret  = isset($_GET[self::$module_id]) ? sanitize_text_field($_GET[self::$module_id]) : false;

		$available_sessions = $this->get_sandbox_sessions()['sessions'];

		if ($session && $secret) {
			if (
				array_key_exists($session, $available_sessions)
				&& $secret === $available_sessions[$session]['secret']
			) {
				$this->set_cookie(['session' => $session, 'secret' => $secret]);
			} else {
				$this->unset_cookie();
			}
		}

		return false;
	}

	private function validate_cookie(): bool
	{
		if (isset($_GET[self::$module_id]) && isset($_GET['session'])) {
			return false;
		}

		$cookie = isset($_COOKIE[self::$module_id]) ? json_decode($_COOKIE[self::$module_id]) : false;

		if ($cookie) {
			$available_sessions = $this->get_sandbox_sessions()['sessions'];

			if (
				array_key_exists($cookie->session, $available_sessions)
				&& $cookie->secret === $available_sessions[$cookie->session]['secret']
			) {
				$this->selected_session = $available_sessions[$cookie->session]['id'];

				return true;
			}

			$this->unset_cookie();
		}

		return false;
	}

	protected function set_cookie($data): void
	{
		setcookie(self::$module_id, json_encode($data), time() + DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN);

		header("location:javascript://history.go(-1)");
		exit;
	}

	protected function unset_cookie(): void
	{
		setcookie(self::$module_id, null, -1, COOKIEPATH, COOKIE_DOMAIN);

		header("location:javascript://history.go(-1)");
		exit;
	}

	protected function init_sessions()
	{
		$random_number = random_int(10000, 99999);
		update_option($this->option_name, [
			'selected' => $random_number,
			'sessions' => [
				$random_number => [
					'id'     => $random_number,
					'secret' => wp_generate_uuid4(),
					'name'   => "Sandbox #{$random_number}"
				]
			]
		]);

		return $random_number;
	}

	public function get_sandbox_sessions()
	{
		if ($this->sessions === null) {
			$this->sessions = get_option($this->option_name);
		}

		return $this->sessions;
	}

	protected function set_session($session): void
	{
		$available_sessions = $this->get_sandbox_sessions();
		$available_sessions['selected'] = $session;
		update_option($this->option_name, $available_sessions);
	}

	protected function unset_session(): void
	{
		$available_sessions = $this->get_sandbox_sessions();
		$available_sessions['selected'] = false;
		update_option($this->option_name, $available_sessions);
	}

	protected function reset_secret($session)
	{
		$available_sessions = $this->get_sandbox_sessions();
		$available_sessions['sessions'][$session]['secret'] = wp_generate_uuid4();
		update_option($this->option_name, $available_sessions);
	}

	public function pre_get_option($pre_option, string $option, $default)
	{
		if ($option === 'oxygen_vsb_universal_css_cache') {
			return 'false';
		}

		if (DB::has('options', ['option_name' => self::$module_id . "_{$this->selected_session}_{$option}",])) {
			$pre_option = get_option(self::$module_id . "_{$this->selected_session}_{$option}", $default);
		}

		return $pre_option;
	}

	public function pre_update_option($value, $old_value, string $option)
	{
		if ($option === 'oxygen_vsb_universal_css_cache') {
			return $old_value;
		}

		update_option(self::$module_id . "_{$this->selected_session}_{$option}", $value);

		return $old_value;
	}

	protected function match_string_prefix($type, $str)
	{
		foreach ($this->string_prefix[$type] as $string_prefix) {
			if (strpos($str, $string_prefix) === 0) {
				return true;
			}
		}
		return false;
	}

	public function update_post_metadata($check, $object_id, $meta_key, $meta_value, $prev_value)
	{
		return $this->match_string_prefix('post_metadata', $meta_key)
			? update_metadata('post', $object_id, self::$module_id . "_{$this->selected_session}_{$meta_key}", $meta_value, $prev_value)
			: $check;
	}

	public function delete_post_metadata($delete, $object_id, $meta_key, $meta_value, $delete_all)
	{
		return $this->match_string_prefix('post_metadata', $meta_key)
			? delete_metadata('post', $object_id, self::$module_id . "_{$this->selected_session}_{$meta_key}", $meta_value, $delete_all)
			: $delete;
	}

	public function get_post_metadata($value, $object_id, $meta_key, $single)
	{
		if ($this->match_string_prefix('post_metadata', $meta_key) && metadata_exists('post', $object_id, self::$module_id . "_{$this->selected_session}_{$meta_key}")) {
			$value = get_metadata('post', $object_id, self::$module_id . "_{$this->selected_session}_{$meta_key}", $single);
			if ($single && is_array($value)) {
				$value = [$value];
			}
		}

		return $value;
	}

	public function admin_bar_node(WP_Admin_Bar $wp_admin_bar)
	{
		if (false === $this->selected_session) {
			return;
		}

		$available_sessions = $this->get_sandbox_sessions();
		$session            = $available_sessions['sessions'][$this->selected_session];
		$session_name       = $session['name'];

		$wp_admin_bar->add_node([
			'parent' => 'top-secondary',
			'id'    => self::$module_id,
			'title' => "<span style=\"font-weight:700;\">Sandbox:</span> {$session_name} <span style=\"color:limegreen;\">●</span>",
			'meta'  => [
				'title' => 'Oxygen Sandbox'
			],
			'href'  => add_query_arg([
				'page' => Artifact::$slug,
				'route' => 'dashboard#oxygen-sandbox-session-' . $session['id'],
			], admin_url('admin.php'))
		]);
	}

	protected function export_changes($session): void
	{
		$timestamp          = time();
		$available_sessions = $this->get_sandbox_sessions();
		$_session           = $available_sessions['sessions'][$session];

		$_options  = $this->retrieve_sandbox_options($session);
		$_postmeta = $this->retrieve_sandbox_postmeta($session);

		$data = [
			'module_id'      => 'artifact_' . self::$module_id,
			'version'        => Artifact::$version,
			'export_time'    => $timestamp,
			'site_url'       => site_url(),
			'session_id'     => $_session['id'],
			'session_name'   => $_session['name'],
			'session_secret' => $_session['secret'],
			'data'           => [
				'postmeta' => $_postmeta,
				'options'  => $_options,
			],
		];

		$filename = "artifact-sandbox-session-{$session}-{$timestamp}.json";

		header('Content-Type: application/json');
		header("Content-Disposition: attachment; filename={$filename}");
		header('Cache-Control: no-cache, no-store, must-revalidate');
		header('Pragma: no-cache');
		header('Expires: 0');

		echo json_encode($data);
		exit;
	}

	protected function retrieve_sandbox_options($session)
	{
		$options = DB::select('options', [
			'option_id',
			'option_name',
			'option_value',
			'autoload',
		], [
			'option_name[~]' => self::$module_id . "_{$session}_%"
		]);

		if ($options) {
			foreach ($options as $key => $value) {
				$options[$key]['option_name']  = str_replace(self::$module_id . "_{$session}_", '', $options[$key]['option_name']);
				$options[$key]['option_value'] = base64_encode($options[$key]['option_value']);
			}
		}

		return $options ?? [];
	}

	protected function retrieve_sandbox_postmeta($session)
	{
		$postmetas = DB::select('postmeta', [
			'meta_id',
			'post_id',
			'meta_key',
			'meta_value',
		], [
			'meta_key[~]' => self::$module_id . "_{$session}_%"
		]);

		if ($postmetas) {
			foreach ($postmetas as $key => $value) {
				$postmetas[$key]['meta_key']   = str_replace(self::$module_id . "_{$session}_", '', $postmetas[$key]['meta_key']);
				$postmetas[$key]['meta_value'] = base64_encode($postmetas[$key]['meta_value']);
			}
		}

		return $postmetas ?? [];
	}

	public function publish_changes($session): void
	{
		$this->publish_sandbox_options($session);
		$this->publish_sandbox_postmeta($session);

		$this->unset_session();
	}

	public static function ltrim(string $string, string $prefix): string
	{
		return strpos($string, $prefix) === 0
			? substr($string, strlen($prefix))
			: $string;
	}

	public function publish_sandbox_options($session): void
	{
		$options = DB::select('options', [
			'option_id',
			'option_name',
			'option_value',
		], [
			'option_name[~]' => self::$module_id . "_{$session}_%"
		]);

		if ($options) {
			foreach ($options as $option) {
				$option       = (object) $option;
				$_option_name = self::ltrim($option->option_name, self::$module_id . "_{$session}_");

				$existing_option = DB::get('options', 'option_id', [
					'option_name' => $_option_name
				]);

				if ($existing_option) {
					DB::delete('options', [
						'option_id' => $existing_option,
					]);
				}

				DB::update('options', [
					'option_name' => $_option_name,
				], [
					'option_id' => $option->option_id,
				]);
			}
		}
	}

	public function publish_sandbox_postmeta($session): void
	{
		$postmetas = DB::select('postmeta', [
			'meta_id',
			'post_id',
			'meta_key',
			'meta_value',
		], [
			'meta_key[~]' => self::$module_id . "_{$session}_%"
		]);

		if ($postmetas) {
			foreach ($postmetas as $postmeta) {
				$postmeta      = (object) $postmeta;
				$_postmeta_key = self::ltrim($postmeta->meta_key, self::$module_id . "_{$session}_");

				$existing_postmeta = DB::get('postmeta', 'meta_id', [
					'post_id'  => $postmeta->post_id,
					'meta_key' => $_postmeta_key,
				]);

				if ($existing_postmeta) {
					DB::delete('postmeta', [
						'meta_id' => $existing_postmeta,
					]);
				}

				DB::update('postmeta', [
					'meta_key' => $_postmeta_key,
				], [
					'meta_id' => $postmeta->meta_id,
				]);
			}
		}
	}

	public function delete_changes($session): void
	{
		$this->delete_sandbox_options($session);
		$this->delete_sandbox_postmeta($session);

		if ($this->selected_session === $session) {
			$this->unset_session();
		}

		$available_sessions = $this->get_sandbox_sessions();

		$available_sessions['sessions'] = array_filter($available_sessions['sessions'], function ($v, $k) use ($session) {
			return $k !== (int) $session;
		}, ARRAY_FILTER_USE_BOTH);

		update_option($this->option_name, $available_sessions);
	}

	protected function delete_sandbox_options($session): void
	{
		DB::delete('options', [
			'option_name[~]' => self::$module_id . "_{$session}_%"
		]);
	}

	protected function delete_sandbox_postmeta($session): void
	{
		DB::delete('postmeta', [
			'meta_key[~]' => self::$module_id . "_{$session}_%"
		]);
	}

	public function install(): bool
	{
		if (!$this->get_sandbox_sessions()) {
			$this->init_sessions();
		}

		return parent::install();
	}

	protected function import_sandbox_options(array $session): void
	{
		$session = (object) $session;

		DB::delete('options', [
			'option_name[~]' => self::$module_id . "_{$session->session_id}_%"
		]);

		foreach ($session->data['options'] as $option) {
			$option = (object) $option;

			DB::insert('options', [
				'option_name'  => self::$module_id . "_{$session->session_id}_{$option->option_name}",
				'option_value' => base64_decode($option->option_value),
				'autoload'     => $option->autoload
			]);
		}
	}

	public function import_sandbox_postmeta($session): void
	{
		$session = (object) $session;

		DB::delete('postmeta', [
			'meta_key[~]' => self::$module_id . "_{$session->session_id}_%"
		]);

		foreach ($session->data['postmeta'] as $postmeta) {
			$postmeta = (object) $postmeta;

			DB::insert('postmeta', [
				'post_id'    => $postmeta->post_id,
				'meta_key'   => self::$module_id . "_{$session->session_id}_{$postmeta->meta_key}",
				'meta_value' => base64_decode($postmeta->meta_value),
			]);
		}
	}

	public function import_changes($input_file)
	{
		$file = $this->has_valid_file($input_file);
		if (false === $file) {
			return false;
		}

		$wp_filesystem = $this->get_filesystem();
		$session       = $wp_filesystem->get_contents($file['file']);
		$session       = json_decode($session, true);

		unlink($file['file']);

		if (is_array($session) && $this->do_import_session($session)) {
			Notice::success('Sandbox session successfully imported.');

			return;
		}

		Notice::error('No sandbox session found to be imported.');
	}

	public function do_import_session($session)
	{
		$notice_bags = [];

		if (!$this->is_valid_sessionfile_data($session, $notice_bags)) {
			$_noticeString = 'Sandbox session could not be imported, invalid format: ';
			foreach ($notice_bags as $notice_bag) {
				$_noticeString .= "<br/> - {$notice_bag}";
			}

			Notice::error($_noticeString);

			return false;
		}

		$available_sessions                                       = $this->get_sandbox_sessions();
		$available_sessions['sessions'][$session['session_id']] = [
			'id'     => $session['session_id'],
			'name'   => $session['session_name'] ?? "Sandbox #" . $session['session_id'],
			'secret' => $session['session_secret'],
		];
		update_option($this->option_name, $available_sessions);

		$this->import_sandbox_options($session);
		$this->import_sandbox_postmeta($session);

		return true;
	}

	protected function is_valid_sessionfile_data($session, &$notice_bag)
	{
		if (
			!array_key_exists('module_id', $session)
			|| $session['module_id'] !== 'artifact_' . self::$module_id
		) {
			$notice_bag[] = "the file is not generated by Artifact";

			return false;
		}

		if (
			!array_key_exists('version', $session)
			|| Comparator::lessThan(Artifact::$version, $session['version'])
		) {
			$notice_bag[] = "installed Artifact version is lower than the version defined on the sandbox session file";

			return false;
		}

		if (
			!array_key_exists('session_id', $session)
			|| !is_int($session['session_id'])
		) {
			$notice_bag[] = "missing session_id / invalid session_id";

			return false;
		}


		if (
			array_key_exists($session['session_id'], $this->get_sandbox_sessions()['sessions'])
		) {
			$notice_bag[] = "session_id is already exist, please delete the sandbox session before importing the sandbox session file";

			return false;
		}

		if (
			!array_key_exists('session_secret', $session)
			|| !wp_is_uuid($session['session_secret'])
		) {
			$notice_bag[] = "missing session_secret / invalid session_secret";

			return false;
		}

		if (
			!array_key_exists('site_url', $session)
			|| $session['site_url'] !== site_url()
		) {
			// $notice_bag[] = "missing site_url / missmatch site_url";
		}

		if (
			!array_key_exists('session_name', $session)
		) {
			$notice_bag[] = "missing session_name";
		}

		if (
			!array_key_exists('data', $session)
			|| !array_key_exists('postmeta', $session['data'])
			|| !array_key_exists('options', $session['data'])
			|| !is_array($session['data']['postmeta'])
			|| !is_array($session['data']['options'])
		) {
			$notice_bag[] = "missing data / invalid data";

			return false;
		}

		return true;
	}

	public function allow_json_upload($types, $user)
	{
		$types['txt']  = 'text/plain';
		$types['json'] = 'application/json';

		return $types;
	}

	public static function get_filesystem()
	{
		global $wp_filesystem;

		if (!defined('FS_METHOD')) {
			define('FS_METHOD', 'direct');
		}

		if (empty($wp_filesystem)) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		return $wp_filesystem;
	}

	private function has_valid_file($input_file)
	{
		add_filter('upload_mimes', [$this, 'allow_json_upload'], 10, 2);

		if (!function_exists('wp_handle_upload')) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
		}
		$file = wp_handle_upload($input_file, ['test_form' => false, 'test_type' => false,]);

		remove_filter('upload_mimes', [$this, 'allow_json_upload'], 10);

		if (is_wp_error($file)) {
			/** @var WP_Error $file */
			Notice::error('Sandbox session could not be imported: ' . $file->get_error_message());

			return false;
		}

		if (isset($file['error'])) {
			Notice::error('Sandbox session could not be imported: ' . $file['error']);

			return false;
		}

		if (!isset($file['file'])) {
			Notice::error('Sandbox session could not be imported: Upload failed.');

			return false;
		}

		return $file;
	}
}
