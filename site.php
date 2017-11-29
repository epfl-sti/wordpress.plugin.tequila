<?php

/* Copy and modify this file to site.php to tweak this plugin for your site. */

namespace EPFL\Tequila;

if (! defined('ABSPATH')) {
    die('Access denied.');
}

// Controller::getInstance()->is_debug_enabled = true;

/* Uncomment to do what it says on the tin. */
// Controller::getInstance()->use_test_tequila = true;

/* Uncomment this to lock down the admin page for this plugin completely. */
// Configuration is still feasible using the CLI.

/* Uncomment to enable "VPSI lockdown mode" */
// Controller::getInstance()->settings->is_configurable = false;
