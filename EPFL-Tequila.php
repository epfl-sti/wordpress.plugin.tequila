<?php
/*
 * Plugin Name: EPFL Tequila
 * Description: Authenticate to WordPress with Tequila
 * Version:     0.1
 * Author:      Dominique Quatravaux
 * Author URI:  mailto:dominique.quatravaux@epfl.ch
 */

namespace EPFL\Tequila;

if (! defined('ABSPATH')) {
    die('Access denied.');
}

require_once(dirname(__FILE__) . "/inc/tequila_client.php");
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
        add_action('init', array($this, 'setup_tequila_auth'));
        $this->settings->hook();
    }

    function setup_tequila_auth()
    {
        if ("true" == $this->settings->get()["has_dual_auth"]) {
            add_action('login_form', array($this, 'render_tequila_login_button'));
            add_action('login_init', array( $this, 'redirect_tequila_if_button_clicked' ));
        } else {
            add_action('wp_authenticate', array( $this, 'do_redirect_tequila'));
            add_action('wp_logout', array( $this, 'logout_to_home_page' ));
        }
    }

    function redirect_tequila_if_button_clicked() {
        if (array_key_exists('redirect-tequila', $_REQUEST)) {
            $this->do_redirect_tequila();
        }
    }

    function do_redirect_tequila()
    {
        $client = new \TequilaClient();
        $client->SetApplicationName(sprintf(___('Administration WordPress — %1$s'), get_bloginfo('name')));
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

    /**
     * Log out to the site's home page.
     *
     * Called as the wp_logout action in case "dual auth" is disabled.
     *
     * Logging out to /wp-admin, as is the default behavior of
     * WordPress, would redirect to Tequila again and just log us
     * right back in (as per the "fast track" mode of Tequila, which
     * gave us a cookie and a logged-in state of its own).
     *
     * We don't want to fix that by logging out of Tequila as a whole;
     * rather, in this situation, navigate to the site's home page.
     * Administrators must somehow browse /wp-admin again on their own
     * to log back in.
     */
    function logout_to_home_page ()
    {
        header('Location: ' . home_url());
        exit;
    }

    function render_tequila_login_button() {
        ?>
        <p>
        <label for="login_tequila">
        <a href="?redirect-tequila=1" class="button button-primary button-large" style="background-color:darkred;"><?php echo ___("Se connecter avec Tequila...") ?></a>
        </label>
        </p>
        <?php
    }

    function maybe_back_from_tequila()
    {
        if (!array_key_exists('back-from-Tequila', $_REQUEST)) {
            return;
        }

        error_log("Back from Tequila with ". $_SERVER['QUERY_STRING'] . " !!");
        $client = new \TequilaClient();
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

