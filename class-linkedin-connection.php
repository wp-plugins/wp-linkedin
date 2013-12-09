<?php

if (!defined('WP_LINKEDIN_CACHETIMEOUT')) {
	define('WP_LINKEDIN_CACHETIMEOUT', 43200); // 12 hours
}

// Let people define their own APPKEY if needed
if (!defined('WP_LINKEDIN_APPKEY')) {
	define('WP_LINKEDIN_APPKEY', '57zh7f1nvty5');
	define('WP_LINKEDIN_APPSECRET', 'FL0gcEC2b0G18KPa');
}

class WPLinkedInConnection {

	public function __construct() {
		$this->app_key = WP_LINKEDIN_APPKEY;
		$this->app_secret = WP_LINKEDIN_APPSECRET;
	}

	protected function set_cache($key, $value, $expires=0) {
		return set_transient($key, $value);
	}

	protected function get_cache($key, $default=false) {
		$value = get_transient($key);
		return ($value !== false) ? $value : $default;
	}

	protected function delete_cache($key) {
		return delete_transient($key);
	}

	public function set_last_error($error=false) {
		if ($error) {
			if (is_wp_error($error)) $error = $error->get_error_message();

			$this->set_cache('wp-linkedin_last_error', $error);
			error_log('[WP LinkedIn] ' . $error);
		} else {
			$this->delete_cache('wp-linkedin_last_error');
		}
	}

	public function get_last_error() {
		return $this->get_cache('wp-linkedin_last_error');
	}

	public function get_access_token() {
		return $this->get_cache('wp-linkedin_oauthtoken');
	}

	public function invalidate_access_token() {
		$this->delete_cache('wp-linkedin_oauthtoken');
	}

	public function get_token_process_url() {
		return site_url('/wp-admin/options-general.php?page=wp-linkedin');
	}

	public function set_access_token($code) {
		$this->set_last_error();
		$url = 'https://www.linkedin.com/uas/oauth2/accessToken?' . http_build_query(array(
			'grant_type' => 'authorization_code',
			'code' => $code,
			'redirect_uri' => $this->get_token_process_url(),
			'client_id' => $this->app_key,
			'client_secret' => $this->app_secret));

		$response = wp_remote_get($url, array('sslverify' => LINKEDIN_SSL_VERIFYPEER));
		if (!is_wp_error($response)) {
			$body = json_decode($response['body']);

			if (isset($body->access_token)) {
				$this->set_cache('wp-linkedin_invalid_token_mail_sent', false);
				return $this->set_cache('wp-linkedin_oauthtoken', $body->access_token, $body->expires_in);
			} elseif (isset($body->error)) {
				return new WP_Error('set_access_token', $body->error . ': ' . $body->error_description);
			} else {
				return new WP_Error('set_access_token', __('An unknown error has occured and no token was retrieved.', 'wp-linkedin'));
			}
		} else {
			return $response;
		}
	}

	public function is_access_token_valid() {
		return $this->get_access_token() !== false;
	}

	protected function get_state_token() {
		$time = intval(time() / 172800);
		return sha1('linkedin-oauth' . NONCE_SALT . $time);
	}

	public function check_state_token($token) {
		return ($token == $this->get_state_token());
	}

	public function get_authorization_url() {
		return 'https://www.linkedin.com/uas/oauth2/authorization?' . http_build_query(array(
				'response_type' => 'code',
				'client_id' => $this->app_key,
				'scope' => 'r_fullprofile r_network rw_nus',
				'state' => $this->get_state_token(),
				'redirect_uri' => $this->get_token_process_url()));
	}

	public function clear_cache() {
		$this->delete_cache('wp-linkedin_cache');
	}

	public function get_profile($options='id', $lang='') {
		$profile = false;
		$cache_key = sha1($options.$lang);

		$cache = $this->get_cache('wp-linkedin_cache');
		if (!is_array($cache)) $cache = array();

		// Do we have an up-to-date profile?
		if (isset($cache[$cache_key])) {
			$expires = $cache[$cache_key]['expires'];
			$profile = $cache[$cache_key]['profile'];
			// If yes let's return it.
			if (time() < $expires) return $profile;
		}

		// Else, let's try to fetch one.
		$fetched = $this->fetch_profile($options, $lang);
		if (!is_wp_error($fetched)) {
			$profile = $fetched;

			$cache[$cache_key] = array(
					'expires' => time() + WP_LINKEDIN_CACHETIMEOUT,
					'profile' => $profile);
			$this->set_cache('wp-linkedin_cache', $cache);
			return $profile;
		} elseif ($profile) {
			// If we cannot fetch one, let's return the outdated one if any.
			return $profile;
		} else {
			// Else just return the error
			return $fetched;
		}

	}

	protected function fetch_profile($options='id', $lang='') {
		$access_token = $this->get_access_token();

		if ($access_token) {
			$url = "https://api.linkedin.com/v1/people/~:($options)?" . http_build_query(array('oauth2_access_token' => $access_token));
			$headers = array(
					'Content-Type' => 'text/plain; charset=UTF-8',
					'x-li-format' => 'json');

			if (!empty($lang)) {
				$headers['Accept-Language'] = str_replace('_', '-', $lang);
			}

			$response = wp_remote_get($url, array('sslverify' => LINKEDIN_SSL_VERIFYPEER, 'headers' => $headers));
			if (!is_wp_error($response)) {
				$return_code = $response['response']['code'];
				$body = json_decode($response['body']);

				if ($return_code == 200) {
					$this->set_last_error();
					return $body;
				} else{
					if ($return_code == 401) {
						// Invalidate token
						$this->invalidate_access_token();
						$this->send_invalid_token_email();
					}

					if (isset($body->message)) {
						$error = new WP_Error('fetch_profile', $body->message);
					} else {
						$error = new WP_Error('fetch_profile', sprintf(__('HTTP request returned error code %d.', 'wp-linkedin'), $return_code));
					}

					$this->set_last_error($error);
					return $error;
				}
			} else {
				$this->set_last_error($response);
				return new WP_Error('fetch_profile', $response->get_error_message());
			}
		} else {
			$this->send_invalid_token_email();
			return new WP_Error('fetch_profile', __('No token or token has expired.', 'wp-linkedin'));
		}
	}

	public function get_network_updates($count=50, $only_self=true) {
		$access_token = $this->get_access_token();

		if ($access_token) {
			$params = array('oauth2_access_token' => $access_token, 'count' => $count);
			if ($only_self) $params['scope'] = 'self';

			$url = 'https://api.linkedin.com/v1/people/~/network/updates?' . http_build_query($params);

			$headers = array(
					'Content-Type' => 'text/plain; charset=UTF-8',
					'x-li-format' => 'json');

			$response = wp_remote_get($url, array('sslverify' => LINKEDIN_SSL_VERIFYPEER, 'headers' => $headers));
			if (!is_wp_error($response)) {
				$return_code = $response['response']['code'];
				$body = json_decode($response['body']);

				if ($return_code == 200) {
					$this->set_last_error();
					return $body;
				} else{
					if ($return_code == 401) {
						// Invalidate token
						$this->invalidate_access_token();
						$this->send_invalid_token_email();
					}

					if (isset($body->message)) {
						$error = new WP_Error('get_network_updates', $body->message);
					} else {
						$error = new WP_Error('get_network_updates', sprintf(__('HTTP request returned error code %d.', 'wp-linkedin'), $return_code));
					}

					$this->set_last_error($error);
					return $error;
				}
			} else {
				$this->set_last_error($response);
				return new WP_Error('get_network_updates', $response->get_error_message());
			}
		} else {
			$this->send_invalid_token_email();
			return new WP_Error('get_network_updates', __('No token or token has expired.', 'wp-linkedin'));
		}
	}

	protected function send_invalid_token_email() {
		if (LINKEDIN_SENDMAIL_ON_TOKEN_EXPIRY && !$this->get_cache('wp-linkedin_invalid_token_mail_sent')) {
			$blog_name = get_option('blogname');
			$admin_email = get_option('admin_email');
			$header = array("From: $blog_name <$admin_email>");
			$subject = '[WP LinkedIn] ' . __('Invalid or expired access token', 'wp-linkedin');

			$message = __('The access token for the WP LinkedIn plugin is either invalid or has expired, please click on the following link to renew it.\n\n%s\n\nThis link will only be valid for a limited period of time.\n-Thank you.', 'wp-linkedin');
			$message = sprintf($message, $this->get_authorization_url());

			$sent = wp_mail($admin_email, $subject, $message, $header);
			$this->set_cache('wp-linkedin_invalid_token_mail_sent', $sent);
		}
	}

}

function wp_linkedin_connection() {
	return apply_filters('linkedin_connection', new WPLinkedInConnection());
}