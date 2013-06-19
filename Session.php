<?php if( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * CodeIgniter Session Class
 *
 * Implements native PHP session functionality for CodeIgniter
 */
class CI_Session
{

  private $CI;
	private $flashdata_key = 'flash';
	private $now;
	
	private $sess_cookie_httponly = FALSE;
	private $sess_cookie_lifetime;
	
	private $sess_expiration = 7200;
	private $sess_expire_on_close = TRUE;
	private $sess_match_ip = FALSE;
	private $sess_match_useragent = TRUE;
	private $sess_time_to_update = 300;
	
	private $cookie_path = '';
	private $cookie_domain = '';
	private $cookie_secure = FALSE;
	

	/**
	 * Constructs session object
	 *
	 * @access public
	 * @return null
	 */
	public function __construct()
	{
		
		// Gets CodeIgniter object instace
		$this->CI =& get_instance();
	
		// Gets current time
		$this->now = time();
		
		// Configuration keys
		$keys = array(
			'sess_expiration',
			'sess_expire_on_close',
			'sess_match_ip',
			'sess_match_useragent',
			'cookie_path',
			'cookie_domain',
			'cookie_secure',
			'sess_time_to_update',
			'sess_cookie_httponly'
		);
		
		// Sets session configuration values from main CI config file
		foreach($keys as $key)
		{
			if($this->CI->config->item($key)) $this->$key = $this->CI->config->item($key);
		}
		
		// Sets the session length to the 2 years if expiration is zero
		if($this->sess_expiration == 0)
		{
			$this->sess_expiration = (60 * 60 * 24 * 365 * 2);
		}
		
		// Sets the session cookie lifetime
		$this->sess_cookie_lifetime = ($this->sess_expire_on_close === FALSE) ? $this->sess_expiration + $this->now : 0;
		
		// Sets the session cookie parameters
		session_set_cookie_params(
			$this->sess_cookie_lifetime,
			$this->cookie_path,
			$this->cookie_domain,
			$this->cookie_secure,
			$this->sess_cookie_httponly
		);
		
		// Checks if session isn't started yet
		if( ! isset($_SESSION))
		{
			// Starts session
            session_start();
        }
		
		// Checks if session is created and valid
		if( ! $this->sess_read())
		{
			// Destroys invalid session
			$this->sess_destroy();
			
			session_start();
			
			// Creates new session
			$this->_sess_create();
		}
		else
		{
			// Updates session data if needed
			$this->_sess_update();
		}
		
		// Deletes 'old' flashdata (from last request)
		$this->_flashdata_sweep();

		// Marks all new flashdata as old (data will be deleted before next request)
		$this->_flashdata_mark();
		
	}
	
	
	/**
	 * Regenerates session ID
	 *
	 * @access public
	 * @return null
	 */
	public function sess_regenerate_id()
	{
	
		// Regenerates session ID and deletes old session storage
		session_regenerate_id(TRUE);
		
		// Makes session ID available
		$_SESSION['session_id'] = session_id();
	
	}

	
	/**
	 * Destroys current session
	 *
	 * @access public
	 * @return null
	 */
	public function sess_destroy()
	{
	
		// Regenerates session ID
		$this->sess_regenerate_id();
	
		// Clears session data
		session_unset();
		
		// Gets session name
		$name = session_name();
		
		// Checks if session cookie exists
		if(isset($_COOKIE[$name]))
		{
			$params = session_get_cookie_params();
			setcookie($name, '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
			
			// Clears session cookie
			unset($_COOKIE[$name]);
		}
	
		// Destroys session
		session_destroy();
	
	}
	
	
	/**
	 * Fetches a specific value from the session array
	 *
	 * @access public
	 * @param string
	 * @return mixed
	 */
	public function userdata($key)
	{
		
		// Returns variable from session if exists
		return (isset($_SESSION[$key])) ? $_SESSION[$key] : FALSE;
	
	}

	
	/**
	 * Fetches all session data
	 *
	 * @access public
	 * @return array
	 */
	function all_userdata()
	{
	
		// Returns whole session array
		return $_SESSION;
		
	}
	
	
	/**
	 * Sets session variables
	 *
	 * @access public
	 * @param array|string
	 * @param mixed
	 * @return null
	 */
	public function set_userdata($new_data = array(), $new_value = '')
	{
	
		if(is_string($new_data))
		{
			$new_data = array($new_data => $new_value);
		}
		
		if(count($new_data) > 0)
		{
			foreach($new_data as $key => $value)
			{
				$_SESSION[$key] = $value;
			}
		}		
	
	}
	
	
	/**
	 * Unsets session variables
	 *
	 * @access public
	 * @param array
	 * @return null
	 */
	public function unset_userdata($new_data = array())
	{
	
		if(is_string($new_data))
		{
			$new_data = array($new_data => '');
		}

		if(count($new_data) > 0)
		{
			foreach($new_data as $key => $value)
			{
				unset($_SESSION[$key]);
			}
		}
	
	}
	
	
	/**
	 * Adds or changes flashdata, only available until the next request
	 *
	 * @access public
	 * @param mixed
	 * @param string
	 * @return null
	 */
	public function set_flashdata($new_data = array(), $new_value = '')
	{
	
		if(is_string($new_data))
		{
			$new_data = array($new_data => $new_value);
		}

		if(count($new_data) > 0)
		{
			foreach($new_data as $key => $value)
			{
				$flashdata_key = $this->flashdata_key .':new:'. $key;
				$this->set_userdata($flashdata_key, $value);
			}
		}
		
	}


	/**
	 * Keeps existing flashdata available to next request
	 *
	 * @access public
	 * @param string
	 * @return null
	 */
	public function keep_flashdata($key)
	{
	
		$old_flashdata_key = $this->flashdata_key .':old:'. $key;
		$value = $this->userdata($old_flashdata_key);

		$new_flashdata_key = $this->flashdata_key .':new:'. $key;
		$this->set_userdata($new_flashdata_key, $value);
		
	}


	/**
	 * Fetches a specific flashdata item from the session array
	 *
	 * @access public
	 * @param string
	 * @return string
	 */
	public function flashdata($key)
	{
	
		$flashdata_key = $this->flashdata_key .':old:'. $key;
		return $this->userdata($flashdata_key);
		
	}

	
	/**
	 * Checks and validates session
	 *
	 * @access private
	 * @return boolean
	 */
	private function sess_read()
	{
		
		// Checks if session is empty
		if(empty($_SESSION))
		{
			return FALSE;
		}
		
		// Checks if session array has correct format
		if( ! isset($_SESSION['last_activity']) || ! isset($_SESSION['ip_address']) || ! isset($_SESSION['user_agent']) || ! isset($_SESSION['session_id']))
		{
			return FALSE;
		}

		// Checks if session is expired
		if(($_SESSION['last_activity'] + $this->sess_expiration) < $this->now)
		{
			return FALSE;
		}
		
		// Checks if user IP matches
		if($this->sess_match_ip === TRUE && $session['ip_address'] != $this->CI->input->ip_address())
		{
			return FALSE;
		}
		
		// Checks if user agent matches
		if($this->sess_match_useragent === TRUE && $_SESSION['user_agent'] != $this->CI->input->user_agent())
		{
			return FALSE;
		}
		
		// Session is valid
		return TRUE;
		
	}
	
	
	/**
	 * Creates new session
	 *
	 * @access private
	 * @return null
	 */
	private function _sess_create()
	{
	
		// Creates new session array
		$_SESSION = array(
			'last_activity' => $this->now,
			'ip_address' => $this->CI->input->ip_address(),
			'user_agent' => $this->CI->input->user_agent(),
			'session_id' => session_id()
		);
		
	}
	
	
	/**
	 * Updates session
	 *
	 * @access private
	 * @return null
	 */
	private function _sess_update()
	{
	
		// Checks if regeneration is needed
		if(($_SESSION['last_activity'] + $this->sess_time_to_update) < $this->now)
		{
			// Regenerates session ID
			$this->sess_regenerate_id();
			
			// Updates last activity time
			$_SESSION['last_activity'] = $this->now;
		}

	}
	
	
	/**
	 * Identifies flashdata as 'old' for removal when _flashdata_sweep() runs
	 *
	 * @access private
	 * @return null
	 */
	private function _flashdata_mark()
	{
	
		$userdata = $this->all_userdata();
		
		foreach($userdata as $name => $value)
		{
		
			$parts = explode(':new:', $name);
			
			if(is_array($parts) && count($parts) === 2)
			{
				$new_name = $this->flashdata_key .':old:'. $parts[1];
				$this->set_userdata($new_name, $value);
				$this->unset_userdata($name);
			}
			
		}
		
	}


	/**
	 * Removes all flashdata marked as 'old'
	 *
	 * @access private
	 * @return null
	 */
	private function _flashdata_sweep()
	{
	
		$userdata = $this->all_userdata();
		
		foreach($userdata as $key => $value)
		{
		
			if(strpos($key, ':old:'))
			{
				$this->unset_userdata($key);
			}
			
		}

	}
	
}

/* End of file Session.php */
/* Location: ./application/libraries/Session.php */
