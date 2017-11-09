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
require_once(dirname(__FILE__) . "/inc/settings.php");


function ___($text)
{
    return __($text, "epfl-tequila");
}

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

    public function __construct ()
    {
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
        $client->SetApplicationName(___('Administration WordPress — ') . get_bloginfo('name'));
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
            echo ___('Cet utilisateur est inconnu');
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

class Settings extends \EPFL\SettingsBase
{
    const SLUG = "epfl_tequila";

    function hook()
    {
        parent::hook();
        $this->add_options_page(
            ___('Réglages de Tequila'),                 // $page_title,
            ___('Tequila (auth)'),                      // $menu_title,
            'manage_options');                          // $capability
        add_action('admin_init', array($this, 'setup_options_page'));
    }

    /**
     * Prepare the admin menu with settings and their values
     */
    function setup_options_page()
    {
        $data = $this->get(array(  // Default values
            'groups'    => 'stiitweb',
            'faculty'   => 'STI'
        ));

        $this->add_settings_section('section_about', ___('À propos'));
        $this->add_settings_section('section_help', ___('Aide'));
        $this->add_settings_section('section_parameters', ___('Paramètres'));

        $this->add_settings_field(
            'section_parameters', 'field_school', ___('Faculté'),
            array(
                'label_for'   => 'faculty', // makes the field name clickable,
                'name'        => 'faculty', // value for 'name' attribute
                'value'       => $data['faculty'],
                'options'     => array(
                    'ENAC'      => 'Architecture, Civil and Environmental Engineering — ENAC',
                    'SB'        => 'Basic Sciences — SB',
                    'STI'       => 'Engineering — STI',
                    'IC'        => 'Computer and Communication Sciences — IC',
                    'SV'        => 'Life Sciences — SV',
                    'CDM'       => 'Management of Technology — CDM',
                    'CDH'       => 'College of Humanities — CDH'
                ),
                'help' => 'Permet de sélectionner les accès par défaut (droit wordpress.faculté).'
            )
        );

        $this->add_settings_field(
            'section_parameters', 'field_admin_groups', ___('Groupes administrateur'),
            array(
                'label_for'   => 'groups',
                'name'        => 'groups',
                'value'       => $data['groups'],
                'help' => 'Groupe permettant l’accès administrateur.'
            )
        );
    }

    function validate_settings( $settings )
    {
        if (false) {
            $this->add_settings_error(
                'number-too-low',
                ___('Number must be between 1 and 1000.')
            );
        }
        return $settings;
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

    function render_field_admin_groups($args)
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

    function render_field_school($args)
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
}

Controller::getInstance()->hook();

