<?php

namespace AncientWorks\Artifact\Modules\OxygenSandbox;

use AncientWorks\Artifact\Artifact;
use AncientWorks\Artifact\Module;
use AncientWorks\Artifact\Utils\DB;
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

	protected $selected_session;

	protected $string_prefix = [
		'options' => ['oxygen_vsb_', 'ct_'],
		'post_metadata' => ['ct_'],
	];

	// public function __construct()
	// {
	// }

	protected function is_active(): bool
	{
		if ($this->selected_session && current_user_can('manage_options')) {
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

	protected function get_sandbox_sessions()
	{
		return get_option($this->option_name);
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

	protected function reset_secrets($session)
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
		$available_sessions = $this->get_sandbox_sessions();
		$session_name       = $available_sessions['sessions'][$this->selected_session]['name'];

		$wp_admin_bar->add_node([
			'parent' => 'top-secondary',
			'id'    => self::$module_id,
			'title' => "<span style=\"font-weight:700;\">Sandbox:</span> {$session_name} <span style=\"color:limegreen;\">●</span>",
			'meta'  => [
				'title' => 'Oxygen Sandbox'
			],
			// 'href'  => add_query_arg( [ 'page' => self::$module_id, ], admin_url( 'admin.php' ) )
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

	/**
	 * 
	 * @param mixed $input_file is a $_FILES['sessionfile']
	 * @return false|void 
	 */
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
			// Notice::success( 'Sandbox session successfully imported.', 'Sandbox' );

			return;
		}

		// Notice::error( 'No sandbox session found to be imported.', 'Sandbox' );
	}

	public function do_import_session($session)
	{
		$notice_bags = [];

		if (!$this->is_valid_sessionfile_data($session, $notice_bags)) {
			// $_noticeString = 'Sandbox session could not be imported, invalid format: ';
			foreach ($notice_bags as $notice_bag) {
				// $_noticeString .= "<br/> - {$notice_bag}";
			}
			// Notice::error( $_noticeString, 'Sandbox' );

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
			// $notice_bag[] = "missing session_name";
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
			// Notice::error( 'Sandbox session could not be imported: ' . $file->get_error_message(), 'Sandbox' );

			return false;
		}

		if (isset($file['error'])) {
			// Notice::error( 'Sandbox session could not be imported: ' . $file['error'], 'Sandbox' );

			return false;
		}

		if (!isset($file['file'])) {
			// Notice::error( 'Sandbox session could not be imported: Upload failed.', 'Sandbox' );

			return false;
		}

		return $file;
	}
}
