<?php
/*
 * Plugin Name: EPFL Tequila
 * Description: Authenticate to WordPress with Tequila
 * Version:     0.16 (vpsi)
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
    var $use_test_tequila = false;
    var $is_debug_enabled = false;
    var $allowedrequesthosts = null;

    function debug ($msg)
    {
        if ($this->is_debug_enabled) {
            error_log("Tequila: ".$msg);
        }
    }


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

        // This is not (yet) tweakable via UI.
        $this->allowedrequesthosts = get_option('plugin:epfl:tequila_allowed_request_hosts', null);
    }

    public function hook()
    {
        add_action('init', array($this, 'maybe_back_from_tequila'));
        add_action('init', array($this, 'setup_tequila_auth'));
        $this->settings->hook();
    }

    function setup_tequila_auth()
    {
        $this->debug("-> setup_tequila_auth");
        if ($this->settings->get("has_dual_auth")) {
            $this->debug("setup_tequila_auth with dual auth");
            add_action('login_form', array($this, 'render_tequila_login_button'));
            add_action('login_init', array( $this, 'redirect_tequila_if_button_clicked' ));
        } else {
            $this->debug("setup_tequila_auth with redirect");
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

        $this->debug("-> do_redirect_tequila");
        $client = $this->get_tequila_client();
        $client->SetApplicationName(sprintf(___('Administration WordPress &mdash; %1$s'), get_bloginfo('name')));
        $client->SetWantedAttributes(array( 'name',
                                            'firstname',
                                            'group',
                                            'droit-WordPress.Admin',
                                            'droit-WordPress.Editor',
                                            'displayname',
                                            'username',
                                            'personaltitle',
                                            'email',
                                            'title', 'title-en',
                                            'uniqueid'));

        /* Getting current URL to be redirected on it after we come back from Tequila*/
        $redirect_to = (empty($_GET['redirect_to']))? home_url(): $_GET['redirect_to'];

        $this->debug("Redirect to Tequila auth. Origin URL = ".$redirect_to);

        $client->Authenticate(admin_url("?back-from-Tequila=1&redirect_to=".urlencode($redirect_to)));
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
        $this->debug("-> maybe_back_from_tequila");
        if (!array_key_exists('back-from-Tequila', $_REQUEST)) {
            $this->debug("Not back from tequila in fact");
            return;
        }

        $params = array("key" => $_GET["key"]);
        if ($this->allowedrequesthosts !== NULL) {
            $params["allowedrequesthosts"]=$this->allowedrequesthosts;
        }
        $tequila_data = $this->get_tequila_client()->fetchAttributes($params);

        $this->debug("Tequila data:\n". var_export($tequila_data, true));
        $user = $this->fetch_user($tequila_data);

        if ($user) {
            wp_set_auth_cookie($user->ID, true);
            /* Recovering redirect URL */
            $redirect_to = (empty($_GET['redirect_to']))? home_url(): $_GET['redirect_to'];

            $this->debug("Redirect to: ". $redirect_to);

            wp_redirect($redirect_to);
            exit;
        } else {
            http_response_code(404);
            // TODO: perhaps we can tell the user to fly a kite in a
            // more beautiful way
            echo ___('Cet utilisateur est inconnu');
            die();
        }
    }

    function fetch_user($tequila_data)
    {
        $this->debug("-> fetch_user");
        /**
         * Create or update this Tequila user in the WordPress database.
         *
         * Called by EPFL Tequila upon a successful login.
         *
         * IF EPFL Tequila is used alone, this hook is not in use and
         * only users that already exist in the database can log in
         * (with whatever privilege level they already have). Plug-ins
         * such as EPFL Accred may define a custom action to create
         * new users in the database, and set up or update their
         * personal information and access rights.
         *
         * @param array $tequila_data The data received from Tequila
         */
        do_action("tequila_save_user", $tequila_data);
        $user = get_user_by("login", $tequila_data["uniqueid"]);

        /* ----------------------------------------------------------------------
            NOTE: maybe, on day, in a far future, this block of code can be removed because it is only here to
                  ensure backward compatibility with existing user accounts having incorrect name. Once everything
                  will be renamed to sciper, this code can be removed
        */

        /* If user is not found using SCIPER as login, */
        if($user === false)
        {
            /* We look for user using its SCIPER in 'nickname' meta field (because was stored here at the beginning) */
            $users = get_users(array('meta_key' => 'nickname',
                                     'meta_value' => $tequila_data['uniqueid']));

            /* We extract user info if we have a match */
            $user = (sizeof($users)==1)?$users[0]:false;
        }

        /* End of code to remove in the future.
        --------------------------------------------- */



        if (gettype($user) === "boolean" && $user === false) {
            $user = null;
        }
        return $user;
    }

    private function get_tequila_client ()
    {
        $client = new \TequilaClient();
        if ($this->use_test_tequila) {
            $client->isTest = true;
        }
        return $client;
    }
}

class Settings extends \EPFL\SettingsBase
{
    const SLUG = "epfl_tequila";
    var $is_configurable = true;

    function hook()
    {
        parent::hook();
        if (! $this->is_configurable) return;

        $this->register_setting('has_dual_auth', array(
            'type'    => 'boolean',
            'default' => true
        ));

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
        $this->add_settings_section('section_about', ___('À propos'));
        $this->add_settings_section('section_help', ___('Aide'));
        $this->add_settings_section('section_settings', ___('Réglages'));

        $this->add_settings_field(
            'section_settings', 'has_dual_auth', ___('Authentification traditionnelle Wordpress'),
            array(
                'type'        => 'radio',
                'options'     => array(
                    '1'           => ___('Activée'),
                    '0'           => ___('Désactivée')
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

if (file_exists(dirname(__FILE__) . "/site.php")) {
    require_once(dirname(__FILE__) . "/site.php");
}

Controller::getInstance()->hook();

