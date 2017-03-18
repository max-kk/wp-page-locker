<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    die('Access denied.');
}

/**
   * Let's include WP Locker class and set up pages, where it must work
*/

require_once 'wp-page-lococker.php';

/**
 * Create Lockers classes
 * @called by "admin_init"
 */
 // TODO - rename function
function my_wp_page_locker_init34 () {
    new TestSettingsLocking();
    new TestFormsLocking();
}

add_action('admin_init', 'my_wp_page_locker_init34');

/**
 * Class TestSettingsLocking
 * Lock "Plugin" => "Settings" page edit
 * URL: admin.php?page=my-settings
 */
class TestSettingsLocking extends WpPageLocking {
    public function __construct() {
        // Where to Redirect if page locked was edited by another user?
        $redirect_url = admin_url( 'admin.php?page=my-plugin' );
        $edit_url = admin_url( 'admin.php?page=my-settings' );

        parent::__construct( 'fv-settings', $redirect_url, $edit_url );
    }

    public function get_strings() {
        $strings = array(
            'currently_locked'  => __( 'These settings are currently locked. Click on the <strong>"Request Control"</strong> button to let <strong>%s</strong> know you\'d like to take over.', 'fv' ),
            'currently_editing' => '<strong>%s</strong> is currently editing these settings',
            'taken_over'        => '<strong>%s</strong> has taken over and is currently editing these settings.',
            'lock_requested'    => __( '<strong>%s</strong> has requested permission to take over control of these settings.', 'fv' )
        );

        return array_merge( parent::get_strings(), $strings );
    }

    protected function is_edit_page() {
        // Check Page slug
        return $this->is_page( 'my-settings' );
    }

    /**
     * @return int
     */
    protected function get_object_id() {
        return 0;
    }

}

/**
 * Class TestFormsLocking
 * Lock "Plugin" => "Forms" edit
 * URL: admin.php?page=my-formbuilder&form=%d
 */
class TestFormsLocking extends WpPageLocking {
    public function __construct() {
        $redirect_url = admin_url( 'admin.php?page=my-formbuilder' );
        $form_id      = $this->get_object_id();
        $edit_url     = admin_url( sprintf( 'admin.php?page=my-formbuilder&form=%d', $form_id ) );

        parent::__construct( 'my-formbuilder', $redirect_url, $edit_url );
    }

    public function get_strings() {
        $strings = array(
            'currently_locked'  => __( 'These form is currently locked. Click on the <strong>"Request Control"</strong> button to let <strong>%s</strong> know you\'d like to take over.', 'fv' ),
            'currently_editing' => '<strong>%s</strong> is currently editing these form',
            'taken_over'        => '<strong>%s</strong> has taken over and is currently editing these form.',
            'lock_requested'    => __( '<strong>%s</strong> has requested permission to take over control of these form.', 'fv' )
        );

        return array_merge( parent::get_strings(), $strings );
    }

    protected function is_edit_page() {
        return $this->is_page( 'my-formbuilder' ) && isset($_GET['form']);
    }

    // Required
    // In case multiply locking objects we need determine it's ID
    protected function get_object_id() {
        return isset($_GET['form']) ? absint($_GET['form']) : 0;
    }

}