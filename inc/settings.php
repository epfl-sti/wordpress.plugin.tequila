<?php

namespace EPFL;

if (! defined('ABSPATH')) {
    die('Access denied.');
}


/**
 * A base class for settings.
 *
 * Uses the settings API rather than writing our own <form>s and validators
 * therefor.
 *
 * Encapsulates the slug and other batons to pass to various functions
 * of the WordPress API, as well as WordPress persistence and the
 * is_plugin_active_for_network() quirks thereof.
 *
 * Subclasses must define:
 *
 *     const SLUG
 *
 *     function hook()
 *        -> but should not forget to call parent::hook()
 *
 * Subclasses may define:
 *
 *     function validate_settings ( $settings )
 *        -> should return a sanitized copy of $settings
 *        -> might call $this->add_settings_error()
 *
 * @see https://wordpress.stackexchange.com/questions/100023/settings-api-with-arrays-example
 */

if (! class_exists('EPFL\SettingsBase')):
class SettingsBase {
    public function hook()
    { 
        add_action('admin_init', array($this, 'register_setting'));
    }


    /************************ Data concerns ***********************/

    /**
     * @return The current settings as an associative array
     */
    public function get()
    {
        if ( $this->is_network_version() ) {
            return get_site_option( $this->network_option_name() );
        } else {
            return get_option( $this->option_name() );
        }
    }

    /**
     * Like get(), except merge / guard with $defaults
     *
     * Keys that exist in $default_values, but are missing in ->get()
     * are replaced with their value in $default_values. Conversely,
     * keys that exist in ->get(), but don't exist in $default_values
     * are discarded.
     */
    public function get_with_defaults ($default_values)
    {
        return shortcode_atts($default_values, $this->get());
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

    /****************** Settings page concerns *******************/

    /**
     * Standard rendering function for the settings form.
     *
     * Regurgitates every knob previously registered by add_settings_section(),
     * add_settings_field() etc.
     */
    function render()
    {
        $title = $GLOBALS['title'];
        echo("<div class=\"wrap\">
        <h2>$title</h2>
        <form action=\"options.php\" method=\"POST\">\n");
        settings_fields( $this->option_group() );
        do_settings_sections( $this::SLUG );
        submit_button();
        echo "        </form>\n";
    }

    /**
     * Create markup like:
     * <input type="text" name="plugin:option_name[number]>"
     */
    function render_default_field_text ($args)
    {
        printf(
            '<input type="text" name="%1$s[%2$s]" id="%3$s" value="%4$s" class="regular-text">',
            $args['option_name'],
            $args['name'],
            $args['label_for'],
            $args['value']
        );
        if ($args['help']) {
            echo '<br />&nbsp;<i>' . $args['help'] . '</i>';
        }
    }

    function render_default_field_select ($args)
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

    function render_default_field_radio ($args)
    {
        foreach ($args['options'] as $val => $title) {
            printf(
                '<input type="radio" type="radio" name="%1$s[%2$s]" value="%3$s" %4$s/>%5$s</p>',
                $args['option_name'],
                $args['name'],
                $val,
                checked($val, $args['value'], false),
                $title
            );
        }
        print '</select>';
        if ($args['help']) {
            echo '<br />&nbsp;<i>' . $args['help'] . '</i>';
        }
    }

    /*************** "Teach WP OO" concerns **********************/

    function option_name()
    {
        return "plugin:" . $this::SLUG;
    }

    function network_option_name()
    {
        return "plugin:" . $this::SLUG . ":network";
    }

    function option_group()
    {
        return "plugin:" . $this::SLUG . ":group";
    }

    /**
     * Like WordPress' add_options_page(), only simpler
     *
     * Must be called exactly once in an overridden hook() method.
     *
     * - Ensure that add_options_page() is actually called in the admin_menu
     *   action, as it won't work from admin_init for some reason
     * - The render callback is always the "render" method
     */
    function add_options_page ($page_title, $menu_title, $capability)
    {
        $self = $this;
        add_action('admin_menu',
                   function() 
                       use ($self, $page_title, $menu_title, $capability) {
                       add_options_page(
                           $page_title, $menu_title, $capability,
                           $this::SLUG,
                           array($self, 'render'));
                   });
    }

    /**
     * Like WordPress' add_settings_section, only simpler
     *
     * @param $key The section name; recommended: make it start with 'section_'
     *             The render callback is simply the method named "render_$key"
     * @param $title The title
     */
    function add_settings_section ($key, $title)
    {
        add_settings_section(
            $key,                                       // ID
            $title,                                     // Title
            array($this, 'render_' . $key),             // print output
            $this::SLUG                                 // menu slug, see action_admin_menu()
        );
    }

    /**
     * Like WordPress' add_settings_field, only simpler
     *
     * @param $parent_section_key The parent section's key (i.e., the value
     #        that was passed as $key to ->add_settings_section())
     * @param $key The field name. Recommended: make it start with 'field_'
     * @param $title The title
     * @param options Like the last argument to WordPress'
     *        add_settings_field(), except 'value' need not be escaped and
     *        'option_name' will be automatically provided
     *
     * The render callback is the method named "render_$key", if it exists,
     * or "render_default_field_$options['type']"
     */
    function add_settings_field ($parent_section_key, $key, $title, $options)
    {
        if (array_key_exists('value', $options)) {
            $options['value'] = esc_attr($options['value']);
        }
        if (! array_key_exists('option_name', $options)) {
            $options['option_name'] = $this->option_name();
        }

        if (array_key_exists('type', $options) &&
            !method_exists($this, "render_$key")) {
            $render_method = "render_default_field_" . $options["type"];
        } else {
            $render_method = "render_$key";
        }
        add_settings_field(
            $key,                                       // ID
            $title,                                     // Title
            array($this, $render_method),               // print output
            $this::SLUG,                                // menu slug, see action_admin_menu()
            $parent_section_key,                        // parent section
            $options
        );
    }

    /**
     * Like WordPress' register_setting, only simpler
     */
    function register_setting()
    {
        register_setting(
            $this->option_group(),
            $this->option_name(),
            array($this, 'validate_settings')           // validation callback - Need not exist
        );
    }

    /**
     * Like WordPress' add_settings_error, only simpler
     */
    function add_settings_error( $key, $msg )
    {           
        add_settings_error(
            $this->option_group(),
            'number-too-low',
            'Number must be between 1 and 1000.'
        );
    }
}
endif;
