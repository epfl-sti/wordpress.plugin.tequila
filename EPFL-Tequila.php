<?php
/*
 * Plugin Name: EPFL Tequila
 * Description: Authenticate with Tequila in the admin page
 * Version:     0.1
 * Author:      Dominique Quatravaux
 * Author URI:  mailto:dominique.quatravaux@epfl.ch
 */

namespace EPFL\Tequila;

if (! defined('ABSPATH')) {
    die('Access denied.');
}

require_once(dirname(__FILE__) . "/tequila_client.php");

class Controller
{
    static $instance = false;
    var $settings = null;

    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self;
        }
        return self::$instance;
    }

    public function __construct () {
        $this->settings = new Settings();
    }

    public function hook()
    {
        add_action('init', array($this, 'maybe_back_from_tequila'));
        add_action('wp_authenticate', array( $this, 'start_authentication' ));
        $this->settings->hook();
    }

    function start_authentication()
    {
        $client = new TequilaClient();
        $client->SetApplicationName(__('Administration WordPress — ', 'epfl-tequila') . get_bloginfo('name'));
        $client->SetWantedAttributes(array( 'name',
                                            'firstname',
                                            'displayname',
                                            'username',
                                            'personaltitle',
                                            'email',
                                            'title', 'title-en',
                                            'uniqueid'));
        $client->Authenticate(admin_url("?back-from-Tequila=1"));
    }

    function maybe_back_from_tequila()
    {
        if (! $_REQUEST['back-from-Tequila']) {
            return;
        }

        error_log("Back from Tequila with ". $_SERVER['QUERY_STRING'] . " !!");
        $client = new TequilaClient();
        $tequila_data = $client->fetchAttributes($_GET["key"]);
        $user = $this->update_user($tequila_data);
        if ($user) {
            wp_set_auth_cookie($user->ID, true);
            wp_redirect(admin_url());
            exit;
        } else {
            http_response_code(404);
            // TODO: perhaps we can tell the user to fly a kite in a
            // more beautiful way
            echo __('Cet utilisateur est inconnu', 'epfl-tequila');
            die();
        }
    }

    function update_user($tequila_data)
    {
        // TODO: improve a lot!
        // * Automatically update all supplementary fields (e.g. email addres)
        //   from the authoritative Tequila response
        // * Depending on some policy setting, maybe auto-update user
        //   privileges
        return get_user_by("login", $tequila_data["username"]);
    }
}

Controller::getInstance()->hook();

class Settings
{
    function hook()
    { 
        add_action('admin_init', array($this, 'action_admin_init'));
        add_action('admin_menu', array($this, 'action_admin_menu'));
    }

    public function get($name, $default = false, $use_cache = true)
    {
        if ( $this->is_network_version() ) {
            return get_site_option($name, $default, $use_cache);
        } else {
            return get_option($name, $default);
        }
    }

    function get_option($name, $default = false, $use_cache = true)
    {
        if ($this->is_network_version()) {
            return get_site_option($name, $default, $use_cache);
        } else {
            return get_option($name, $default);
        }
    }

    /**
     * @returns Whether this plugin is currently network activated
     */
    var $_is_network_version = null;
    function is_network_version()
    {
        if ($this->_is_network_version === null) {
            if (! function_exists('is_plugin_active_for_network')) {
                require_once(ABSPATH . '/wp-admin/includes/plugin.php');
            }

            $this->_is_network_version = (bool) is_plugin_active_for_network(plugin_basename(__FILE__));
        }

        return $this->_is_network_version;
    }

    const SLUG = "epfl_tequila";
    const OPTION_GROUP = "plugin:epfl-tequila-optiongroup";

    function action_admin_init()
    {
        // Use the settings API rather than writing our own <form>s and
        // validators therefor.
        // More at https://wordpress.stackexchange.com/a/100137
        $option_name   = 'plugin:epfl-tequila';

        // Fetch existing options.
        $option_values = $this->get( $option_name );

        $default_values = array(
            'groups'    => 'stiitweb',
            'faculty'   => 'STI',
            'long'      => ''
        );

        // Parse option values into predefined keys, throw the rest away.
        $data = shortcode_atts($default_values, $option_values);

        register_setting(
            $this::OPTION_GROUP,                        // group, used for settings_fields()
            $option_name,                               // option name, used as key in database
            array($this, 'validate_settings')           // validation callback
        );

        /* No argument has any relation to the prvious register_setting(). */
        add_settings_section(
            'section_1',                                // ID
            'A propos',                                 // Title
            array($this, 'render_section_about'),       // print output
            $this::SLUG                                 // menu slug, see action_admin_menu()
        );

        add_settings_section(
            'section_2',
            'Aide',
            array($this, 'render_section_help'),
            $this::SLUG
        );

        add_settings_section(
            'section_3',
            'Paramètres',
            array($this, 'render_section_parameters'),
            $this::SLUG
        );

        add_settings_field(
            'section_3_field_1',                        // ID
            'Faculté',                                  // Title
            array($this, 'render_dropdown'),            // print output
            $this::SLUG,                                // menu slug, see action_admin_menu()
            'section_3',                                // parent section
            array(
                'label_for'   => 'faculty', // makes the field name clickable,
                'name'        => 'faculty', // value for 'name' attribute
                'value'       => esc_attr($data['faculty']),
                'options'     => array(
                    'ENAC'      => 'Architecture, Civil and Environmental Engineering — ENAC',
                    'SB'        => 'Basic Sciences — SB',
                    'STI'       => 'Engineering — STI',
                    'IC'        => 'Computer and Communication Sciences — IC',
                    'SV'        => 'Life Sciences — SV',
                    'CDM'       => 'Management of Technology — CDM',
                    'CDH'       => 'College of Humanities — CDH'
                ),
                'option_name' => $option_name,
                'help' => 'Permet de sélectionner les accès par défaut (droit wordpress.faculté).'
            )
        );

        add_settings_field(
            'section_3_field_2',
            'Groupes administrateur',
            array($this, 'render_input'),
            $this::SLUG,
            'section_3',
            array(
                'label_for'   => 'groups',
                'name'        => 'groups',
                'value'       => esc_attr($data['groups']),
                'option_name' => $option_name,
                'help' => 'Groupe permettant l’accès administrateur.'
            )
        );
    }

    /**
     * Spit out every knob previously registered with action_admin_init()
     *
     * @see https://wordpress.stackexchange.com/questions/100023/settings-api-with-arrays-example
     */
    function action_admin_menu()
    {
        add_options_page(
            __('Réglages de Tequila', 'epfl-tequila'),  // $page_title,
            __('Tequila (auth)', 'epfl-tequila'),       // $menu_title,
            'manage_options',                           // $capability,
            $this::SLUG,                                // $menu_slug
            array($this, 'render')                      // Callback
        );
    }

    function render()
    {
        $title = $GLOBALS['title'];
        echo("<div class=\"wrap\">
        <h2>$title</h2>
        <form action=\"options.php\" method=\"POST\">\n");
        settings_fields( $this::OPTION_GROUP );
        do_settings_sections( $this::SLUG );
        submit_button();
        echo "        </form>\n";
    }


    function validate_settings()
    {
        if (false) {
            add_settings_error(
                $this::OPTION_GROUP,
                'number-too-low',
                'Number must be between 1 and 1000.'
            );
        }
    }

    function render_section_about()
    {
        echo __('<p><a href="https://github.com/epfl-sti/wordpress.plugin.tequila">EPFL-tequila</a>
    permet l’utilisation de <a href="https://tequila.epfl.ch/">Tequila</a>
    (Tequila est un système fédéré de gestion d’identité. Il fournit les moyens
    d’authentifier des personnes dans un réseau d’organisations) avec
    WordPress.</p>', 'epfl-tequila');
    }

    function render_section_help()
    {
        echo __('<p>En cas de problème avec EPFL-tequila veuillez créer une
    <a href="https://github.com/epfl-sti/wordpress.plugin.tequila/issues/new"
    target="_blank">issue</a> sur le dépôt
    <a href="https://github.com/epfl-sti/wordpress.plugin.tequila/issues">
    GitHub</a>.</p>', 'epfl-tequila');
    }

    function render_section_parameters()
    {
        // Nothing — The fields in this section speak for themselves
    }

    function render_input($args)
    {
        /* Creates this markup:
           /* <input name="plugin:option_name[number]"
        */
        printf(
            '<input name="%1$s[%2$s]" id="%3$s" value="%4$s" class="regular-text">',
            $args['option_name'],
            $args['name'],
            $args['label_for'],
            $args['value']
        );
        if ($args['help']) {
            echo '<br />&nbsp;<i>' . $args['help'] . '</i>';
        }
    }

    function render_dropdown($args)
    {
        printf(
            '<select name="%1$s[%2$s]" id="%3$s">',
            $args['option_name'],
            $args['name'],
            $args['label_for']
        );

        foreach ($args['options'] as $val => $title) {
            printf(
                '<option value="%1$s" %2$s>%3$s</option>',
                $val,
                selected($val, $args['value'], false),
                $title
            );
        }
        print '</select>';
        if ($args['help']) {
            echo '<br />&nbsp;<i>' . $args['help'] . '</i>';
        }
    }

    function render_textarea($args)
    {
        printf(
            '<textarea name="%1$s[%2$s]" id="%3$s" rows="10" cols="30" class="code">%4$s</textarea>',
            $args['option_name'],
            $args['name'],
            $args['label_for'],
            $args['value']
        );
        if ($args['help']) {
            echo '<br />&nbsp;<i>' . $args['help'] . '</i>';
        }
    }
}
