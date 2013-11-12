<?php

if (!defined('WP_LINKEDIN_CACHETIMEOUT')) {
	define('WP_LINKEDIN_CACHETIMEOUT', 43200); // 12 hours
}

// Let people define their own APPKEY if needed
if (!defined('WP_LINKEDIN_APPKEY')) {
	define('WP_LINKEDIN_APPKEY', '57zh7f1nvty5');
	define('WP_LINKEDIN_APPSECRET', 'FL0gcEC2b0G18KPa');
}

class WPLinkedInOAuth {

	function set_last_error($error=false) {
		if ($error) {
			update_option('wp-linkedin_last_error', $error);
			error_log('[WP LinkedIn] ' . $error);
		} else {
			delete_option('wp-linkedin_last_error');
		}
	}

	function get_last_error() {
		return get_option('wp-linkedin_last_error', false);
	}

	function get_access_token() {
		return apply_filters('linkedin_oauthtoken', get_transient('wp-linkedin_oauthtoken'));
	}

	function invalidate_access_token() {
		if (!has_filter('linkedin_oauthtoken')) {
			// If the token is filtered then let's assume somebody else is taking care of it's lifecycle
			delete_transient('wp-linkedin_oauthtoken');
		}
	}

	function set_access_token($code) {
		$this->set_last_error();
		$url = 'https://www.linkedin.com/uas/oauth2/accessToken?' . $this->urlencode(array(
			'grant_type' => 'authorization_code',
			'code' => $code,
			'redirect_uri' => site_url('/wp-admin/options-general.php?page=wp-linkedin'),
			'client_id' => WP_LINKEDIN_APPKEY,
			'client_secret' => WP_LINKEDIN_APPSECRET));

		$response = wp_remote_get($url, array('sslverify' => LINKEDIN_SSL_VERIFYPEER));
		if (!is_wp_error($response)) {
			$body = json_decode($response['body']);

			if (isset($body->access_token)) {
				update_option('wp-linkedin_invalid_token_mail_sent', false);
				return set_transient('wp-linkedin_oauthtoken', $body->access_token, $body->expires_in);
			} elseif (isset($body->error)) {
				return new WP_Error($body->error, $body->error_description);
			} else {
				return new WP_Error('unknown', __('An unknown error has occured and no token was retrieved.'));
			}
		} else {
			return $response;
		}
	}

	function is_access_token_valid() {
		if (!has_filter('linkedin_oauthtoken')) {
			return $this->get_access_token() !== false;
		} else {
			// If the token is filtered then let's assume somebody else is taking care of it's lifecycle
			return true;
		}
	}

	function get_state_token() {
		$time = intval(time() / 172800);
		return sha1('linkedin-oauth' . NONCE_SALT . $time);
	}

	function check_state_token($token) {
		return ($token == $this->get_state_token());
	}

	function get_authorization_url() {
		return 'https://www.linkedin.com/uas/oauth2/authorization?' . $this->urlencode(array(
				'response_type' => 'code',
				'client_id' => WP_LINKEDIN_APPKEY,
				'scope' => 'r_fullprofile r_network rw_nus',
				'state' => $this->get_state_token(),
				'redirect_uri' => site_url('/wp-admin/options-general.php?page=wp-linkedin')));
	}

	function clear_cache() {
		delete_option('wp-linkedin_cache');
	}

	function get_profile($options='id', $lang='') {
		$profile = false;
		$cache_key = apply_filters('linkedin_cachekey', sha1($options.$lang));

		$cache = get_option('wp-linkedin_cache');
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
		if ($fetched) {
			$profile = $fetched;

			$cache[$cache_key] = array(
					'expires' => time() + WP_LINKEDIN_CACHETIMEOUT,
					'profile' => $profile);
			update_option('wp-linkedin_cache', $cache);
		}

		// But if we cannot fetch one, let's return the outdated one if any.
		return $profile;
	}

	function fetch_profile($options='id', $lang='') {
		$access_token = $this->get_access_token();

		if ($access_token) {
			$url = "https://api.linkedin.com/v1/people/~:($options)?" . $this->urlencode(array('oauth2_access_token' => $access_token));
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
					}

					if (isset($body->message)) {
						$error = $body->message;
					} else {
						$error = sprintf(__('HTTP request returned error code %d.'), $return_code);
					}
				}
			} else {
				$error = $response->get_error_code() . ': ' . $response->get_error_message();
			}
		}

		if (isset($error)) $this->set_last_error($error);
		$this->send_invalid_token_email();
		return false;
	}

	function get_network_updates($count=50, $only_self=true) {
		$access_token = $this->get_access_token();

		if ($access_token) {
			$params = array('oauth2_access_token' => $access_token, 'count' => $count);
			if ($only_self) $params['scope'] = 'self';

			$url = 'https://api.linkedin.com/v1/people/~/network/updates?' . $this->urlencode($params);

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
					}

					if (isset($body->message)) {
						$error = $body->message;
					} else {
						$error = sprintf(__('HTTP request returned error code %d.'), $return_code);
					}
				}
			} else {
				$error = $response->get_error_code() . ': ' . $response->get_error_message();
			}
		}

		if (isset($error)) $this->set_last_error($error);
		$this->send_invalid_token_email();
		return false;
	}

	function send_invalid_token_email() {
		if (LINKEDIN_SENDMAIL_ON_TOKEN_EXPIRY && !get_option('wp-linkedin_invalid_token_mail_sent', false)) {
			$blog_name = get_option('blogname');
			$admin_email = get_option('admin_email');
			$header = array("From: $blog_name <$admin_email>");
			$subject = '[WP LinkedIn] ' . __('Invalid or expired access token', 'wp-linkedin');

			$message = __("The access token for the WP LinkedIn plugin is either invalid or has expired, please click on the following link to renew it.\n\n%s\n\nThis link will only be valid for a limited period of time.\n-Thank you.", 'wp-linkedin');
			$message = sprintf($message, $this->get_authorization_url());

			$sent = wp_mail($admin_email, $subject, $message, $header);
			update_option('wp-linkedin_invalid_token_mail_sent', $sent);
		}
	}

	function urlencode($params) {
		if (is_array($params)) {
			$p = array();
			foreach($params as $k => $v) {
				$p[] = $k . '=' . urlencode($v);
			}
			return implode('&', $p);
		} else {
			return urlencode($params);
		}
	}
}