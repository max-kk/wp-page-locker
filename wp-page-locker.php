<?php
/*
	Library Name:  ======= WPL - Wordpress Page LOCKER [beta] =======
	Library URI: -
	Author: Maxim Kaminsky
	Author URI: http://www.maxim-kaminsky.com/
	Plugin support EMAIL: support@wp-vote.net
	Version: 0.1
  
	THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
	ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
	WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
	DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR
	ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
	(INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
	LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
	ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
	(INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
	SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.   
	
 */

if ( class_exists('WpPageLocking') ) {
    return;
}

/**
 * Library features:
 * - lock admin pages to editing another user / the same user with another IP
 * - Request control on Locked pages
 * - Accept / Reject remove lock request
 *
 * Useful if you does't wan't allow another users override his/her setting changes.
 *
 * ==========================================================================
 * Class handles all tasks related to locking.
 *
 * - Loads the WordPress Heartbeat API and scripts & styles for Pages Locking
 * - Provides standardized UX
 *
 * @package WP PAGE Locking
 * @author  Rocketgenius and rewritten by MK
 * @version 1.0
 */
abstract class WpPageLocking {
	private $_object_type;
	private $_object_id;
	private $_edit_url;
	private $_redirect_url;
	private $_capabilities;
	const PREFIX_EDIT_LOCK         = 'lock_';
	const PREFIX_EDIT_LOCK_REQUEST = 'lock_request_';
	const VERSION = 1.0;


	public function __construct( $object_type, $redirect_url, $edit_url = '', $capabilities = array() ) {
		$this->_object_type  = $object_type;
		$this->_redirect_url = $redirect_url;
		$this->_capabilities = $capabilities;
        
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			$this->init_ajax();
		} else {
			$this->register_scripts();
			$is_locking_page = false;
			$is_edit_page    = false;
			if ( $this->is_edit_page() ) {
                // Common USED !! MAX                
				$this->init_edit_lock();
				$is_locking_page = true;
				$is_edit_page    = true;
			} else if ( $this->is_list_page() ) {
				$this->init_list_page();
				$is_locking_page = true;
			}
            
			if ( $is_locking_page ) {
				$this->_object_id = $this->get_object_id();
				$this->_edit_url  = $edit_url;
				$this->maybe_lock_object( $is_edit_page );
			}
		}
	}

	/**
	 * Override this method to check the condition for the edit page.
	 *
	 * @return bool
	 */
	protected function is_edit_page() {
		return false;
	}

	/**
	 * Override this method to check the condition for the list page.
	 *
	 * @return bool
	 */
	protected function is_list_page() {
		return false;
	}

	/**
	 * Override this method to provide the class with the correct object id.
	 *
	 * @return int
	 */
	protected function get_object_id() {
        // example in the case of form id
		return isset( $_GET['id'] ) ? absint($_GET['id']) : 0;
	}

	public function init_edit_lock() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	public function init_ajax() {
		//add_filter( 'heartbeat_received', array( $this, 'heartbeat_check_locked_objects' ), 10, 3 );

		add_filter( 'heartbeat_received', array( $this, 'heartbeat_refresh_lock' ), 10, 3 );
		add_filter( 'heartbeat_received', array( $this, 'heartbeat_request_lock' ), 10, 3 );
        
		add_filter( 'wp_ajax_wpl_lock_request_' . $this->_object_type, array( $this, 'ajax_lock_request' ) );
		add_filter( 'wp_ajax_wpl_reject_lock_request_' . $this->_object_type, array( $this, 'ajax_reject_lock_request' ) );
	}

	public function init_list_page() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_list_scripts' ) );
	}


	public function register_scripts() {
		//$min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG || isset( $_GET['gform_debug'] ) ? '' : '.min';
		$locking_path = plugin_dir_url( __FILE__ );

		wp_register_script( 'wp-page-locking', $locking_path . "assets/wp-page-locker.js", array( 'jquery', 'heartbeat' ), self::VERSION );
		//wp_register_script( 'gforms_locking_list', $locking_path . "js/locking-list{$min}.js", array( 'jquery', 'heartbeat' ), GFCommon::$version );
		wp_register_style( 'wp-page-locking', $locking_path . "assets/wp-page-locker.css", array(), self::VERSION );
		//wp_register_style( 'gforms_locking_list_css', $locking_path . "css/locking-list{$min}.css", array(), GFCommon::$version );
	}

	public function enqueue_scripts() {

		wp_enqueue_script( 'wp-page-locking' );
		wp_enqueue_style( 'wp-page-locking' );
		$lock_user_id = $this->check_lock( $this->get_object_id() );

		$strings = array(
			'noResponse'    => $this->get_string( 'no_response' ),
			'requestAgain'  => $this->get_string( 'request_again' ),
			'requestError'  => $this->get_string( 'request_error' ),
			'gainedControl' => $this->get_string( 'gained_control' ),
			'rejected'      => $this->get_string( 'request_rejected' ),
			'pending'       => $this->get_string( 'request_pending' )
		);


		$vars = array(
			'hasLock'    => ! $lock_user_id ? 1 : 0,
			'lockUI'     => $this->get_lock_ui( $lock_user_id ),
			'objectID'   => $this->_object_id,
			'objectType' => $this->_object_type,
			'iconUrl'    => plugin_dir_url( __FILE__ ) . 'assets/icon-lock-48w.png',
			'strings'    => $strings,
		);

		wp_localize_script( 'wp-page-locking', 'wpLockingVars', $vars );
	}

	/*
	 * For list
	public function enqueue_list_scripts() {

		wp_enqueue_script( 'gforms_locking_list' );
		wp_enqueue_style( 'gforms_locking_list_css' );

		$vars = array(
			'objectType' => $this->_object_type,
		);

		wp_localize_script( 'gforms_locking_list', 'wpLockingVars', $vars );

	}
	*/

	protected function get_strings() {
		$strings = array(
			'currently_locked'  => esc_html__( 'This page is currently locked. Click on the <strong>"Request Control"</strong> button to let <strong>%s</strong> know you\'d like to take over.', 'wpl' ),
			'accept'            => esc_html__( 'Accept', 'wpl' ),
			'cancel'            => esc_html__( 'Cancel', 'wpl' ),
			'currently_editing' => esc_html__( '<strong>%s</strong> is currently editing', 'wpl' ),
			'taken_over'        => esc_html__( '<strong>%s</strong> has taken over and is currently editing.', 'wpl' ),
			'lock_requested'    => esc_html__( '<strong>%s</strong> has requested permission to take over control.', 'wpl' ),
			'gained_control'    => esc_html__( 'You now have control', 'wpl' ),
			'request_pending'   => esc_html__( 'Pending', 'wpl' ),
			'no_response'       => esc_html__( 'No response', 'wpl' ),
			'request_again'     => esc_html__( 'Request again', 'wpl' ),
			'request_error'     => esc_html__( 'Error', 'wpl' ),
			'request_rejected'  => esc_html__( 'Your request was rejected', 'wpl' ),
		);

		return $strings;
	}

	public function ajax_lock_request() {
		$object_id = isset( $_GET['object_id'] ) ? absint($_GET['object_id']) : '';

		$response  = $this->request_lock( $object_id );
        $this->_send_AJAX($response);
	}

	public function ajax_reject_lock_request() {
		$object_id = isset( $_GET['object_id'] ) ? absint($_GET['object_id']) : '';
		$response  = $this->_delete_lock_request_meta( $object_id );
        $this->_send_AJAX($response);
	}

	protected function has_lock() {
		return $this->check_lock( $this->get_object_id() ) ? true : false;
	}


	protected function check_lock( $object_id ) {

        $lock_meta = $this->_get_lock_meta( $object_id );
		if ( empty($lock_meta) || !isset($lock_meta['user_id']) || !isset($lock_meta['user_ip']) ) {
			return false;
		}

		if ( $lock_meta['user_id'] != get_current_user_id() || $lock_meta['user_ip'] != $this->_get_user_ip()  ) {
			return $lock_meta['user_id'];
		}

		return false;
	}

	protected function check_lock_request( $object_id ) {

        $lock_meta = $this->_get_lock_request_meta( $object_id );
        if ( empty($lock_meta) || !isset($lock_meta['user_id']) || !isset($lock_meta['user_ip']) ) {
            return false;
        }

        if ( $lock_meta['user_id'] != get_current_user_id() || $lock_meta['user_ip'] != $this->_get_user_ip()  ) {
            return $lock_meta['user_id'];
        }

		return false;
	}

	protected function set_lock( $object_id ) {
	    // TODO - may be some security checks??
		/*if ( ! GFCommon::current_user_can_any( $this->_capabilities ) ) {
			return false;
		}*/

		if ( 0 == ( $user_id = get_current_user_id() ) ) {
			return false;
		}

		$this->_update_lock_meta( $object_id, $user_id );

		return $user_id;
	}

	protected function request_lock( $object_id ) {
		if ( 0 == ( $user_id = get_current_user_id() ) ) {
			return false;
		}

		$lock_holder_user_id = $this->check_lock( $object_id );

		$result = array();
		if ( ! $lock_holder_user_id ) {
			$this->set_lock( $object_id );
			$result['html']   = __( 'You now have control', 'wpl' );
			$result['status'] = 'lock_obtained';
		} else {
			$user = get_userdata( $lock_holder_user_id );
			$this->_update_lock_request_meta( $object_id, $user_id );
			$result['html']   = sprintf( __( 'Your request has been sent to %s.', 'wpl' ), $user->display_name );
			$result['status'] = 'lock_requested';
		}

		return $result;
	}

	protected function _get_lock_request_meta($object_id ) {
        //return (false == ($lock_meta = get_transient( self::PREFIX_EDIT_LOCK_REQUEST . $this->_object_type . '_' . $object_id ))) ? array() : $lock_meta;
		return get_transient( self::PREFIX_EDIT_LOCK_REQUEST . $this->_object_type . '_' . $object_id );
	}

	protected function _get_lock_meta($object_id ) {
		return get_transient( self::PREFIX_EDIT_LOCK . $this->_object_type . '_' . $object_id );
	}

	protected function _update_lock_meta($object_id, $lock_value ) {
        $lock_value_arr = array('user_ip'=>$this->_get_user_ip(), 'user_id'=>$lock_value);
        set_transient( self::PREFIX_EDIT_LOCK . $this->_object_type . '_' . $object_id, $lock_value_arr, 130 );
	}

	protected function _update_lock_request_meta($object_id, $lock_request_value ) {
        $lock_request_value_arr = array('user_ip'=>$this->_get_user_ip(), 'user_id'=>$lock_request_value);
        set_transient( self::PREFIX_EDIT_LOCK_REQUEST . $this->_object_type . '_' . $object_id, $lock_request_value_arr, 65 );
	}

	protected function _delete_lock_request_meta($object_id ) {
		delete_transient( self::PREFIX_EDIT_LOCK_REQUEST . $this->_object_type . '_' . $object_id );

		return true;
	}

	protected function _delete_lock_meta($object_id ) {
		delete_transient( self::PREFIX_EDIT_LOCK . $this->_object_type . '_' . $object_id );

		return true;
	}

	public function maybe_lock_object( $is_edit_page ) {
		if ( isset( $_GET['get-edit-lock'] ) ) {
			$this->set_lock( $this->_object_id );
			wp_safe_redirect( $this->_edit_url );
			exit();
		} else if ( isset( $_GET['release-edit-lock'] ) ) {
			$this->_delete_lock_meta( $this->_object_id );
			wp_safe_redirect( $this->_redirect_url );
			exit();
		} else {
			if ( $is_edit_page && ! $user_id = $this->check_lock( $this->_object_id ) ) {
				$this->set_lock( $this->_object_id );
			}
		}
	}


	public function heartbeat_refresh_lock( $response, $data, $screen_id ) {
		$heartbeat_key = 'wpl-form-refresh-lock';
		if ( isset($data[$heartbeat_key]) && $data[$heartbeat_key]['objectType'] == $this->_object_type ) {
			$received = $data[ $heartbeat_key ];
			$send     = array();

			if ( ! isset( $received['objectID'] ) ) {
				return $response;
			}

            $object_id = absint($received['objectID']);

			if ( ( $user_id = $this->check_lock( $object_id ) ) && ( $user = get_userdata( $user_id ) ) ) {
				$error = array(
					'text' => sprintf( __( $this->get_string( 'taken_over' ) ), $user->display_name )
				);

				if ( $avatar = get_avatar( $user->ID, 64 ) ) {
					if ( preg_match( "|src='([^']+)'|", $avatar, $matches ) ) {
						$error['avatar_src'] = $matches[1];
					}
				}

				$send['lock_error'] = $error;
			} else {
				if ( $new_lock = $this->set_lock( $object_id ) ) {
					$send['new_lock'] = $new_lock;

					if ( ( $lock_requester = $this->check_lock_request( $object_id ) ) && ( $user = get_userdata( $lock_requester ) ) ) {
						$lock_request = array(
							'text' => sprintf( __( $this->get_string( 'lock_requested' ) ), $user->display_name )
						);

						if ( $avatar = get_avatar( $user->ID, 64 ) ) {
							if ( preg_match( "|src='([^']+)'|", $avatar, $matches ) ) {
								$lock_request['avatar_src'] = $matches[1];
							}
						}
						$send['lock_request'] = $lock_request;
					}
				}
			}

			$response[ $heartbeat_key ] = $send;
		}

		return $response;
	}

	public function heartbeat_request_lock( $response, $data, $screen_id ) {
		$heartbeat_key = 'wpl-form-request-lock';
		if ( isset($data[$heartbeat_key]) && $data[$heartbeat_key]['objectType'] == $this->_object_type ) {
			$received = $data[ $heartbeat_key ];
			$send     = array();

			if ( ! isset( $received['objectID'] ) ) {
				return $response;
			}

			$object_id = absint($received['objectID']);

			if ( ( $user_id = $this->check_lock( $object_id ) ) && ( $user = get_userdata( $user_id ) ) ) {
				if ( $this->_get_lock_request_meta( $object_id ) ) {
					$send['status'] = 'pending';
				} else {
					$send['status'] = 'deleted';
				}
			} else {
				if ( $new_lock = $this->set_lock( $object_id ) ) {
					$send['status'] = 'granted';
				}
			}

			$response[ $heartbeat_key ] = $send;
		}

		return $response;
	}

    /*
     * For List
     *
	public function heartbeat_check_locked_objects( $response, $data, $screen_id ) {
		$checked       = array();
		$heartbeat_key = 'wpl-form-check-locked-objects';
		if ( isset($data[$heartbeat_key]) && $data[$heartbeat_key]['objectType'] == $this->_object_type ) {
			foreach ( $data[ $heartbeat_key ] as $object_id ) {
				if ( ( $user_id = $this->check_lock( $object_id ) ) && ( $user = get_userdata( $user_id ) ) ) {
					$send = array( 'text' => sprintf( __( $this->get_string( 'currently_editing' ) ), $user->display_name ) );

					if ( ( $avatar = get_avatar( $user->ID, 18 ) ) && preg_match( "|src='([^']+)'|", $avatar, $matches ) ) {
						$send['avatar_src'] = $matches[1];
					}

					$checked[ $object_id ] = $send;
				}
			}
		}

		if ( ! empty( $checked ) ) {
			$response[ $heartbeat_key ] = $checked;
		}

		return $response;
	}
    */
	public function get_lock_ui( $user_id ) {

		$user = get_userdata( $user_id );

		$locked = $user_id && $user;

		$edit_url = $this->_edit_url;

		$hidden = $locked ? '' : ' hidden';
		if ( $locked ) {

			$message = '<div class="wpl-form-locked-message">
                            <div class="wpl-form-locked-avatar">' . get_avatar( $user->ID, 64 ) . '</div>
                            <p class="currently-editing" tabindex="0">' . sprintf( $this->get_string( 'currently_locked' ), $user->display_name ) . '</p>
                            <p class="wpl-form-actions">

                                <a id="wpl-form-take-over-button" style="display:none" class="button button-primary wp-tab-first" href="' . esc_url( add_query_arg( 'get-edit-lock', '1', $edit_url ) ) . '"><span class="dashicons dashicons-unlock"></span> ' . __( '>> Take Over <<', 'wpl' ) . '</a>
                                <button id="wpl-form-lock-request-button" class="button button-primary wp-tab-last"><span class="dashicons dashicons-lock"></span> ' . __( 'Request Control', 'wpl' ) . '</button>
                                <a class="button" href="' . esc_url( $this->_redirect_url ) . '">' . $this->get_string( 'cancel' ) . '</a>
                            </p>
                            <div id="wpl-form-lock-request-status">
                                <!-- placeholder -->
                            </div>
                        </div>';

		} else {

			$message = '<div class="wpl-form-taken-over">
                            <div class="wpl-form-locked-avatar"></div>
                            <p class="wp-tab-first" tabindex="0">
                                <span class="currently-editing"></span><br>
                            </p>
                            <p class="wpl-form-actions">
                                <a id="wpl-form-release-lock-button" class="button button-primary wp-tab-last"  href="' . esc_url( add_query_arg( 'release-edit-lock', '1', $edit_url ) ) . '"><span class="dashicons dashicons-yes"></span> ' . $this->get_string( 'accept' ) . '</a>
                                <button id="wpl-form-reject-lock-request-button" style="display:none"  class="button button-primary wp-tab-last"><span class="dashicons dashicons-no-alt"></span> ' . __( 'Reject Request', 'wpl' ) . '</button>
                            </p>
                        </div>';

		}
		$html = '<div id="wpl-form-lock-dialog" class="notification-dialog-wrap' . $hidden . '">
                    <div class="notification-dialog-background"></div>
                    <div class="notification-dialog">';
		$html .= $message;

		$html .= '   </div>
                 </div>';

		return $html;
	}

	public function get_string( $string_key ) {
		$strings = $this->get_strings();

		return isset($strings[$string_key]) ? $strings[$string_key] : '';
	}

	// helper functions for the list page

	public function list_row_class( $object_id, $echo = true ) {
		$locked_class = $this->is_locked( $object_id ) ? 'wp-locked' : '';
		$classes      = ' gf-locking ' . $locked_class;
		if ( $echo ) {
			echo $classes;
		}

		return $classes;
	}

	public function is_locked( $object_id ) {
		if ( ! $user_id = get_transient( self::PREFIX_EDIT_LOCK . $this->_object_type . '_' . $object_id ) ) {
			return false;
		}

		if ( $user_id != get_current_user_id() ) {
			return true;
		}

		return false;
	}

	public function lock_indicator( $echo = true ) {

		$lock_indicator = '<div class="locked-indicator"></div>';

		if ( $echo ) {
			echo $lock_indicator;
		}

		return $lock_indicator;
	}

	public function lock_info( $object_id, $echo = true ) {
		$user_id = $this->check_lock( $object_id );

		if ( ! $user_id ) {
			return '';
		}

		if ( $user_id && $user = get_userdata( $user_id ) ) {
			$locked_avatar = get_avatar( $user->ID, 18 );
			$locked_text   = esc_html( sprintf( $this->get_string( 'currently_editing' ), $user->display_name ) );
		} else {
			$locked_avatar = $locked_text = '';
		}

		$locked_info = '<div class="locked-info"><span class="locked-avatar">' . $locked_avatar . '</span> <span class="locked-text">' . $locked_text . "</span></div>\n";

		if ( $echo ) {
			echo $locked_info;
		}

		return $locked_info;
	}

	protected function is_page( $page_name ) {
        $curr_page = '';
        if ( isset($_GET['page']) ) {
            $curr_page = $_GET['page'];
        }
        //get_current_screen()->id;
		return $page_name == $curr_page;
	}

    protected function _send_AJAX($data) {
        die('<!--WPL_START-->' . json_encode($data) . '<!--WPL_END-->');
    }

    protected function _get_user_ip() {
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (isset($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = $_SERVER['HTTP_X_REAL_IP'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return $ip;
    }

}
