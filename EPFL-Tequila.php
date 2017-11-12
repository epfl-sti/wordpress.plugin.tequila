<?php
/*
 * Plugin Name: EPFL Tequila
 * Description: Authenticate with Tequila in the admin page
 * Version:     0.0.3
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
        $data = $this->get_with_defaults(array(
            'has_dual_auth'    => 'true'
        ));

        $this->add_settings_section('section_about', ___('À propos'));
        $this->add_settings_section('section_help', ___('Aide'));
        $this->add_settings_section('section_settings', ___('Réglages'));

        $this->add_settings_field(
            'section_settings', 'field_dual_auth', ___('Authentification traditionnelle Wordpress'),
            array(
                'type'        => 'radio',
                'label_for'   => 'has_dual_auth', // makes the field name clickable,
                'name'        => 'has_dual_auth', // value for 'name' attribute
                'value'       => $data['has_dual_auth'],
                'options'     => array(
                    'true'       => ___('Activée'),
                    'false'      => ___('Désactivée')
                ),
                'help' => ___('Le réglage «Activée» permet d\'utiliser simultanément Tequila et l\'authentification par nom / mot de passe livrée avec WordPress')
            )
        );
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

    function render_section_settings()
    {
        // Nothing — The fields in this section speak for themselves
    }
}

Controller::getInstance()->hook();

