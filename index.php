<?php
/**
 * UniFi API browser
 *
 * This tool is for browsing data that is exposed through Ubiquiti's UniFi Controller API,
 * and is developed with PHP, JavaScript and the Bootstrap CSS framework.
 *
 * Please keep the following in mind:
 * - not all data collections/API endpoints are supported (yet), see the list of
 *   the currently supported data collections/API endpoints in the README.md file
 * - this tool currently supports versions 4.x and 5.x of the UniFi Controller software
 * ------------------------------------------------------------------------------------
 *
 * Copyright (c) 2017, Art of WiFi
 *
 * This source file is subject to the MIT license that is bundled
 * with this package in the file LICENSE.md
 *
 */
define('API_BROWSER_VERSION', '1.0.36');
define('API_CLASS_VERSION', get_client_version());

/**
 * check whether the required PHP curl module is available
 * - if yes, collect cURL version details for the info modal
 * - if not, stop and display an error message
 */
if (function_exists('curl_version')) {
    $curl_info       = curl_version();
    $curl_version    = $curl_info['version'];
    $openssl_version = $curl_info['ssl_version'];
} else {
    exit('The <b>PHP curl</b> module is not installed! Please correct this before proceeding!<br>');
    $curl_version    = 'unavailable';
    $openssl_version = 'unavailable';
}

/**
 * in order to use the PHP $_SESSION array for temporary storage of variables, session_start() is required
 */
session_start();

/**
 * check whether user has requested to clear (force expiry) the PHP session
 * - this feature can be useful when login errors occur, mostly after upgrades or credential changes
 */
if (isset($_GET['reset_session']) && $_GET['reset_session'] == true) {
    $_SESSION = [];
    session_unset();
    session_destroy();
    session_start();
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

/**
 * starting timing of the session here
 */
$time_start = microtime(true);

/**
 * declare variables which are required later on together with their default values
 */
$show_login         = false;
$controller_id      = '';
$action             = '';
$site_id            = '';
$site_name          = '';
$selection          = '';
$data               = '';
$objects_count      = '';
$alert_message      = '';
$output_format      = 'json';
$controlleruser     = '';
$controllerpassword = '';
$controllerurl      = '';
$controllername     = 'Controller';
$cookietimeout      = '3600';
$theme              = 'bootstrap';
$debug              = false;

/**
 * load the optional configuration file if readable
 * - allows override of several of the previously declared variables
 */
if (is_file('config.php') && is_readable('config.php')) {
    include('config.php');
}

/**
 * load the UniFi API client and Kint classes using composer autoloader
 */
require('vendor/autoload.php');

/**
 * set relevant Kint options
 * more info on Kint usage: http://kint-php.github.io/kint/
 */
Kint::$display_called_from = false;

/**
 * determine whether we have reached the cookie timeout, if so, refresh the PHP session
 * else, update last activity time stamp
 */
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $cookietimeout)) {
    /**
     * last activity was longer than $cookietimeout seconds ago
     */
    session_unset();
    session_destroy();
    if ($debug) {
        error_log('UniFi API browser INFO: session cookie timed out');
    }
}

$_SESSION['last_activity'] = time();

/**
 * process the GET variables and store them in the $_SESSION array
 * if a GET variable is not set, get the values from the $_SESSION array (if available)
 *
 * process in this order:
 * - controller_id
 * only process this after controller_id is set:
 * - site_id
 * only process these after site_id is set:
 * - action
 * - output_format
 */
if (isset($_GET['controller_id'])) {
    /**
     * user has requested a controller switch
     */
    if (!isset($controllers)) {
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . "?reset_session=true");
        exit;
    }
    $controller                = $controllers[$_GET['controller_id']];
    $controller_id             = $_GET['controller_id'];
    $_SESSION['controller']    = $controller;
    $_SESSION['controller_id'] = $_GET['controller_id'];

    /**
     * clear the variables from the $_SESSION array that are associated with the previous controller session
     */
    unset($_SESSION['site_id']);
    unset($_SESSION['site_name']);
    unset($_SESSION['sites']);
    unset($_SESSION['action']);
    unset($_SESSION['detected_controller_version']);
    unset($_SESSION['unificookie']);
} else {
    if (isset($_SESSION['controller']) && isset($controllers)) {
        $controller    = $_SESSION['controller'];
        $controller_id = $_SESSION['controller_id'];
    } else {
        if (!isset($controllers)) {
            /**
             * pre-load $controller array with $_SESSION['controllers'] if present
             * then load configured single site credentials
             */
            $controller = [];
            if (isset($_SESSION['controller'])) {
                $controller = $_SESSION['controller'];
            }
            if (!isset($controller['user']) || !isset($controller['password']) || !isset($controller['url'])) {
                $_SESSION['controller'] = [
                    'user'     => $controlleruser,
                    'password' => $controllerpassword,
                    'url'      => $controllerurl,
                    'name'     => $controllername
                ];
                $controller = $_SESSION['controller'];
            }
        }
    }

    if (isset($_GET['site_id'])) {
        $site_id               = $_GET['site_id'];
        $_SESSION['site_id']   = $site_id;
        $site_name             = $_GET['site_name'];
        $_SESSION['site_name'] = $site_name;
    } else {
        if (isset($_SESSION['site_id'])) {
            $site_id   = $_SESSION['site_id'];
            $site_name = $_SESSION['site_name'];
        }
    }
}

/**
 * load login form data, if present, and save to credential variables
 */
if (isset($_POST['controller_user']) && !empty($_POST['controller_user'])) {
    $controller['user'] = $_POST['controller_user'];
}

if (isset($_POST['controller_password']) && !empty($_POST['controller_password'])) {
    $controller['password'] = $_POST['controller_password'];
}

if (isset($_POST['controller_url']) && !empty($_POST['controller_url'])) {
    $controller['url'] = $_POST['controller_url'];
}

if (isset($controller)) {
    $_SESSION['controller'] = $controller;
}

/**
 * get requested theme or use the theme stored in the $_SESSION array
 */
if (isset($_GET['theme'])) {
    $theme             = $_GET['theme'];
    $_SESSION['theme'] = $theme;
    $theme_changed     = true;
} else {
    if (isset($_SESSION['theme'])) {
        $theme = $_SESSION['theme'];
    }

    $theme_changed = false;
}

/**
 * get requested output_format or use the output_format stored in the $_SESSION array
 */
if (isset($_GET['output_format'])) {
    $output_format             = $_GET['output_format'];
    $_SESSION['output_format'] = $output_format;
} else {
    if (isset($_SESSION['output_format'])) {
        $output_format = $_SESSION['output_format'];
    }
}

/**
 * get requested action or use the action stored in the $_SESSION array
 */
if (isset($_GET['action'])) {
    $action             = $_GET['action'];
    $_SESSION['action'] = $action;
} else {
    if (isset($_SESSION['action'])) {
        $action = $_SESSION['action'];
    }
}

/**
 * display info message when no controller, site or data collection is selected
 * placed here so they can be overwritten by more "severe" error messages later on
 */
// if ($action === '') {
//     $alert_message = '<div class="alert alert-info" role="alert">Please select a data collection/API endpoint from the dropdown menus ' .
//                      '<i class="fa fa-arrow-circle-up"></i></div>';
// }

// if ($site_id === '') {
//     $alert_message = '<div class="alert alert-info" role="alert">Please select a site from the Sites dropdown menu ' .
//                      '<i class="fa fa-arrow-circle-up"></i></div>';
// }

if (!isset($controller['name']) && isset($controllers)) {
    $alert_message = '<div class="alert alert-info" role="alert">Please select a controller from the Controllers dropdown menu ' .
                     '<i class="fa fa-arrow-circle-up"></i></div>';
} else {
    if (!isset($_SESSION['unificookie']) && (empty($controller['user']) || empty($controller['password']) || empty($controller['url']))) {
        $show_login    = true;
        $alert_message = '<div class="alert alert-info" role="alert">Please login to ';
        if (!empty($controller['url'])) {
            $alert_message .= '<a href="' . $controller['url'] . '">';
        }

        $alert_message .= $controller['name'];
        if (!empty($controller['url'])) {
            $alert_message .= '</a>';
        }

        if (!empty($controller['user'])) {
            $alert_message .= ' with username ' . $controller['user'];
        }

        $alert_message .= ' <i class="fa fa-sign-in"></i></div>';
    }
}


/**
 * do this when a controller has been selected and was stored in the $_SESSION array and login isn't needed
 */
if (isset($_SESSION['controller']) && $show_login !== true) {
    /**
     * create a new instance of the API client class and log in to the UniFi controller
     * - if an error occurs during the login process, an alert is displayed on the page
     */
    $unifidata      = new UniFi_API\Client(trim($controller['user']), $controller['password'], rtrim(trim($controller['url']), '/'), $site_id);
    $set_debug_mode = $unifidata->set_debug($debug);
    $loginresults   = $unifidata->login();

    if ($loginresults === 400) {
        $alert_message = '<div class="alert alert-danger" role="alert">HTTP response status: 400' .
                         '<br>This is probably caused by a UniFi controller login failure. Please check your credentials and ' .
                         '<a href="?reset_session=true">try again</a>.</div>';

        /**
         * to prevent unwanted errors we assign empty values to the following variables
         */
        $sites                       = [];
        $detected_controller_version = 'undetected';
    } else {
        /**
         * remember authentication cookie to the controller.
         */
        $_SESSION['unificookie'] = $unifidata->get_cookie();

        /**
         * get the list of sites managed by the UniFi controller (if not already stored in the $_SESSION array)
         */
        if (!isset($_SESSION['sites']) || empty($_SESSION['sites'])) {
            $sites = $unifidata->list_sites();
            if (is_array($sites)) {
                $_SESSION['sites'] = $sites;
            } else {
                $sites = [];

                $alert_message = '<div class="alert alert-danger" role="alert">No sites available' .
                                 '<br>This is probably caused by incorrect access rights in the UniFi controller, or the controller is not ' .
                                 'accepting connections. Please check your credentials and/or your server error logs and ' .
                                 '<a href="?reset_session=true">try again</a>.</div>';
            }

        } else {
            $sites = $_SESSION['sites'];
        }

        /**
         * get the version of the UniFi controller (if not already stored in the $_SESSION array or when 'undetected')
         */
        if (!isset($_SESSION['detected_controller_version']) || $_SESSION['detected_controller_version'] === 'undetected') {
            $site_info = $unifidata->stat_sysinfo();

            if (isset($site_info[0]->version)) {
                $detected_controller_version             = $site_info[0]->version;
                $_SESSION['detected_controller_version'] = $detected_controller_version;
            } else {
                $detected_controller_version             = 'undetected';
                $_SESSION['detected_controller_version'] = 'undetected';
            }

        } else {
            $detected_controller_version = $_SESSION['detected_controller_version'];
        }
    }
}

/**
 * execute timing of controller login
 */
$time_1           = microtime(true);
$time_after_login = $time_1 - $time_start;

if (isset($unifidata)) {
    /**
     * array containing attributes to fetch for the gateway stats, overriding
     * the default attributes
     */
    $gateway_stats_attribs = [
        'time',
        'mem',
        'cpu',
        'loadavg_5',
        'lan-rx_errors',
        'lan-tx_errors',
        'lan-rx_bytes',
        'lan-tx_bytes',
        'lan-rx_packets',
        'lan-tx_packets',
        'lan-rx_dropped',
        'lan-tx_dropped'
    ];

    /**
     * select the required call to the UniFi Controller API based on the selected action
     */
    switch ($action) {
        case 'list_clients':
            $selection = 'list online clients';
            $data      = $unifidata->list_clients();
            break;
        case 'stat_allusers':
            $selection = 'stat all users';
            $data      = $unifidata->stat_allusers();
            break;
        case 'stat_auths':
            $selection = 'stat active authorisations';
            $data      = $unifidata->stat_auths();
            break;
        case 'list_guests':
            $selection = 'list guests';
            $data      = $unifidata->list_guests();
            break;
        case 'list_usergroups':
            $selection = 'list usergroups';
            $data      = $unifidata->list_usergroups();
            break;
        case 'stat_5minutes_site':
            $selection = '5 minute site stats';
            $data      = $unifidata->stat_5minutes_site();
            break;
        case 'stat_hourly_site':
            $selection = 'hourly site stats';
            $data      = $unifidata->stat_hourly_site();
            break;
        case 'stat_daily_site':
            $selection = 'daily site stats';
            $data      = $unifidata->stat_daily_site();
            break;
        case 'stat_5minutes_aps':
            $selection = '5 minute ap stats';
            $data      = $unifidata->stat_5minutes_aps();
            break;
        case 'stat_hourly_aps':
            $selection = 'hourly ap stats';
            $data      = $unifidata->stat_hourly_aps();
            break;
        case 'stat_daily_aps':
            $selection = 'daily ap stats';
            $data      = $unifidata->stat_daily_aps();
            break;
        case 'stat_5minutes_gateway':
            $selection = '5 minute gateway stats';
            $data      = $unifidata->stat_5minutes_gateway(null, null, $gateway_stats_attribs);
            break;
        case 'stat_hourly_gateway':
            $selection = 'hourly gateway stats';
            $data      = $unifidata->stat_hourly_gateway(null, null, $gateway_stats_attribs);
            break;
        case 'stat_daily_gateway':
            $selection = 'daily gateway stats';
            $data      = $unifidata->stat_daily_gateway(null, null, $gateway_stats_attribs);
            break;
        case 'stat_sysinfo':
            $selection = 'sysinfo';
            $data      = $unifidata->stat_sysinfo();
            break;
        case 'list_devices':
            $selection = 'list devices';
            $data      = $unifidata->list_devices();
            break;
        case 'list_tags':
            $selection = 'list tags';
            $data      = $unifidata->list_tags();
            break;
        case 'list_wlan_groups':
            $selection = 'list wlan groups';
            $data      = $unifidata->list_wlan_groups();
            break;
        case 'stat_sessions':
            $selection = 'stat sessions';
            $data      = $unifidata->stat_sessions();
            break;
        case 'list_users':
            $selection = 'list users';
            $data      = $unifidata->list_users();
            break;
        // case 'list_rogueaps':
        //     $selection = 'list rogue access points';
        //     $data      = $unifidata->list_rogueaps();
        //     break;
        case 'list_known_rogueaps':
            $selection = 'list known rogue access points';
            $data      = $unifidata->list_known_rogueaps();
            break;
        case 'list_events':
            $selection = 'list events';
            $data      = $unifidata->list_events();
            break;
        case 'list_alarms':
            $selection = 'list alarms';
            $data      = $unifidata->list_alarms();
            break;
        case 'list_firewallgroups':
            $selection = 'list firewall groups';
            $data      = $unifidata->list_firewallgroups();
            break;
        case 'count_alarms':
            $selection = 'count all alarms';
            $data      = $unifidata->count_alarms();
            break;
        case 'count_alarms(false)':
            $selection = 'count active alarms';
            $data      = $unifidata->count_alarms(false);
            break;
        case 'list_wlanconf':
            $selection = 'list wlan config';
            $data      = $unifidata->list_wlanconf();
            break;
        case 'list_health':
            $selection = 'site health metrics';
            $data      = $unifidata->list_health();
            break;
        case 'list_dashboard(true)':
            $selection = '5 minutes site dashboard metrics';
            $data      = $unifidata->list_dashboard(true);
            break;
        case 'list_hourly_dashboard':
            $selection = 'hourly site dashboard metrics';
            $data      = $unifidata->list_dashboard();
            break;
        case 'list_settings':
            $selection = 'list site settings';
            $data      = $unifidata->list_settings();
            break;
        case 'list_sites':
            $selection = 'details of available sites';
            $data      = $sites;
            break;
        case 'list_extension':
            $selection = 'list VoIP extensions';
            $data      = $unifidata->list_extension();
            break;
        case 'list_portconf':
            $selection = 'list port configuration';
            $data      = $unifidata->list_portconf();
            break;
        case 'list_networkconf':
            $selection = 'list network configuration';
            $data      = $unifidata->list_networkconf();
            break;
        case 'list_dynamicdns':
            $selection = 'dynamic DNS configuration';
            $data      = $unifidata->list_dynamicdns();
            break;
        case 'list_current_channels':
            $selection = 'current channels';
            $data      = $unifidata->list_current_channels();
            break;
        case 'list_portforwarding':
            $selection = 'list port forwarding rules';
            $data      = $unifidata->list_portforwarding();
            break;
        case 'list_portforward_stats':
            $selection = 'list port forwarding stats';
            $data      = $unifidata->list_portforward_stats();
            break;
        case 'list_dpi_stats':
            $selection = 'list DPI stats';
            $data      = $unifidata->list_dpi_stats();
            break;
        case 'stat_voucher':
            $selection = 'list hotspot vouchers';
            $data      = $unifidata->stat_voucher();
            break;
        case 'stat_payment':
            $selection = 'list hotspot payments';
            $data      = $unifidata->stat_payment();
            break;
        case 'list_hotspotop':
            $selection = 'list hotspot operators';
            $data      = $unifidata->list_hotspotop();
            break;
        case 'list_self':
            $selection = 'self';
            $data      = $unifidata->list_self();
            break;
        case 'stat_sites':
            $selection = 'all site stats';
            $data      = $unifidata->stat_sites();
            break;
        case 'list_admins':
            $selection = 'list_admins';
            $data      = $unifidata->list_admins();
            break;
        case 'list_all_admins':
            $selection = 'list_all_admins';
            $data      = $unifidata->list_all_admins();
            break;
        case 'list_radius_accounts':
            $selection = 'list Radius accounts';
            $data      = $unifidata->list_radius_accounts();
            break;
        case 'list_radius_profiles':
            $selection = 'list Radius profiles';
            $data      = $unifidata->list_radius_profiles();
            break;
        case 'list_country_codes':
            $selection = 'list country codes';
            $data      = $unifidata->list_country_codes();
            break;
        case 'list_backups':
            $selection = 'list auto backups';
            $data      = $unifidata->list_backups();
            break;
        case 'stat_ips_events':
            $selection = 'list IPS/IDS events';
            $data      = $unifidata->stat_ips_events();
            break;
        default:
            $selection = 'daily site stats';
            $data      = $unifidata->stat_daily_site();
            break;
    }
}

/**
 * count the number of objects collected from the UniFi controller
 */
if ($action != '' && !empty($data)) {
    $objects_count = count($data);
}

/**
 * execute timing of data collection from UniFi controller
 */
$time_2          = microtime(true);
$time_after_load = $time_2 - $time_start;

/**
 * calculate all the timings/percentages
 */
$time_end    = microtime(true);
$time_total  = $time_end - $time_start;
$login_perc  = ($time_after_login / $time_total) * 100;
$load_perc   = (($time_after_load - $time_after_login) / $time_total) * 100;
$remain_perc = 100 - $login_perc - $load_perc;

/**
 * shared functions
 */

/**
 * function to print the output
 * switch depending on the selected $output_format
 */
function print_output($output_format, $data)
{
    switch ($output_format) {
        case 'json':
            echo json_encode($data, JSON_PRETTY_PRINT);
            break;
        case 'json_color':
            echo json_encode($data);
            break;
        case 'php_array':
            print_r($data);
            break;
        case 'php_array_kint':
            +d($data);
            break;
        case 'php_var_dump':
            var_dump($data);
            break;
        case 'php_var_export':
            var_export($data);
            break;
        default:
            echo json_encode($data, JSON_PRETTY_PRINT);
            break;
    }
}

/**
 * function to sort the sites collection alpabetically by description
 */
function sites_sort($site_a, $site_b)
{
    return strcmp($site_a->desc, $site_b->desc);
}

/**
 * function which returns the version of the included API client class by
 * extracting it from the composer.lock file
 */
function get_client_version()
{
    if (is_readable('composer.lock')) {
        $composer_lock = file_get_contents('composer.lock');
        $json_decoded = json_decode($composer_lock, true);

        if (isset($json_decoded['packages'])) {
            foreach ($json_decoded['packages'] as $package) {
                if ($package['name'] === 'art-of-wifi/unifi-api-client') {
                    return substr($package['version'], 1);
                }
            }
        }
    }

    return 'unknown';
}
?>
<!DOCTYPE html>
<html>
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
        <meta charset="utf-8">
        <title>UniFi Stats</title>
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
        <!-- latest compiled and minified versions of Bootstrap, Font-awesome and Highlight.js CSS, loaded from CDN -->
        <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css" integrity="sha384-wvfXpqpZZVQGK6TAh5PVlGOfQNHSoD2xbE+QkPxCAFlNEevoEH3Sl0sibVcOQVnN" crossorigin="anonymous">

        <!-- load the default Bootstrap CSS file from CDN -->
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" integrity="sha384-BVYiiSIFeK1dGmJRAkycuHAHRg32OmUcww7on3RYdg4Va+PmSTsz/K68vbdEjh4u" crossorigin="anonymous">

        <!-- placeholder to dynamically load the appropriate Bootswatch CSS file from CDN -->
        <link rel="stylesheet" href="" id="bootswatch_theme_stylesheet">

        <!-- load the jsonview CSS file from CDN -->
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jquery-jsonview/1.2.3/jquery.jsonview.min.css" integrity="sha256-OhImf+9TMPW5iYXKNT4eRNntf3fCtVYe5jZqo/mrSQA=" crossorigin="anonymous">

        <!-- define favicon  -->
        <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
        <link rel="icon" sizes="16x16" href="favicon.ico" type="image/x-icon" >

        <!-- custom CSS styling -->
        <style>
            body {
                padding-top: 70px;
            }

            .scrollable-menu {
                height: auto;
                max-height: 80vh;
                overflow-x: hidden;
                overflow-y: auto;
            }

            #output_panel_loading {
                color: rgba(0,0,0,.4);
            }

            #output {
                display: none;
                position:relative;
            }

            .back-to-top {
                cursor: pointer;
                position: fixed;
                bottom: 20px;
                right: 20px;
                display:none;
            }

            #copy_to_clipboard_button {
                 position: absolute;
                 top: 0;
                 right: 0;
            }

            #toggle_buttons {
                 display: none;
            }
        </style>
        <!-- /custom CSS styling -->
    </head>
    <body>
        <!-- top navbar -->
        <nav id="navbar" class="navbar navbar-default navbar-fixed-top">
            <div class="container-fluid">
                <div class="navbar-header">
                    <button class="navbar-toggle collapsed" type="button" data-toggle="collapse" data-target="#navbar-main">
                        <span class="icon-bar"></span>
                        <span class="icon-bar"></span>
                        <span class="icon-bar"></span>
                    </button>
                    <a class="navbar-brand" href="index.php">UniFi Stats</a>
                </div>
                <div id="navbar-main" class="collapse navbar-collapse">
                    <ul class="nav navbar-nav navbar-left">
                        <!-- controllers dropdown, only show when multiple controllers have been configured -->
                        <?php if (isset($controllers)) { ?>
                            <li id="site-menu" class="dropdown">
                                <a id="controller-menu" href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-haspopup="true" aria-expanded="false">
                                    <?php
                                    /**
                                     * here we display the UniFi controller name, if selected, else just label it
                                     */
                                    if (isset($controller['name'])) {
                                        echo $controller['name'];
                                    } else {
                                        echo 'Controllers';
                                    }
                                    ?>
                                    <span class="caret"></span>
                                </a>
                                <ul class="dropdown-menu scrollable-menu" id="controllerslist">
                                    <li class="dropdown-header">Select a controller</li>
                                    <li role="separator" class="divider"></li>
                                    <?php
                                    /**
                                     * here we loop through the configured UniFi controllers
                                     */
                                    foreach ($controllers as $key => $value) {
                                        echo '<li id="controller_' . $key . '"><a href="?controller_id=' . $key . '">' . $value['name'] . '</a></li>' . "\n";
                                    }
                                    ?>
                                 </ul>
                            </li>
                        <?php } ?>
                        <!-- /controllers dropdown -->
                        <!-- sites dropdown, only show when a controller has been selected -->
                        <?php if ($show_login === false && isset($controller['name'])) { ?>
                            <li id="site-menu" class="dropdown">
                                <a id="site-menu" href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-haspopup="true" aria-expanded="false">

                                    <?php
                                    /**
                                     * here we display the site name, if selected, else just label it
                                     */
                                    if (!empty($site_name)) {
                                        echo $site_name;
                                    } else {
                                        echo 'Sites';
                                    }
                                    ?>
                                    <span class="caret"></span>
                                </a>
                                <ul class="dropdown-menu scrollable-menu" id="siteslist">
                                    <li class="dropdown-header">Select a site</li>
                                    <li role="separator" class="divider"></li>
                                    <?php
                                    /**
                                     * here we loop through the available sites, after we've sorted the sites collection
                                     */
                                    usort($sites, "sites_sort");

                                    foreach ($sites as $site) {
                                        $link_row = '<li id="' . $site->name . '"><a href="?site_id=' .
                                                    urlencode($site->name) . '&site_name=' . urlencode($site->desc) .
                                                    '">' . $site->desc . '</a></li>' . "\n";

                                        echo $link_row;
                                    }
                                    ?>
                                 </ul>
                            </li>
                        <?php } ?>
                        <!-- /sites dropdown -->
                        <!-- data collection dropdowns, only show when a site_id is selected -->
                        
                    </ul>
                    <ul class="nav navbar-nav navbar-right" style="margin-right: 0;">
                        <li id="theme-menu" class="dropdown">
                            <a id="theme-menu" href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-haspopup="true" aria-expanded="false">
                                <i class="fa fa-bars fa-lg"></i>
                            </a>
                            <ul class="dropdown-menu scrollable-menu">
                                <li id="info" data-toggle="modal" data-target="#about_modal"><a href="#"><i class="fa fa-info-circle"></i> About UniFi API browser</a></li>
                                <li role="separator" class="divider"></li>
                                <li id="reset_session" data-toggle="tooltip" data-container="body" data-placement="left"
                                    data-original-title="In some cases this can fix login errors and/or an empty sites list">
                                    <a href="?reset_session=true"><i class="fa fa-refresh"></i> Reset PHP session</a>
                                </li>
                                <li role="separator" class="divider"></li>
                                <li class="dropdown-header">Select a theme</li>
                                <li id="bootstrap"><a href="#" onclick=switchCSS('bootstrap')>Bootstrap (default)</a></li>
                                <li id="cerulean"><a href="#" onclick=switchCSS('cerulean')>Cerulean</a></li>
                                <li id="cosmo"><a href="#" onclick=switchCSS('cosmo')>Cosmo</a></li>
                                <li id="cyborg"><a href="#" onclick=switchCSS('cyborg')>Cyborg</a></li>
                                <li id="darkly"><a href="#" onclick=switchCSS('darkly')>Darkly</a></li>
                                <li id="flatly"><a href="#" onclick=switchCSS('flatly')>Flatly</a></li>
                                <li id="journal"><a href="#" onclick=switchCSS('journal')>Journal</a></li>
                                <li id="lumen"><a href="#" onclick=switchCSS('lumen')>Lumen</a></li>
                                <li id="paper"><a href="#" onclick=switchCSS('paper')>Paper</a></li>
                                <li id="readable"><a href="#" onclick=switchCSS('readable')>Readable</a></li>
                                <li id="sandstone"><a href="#" onclick=switchCSS('sandstone')>Sandstone</a></li>
                                <li id="simplex"><a href="#" onclick=switchCSS('simplex')>Simplex</a></li>
                                <li id="slate"><a href="#" onclick=switchCSS('slate')>Slate</a></li>
                                <li id="solar"><a href="#" onclick=switchCSS('solar')>Solar</a></li>
                                <li id="spacelab"><a href="#" onclick=switchCSS('spacelab')>Spacelab</a></li>
                                <li id="superhero"><a href="#" onclick=switchCSS('superhero')>Superhero</a></li>
                                <li id="united"><a href="#" onclick=switchCSS('united')>United</a></li>
                                <li id="yeti"><a href="#" onclick=switchCSS('yeti')>Yeti</a></li>
                            </ul>
                        </li>
                    </ul>
                </div><!-- /.nav-collapse -->
            </div><!-- /.container-fluid -->
        </nav><!-- /top navbar -->
        <div class="container-fluid">
            <div id="alert_placeholder" style="display: none"></div>
            <!-- login_form, only to be displayed when we have no controller config -->
            <div id="login_form" style="display: none">
                <div class="col-xs-offset-1 col-xs-10 col-sm-offset-3 col-sm-6 col-md-offset-3 col-md-6 col-lg-offset-4 col-lg-4">
                    <div class="panel panel-default">
                        <div class="panel-body">
                            <h3 align="center">UniFi Stats login</h3>
                            <br>
                            <div id="login_alert_placeholder" style="display: none"></div>
                            <form method="post">
                                <?php if (empty($controller['user'])) : ?>
                                    <div class="form-group">
                                        <label for="input_controller_user">Username</label>
                                        <input type="text" id="input_controller_user" name="controller_user" class="form-control" placeholder="Controller username">
                                    </div>
                                <?php endif; ?>
                                <?php if (empty($controller['password'])) : ?>
                                    <div class="form-group">
                                        <label for="input_controller_password">Password</label>
                                        <input type="password" id="input_controller_password" name="controller_password" class="form-control" placeholder="Controller password">
                                    </div>
                                <?php endif; ?>
                                <?php if (empty($controller['url'])) : ?>
                                    <div class="form-group">
                                        <label for="input_controller_url">URL</label>
                                        <input type="text" id="input_controller_url" name="controller_url" class="form-control" placeholder="https://<controller FQDN or IP>:8443" value="https://unifi.paulamato.com.au/">
                                    </div>
                                <?php endif; ?>
                                <input type="submit" name="login" class="btn btn-primary pull-right" value="Login">
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <!-- /login_form -->
            <!-- data-panel, only to be displayed once a controller has been configured and an action has been selected, while loading we display a temp div -->
            <?php if (isset($_SESSION['unificookie']) && $action) { ?>
            <div id="output_panel_loading" class="text-center">
                <br>
                <h2><i class="fa fa-spinner fa-spin fa-fw"></i></h2>
            </div>
            
            <?php } ?>
            <!-- /data-panel -->
        </div>
        <!-- back-to-top button element -->
        <a id="back-to-top" href="#" class="btn btn-primary back-to-top" role="button" title="Back to top" data-toggle="tooltip" data-placement="left"><i class="fa fa-chevron-up" aria-hidden="true"></i></a>
        <!-- /back-to-top button element -->
        <!-- About modal -->
        <div class="modal fade" id="about_modal" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                        <h4 class="modal-title" id="myModalLabel"><i class="fa fa-info-circle"></i> About UniFi API browser</h4>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-sm-10 col-sm-offset-1">
                                A tool for browsing the data collections which are exposed through Ubiquiti's UniFi Controller API.
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-sm-8 col-sm-offset-1"><a href="http://www.dereferer.org/?https://github.com/Art-of-WiFi/UniFi-API-browser"
                            target="_blank">UniFi API browser on Github</a></div>
                            <div class="col-sm-8 col-sm-offset-1"><a href="http://www.dereferer.org/?http://community.ubnt.com/t5/UniFi-Wireless/UniFi-API-browser-tool-updates-and-discussion/m-p/1392651#U1392651"
                            target="_blank">UniFi API browser on Ubiquiti Community forum</a></div>
                            <div class="col-sm-8 col-sm-offset-1"><a href="http://www.dereferer.org/?https://github.com/Art-of-WiFi/UniFi-API-client"
                            target="_blank">UniFi API client on Github</a></div>
                        </div>
                        <hr>
                        <dl class="dl-horizontal col-sm-offset-2">
                            <dt>API browser version</dt>
                            <dd><span id="span_api_browser_version" class="label label-primary"></span> <span id="span_api_browser_update" class="label label-success"><i class="fa fa-spinner fa-spin fa-fw"></i> checking for updates</span></dd>
                            <dt>API client version</dt>
                            <dd><span id="span_api_class_version" class="label label-primary"></span></dd>
                        </dl>
                        <hr>
                        <dl class="dl-horizontal col-sm-offset-2">
                            <dt>controller user</dt>
                            <dd><span id="span_controller_user" class="label label-primary"></span></dd>
                            <dt>controller URL</dt>
                            <dd><span id="span_controller_url" class="label label-primary"></span></dd>
                            <dt>version detected</dt>
                            <dd><span id="span_controller_version" class="label label-primary"></span></dd>
                        </dl>
                        <hr>
                        <dl class="dl-horizontal col-sm-offset-2">
                            <dt>PHP version</dt>
                            <dd><span id="span_php_version" class="label label-primary"></span></dd>
                            <dt>PHP memory_limit</dt>
                            <dd><span id="span_memory_limit" class="label label-primary"></span></dd>
                            <dt>PHP memory used</dt>
                            <dd><span id="span_memory_used" class="label label-primary"></span></dd>
                            <dt>cURL version</dt>
                            <dd><span id="span_curl_version" class="label label-primary"></span></dd>
                            <dt>OpenSSL version</dt>
                            <dd><span id="span_openssl_version" class="label label-primary"></span></dd>
                            <dt>operating system</dt>
                            <dd><span id="span_os_version" class="label label-primary"></span></dd>
                        </dl>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-primary" data-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
        <?php if (isset($_SESSION['unificookie'])) { ?>

          <?php
            $current_day = date("d");
            $current_month = date("m");
            $current_year = date("Y");
          ?>
          <div class="usage-form">
            <form>
              <div>
                <label>From</label>
                <select name="from_d">
                  <option<?php if ($_GET["from_d"] == '01') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '1') { echo ' selected'; } ?>>01</option>
                  <option<?php if ($_GET["from_d"] == '02') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '2') { echo ' selected'; } ?>>02</option>
                  <option<?php if ($_GET["from_d"] == '03') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '3') { echo ' selected'; } ?>>03</option>
                  <option<?php if ($_GET["from_d"] == '04') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '4') { echo ' selected'; } ?>>04</option>
                  <option<?php if ($_GET["from_d"] == '05') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '5') { echo ' selected'; } ?>>05</option>
                  <option<?php if ($_GET["from_d"] == '06') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '6') { echo ' selected'; } ?>>06</option>
                  <option<?php if ($_GET["from_d"] == '07') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '7') { echo ' selected'; } ?>>07</option>
                  <option<?php if ($_GET["from_d"] == '08') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '8') { echo ' selected'; } ?>>08</option>
                  <option<?php if ($_GET["from_d"] == '09') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '9') { echo ' selected'; } ?>>09</option>
                  <option<?php if ($_GET["from_d"] == '10') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '10') { echo ' selected'; } ?>>10</option>
                  <option<?php if ($_GET["from_d"] == '11') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '11') { echo ' selected'; } ?>>11</option>
                  <option<?php if ($_GET["from_d"] == '12') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '12') { echo ' selected'; } ?>>12</option>
                  <option<?php if ($_GET["from_d"] == '13') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '13') { echo ' selected'; } ?>>13</option>
                  <option<?php if ($_GET["from_d"] == '14') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '14') { echo ' selected'; } ?>>14</option>
                  <option<?php if ($_GET["from_d"] == '15') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '15') { echo ' selected'; } ?>>15</option>
                  <option<?php if ($_GET["from_d"] == '16') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '16') { echo ' selected'; } ?>>16</option>
                  <option<?php if ($_GET["from_d"] == '17') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '17') { echo ' selected'; } ?>>17</option>
                  <option<?php if ($_GET["from_d"] == '18') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '18') { echo ' selected'; } ?>>18</option>
                  <option<?php if ($_GET["from_d"] == '19') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '19') { echo ' selected'; } ?>>19</option>
                  <option<?php if ($_GET["from_d"] == '20') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '20') { echo ' selected'; } ?>>20</option>
                  <option<?php if ($_GET["from_d"] == '21') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '21') { echo ' selected'; } ?>>21</option>
                  <option<?php if ($_GET["from_d"] == '22') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '22') { echo ' selected'; } ?>>22</option>
                  <option<?php if ($_GET["from_d"] == '23') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '23') { echo ' selected'; } ?>>23</option>
                  <option<?php if ($_GET["from_d"] == '24') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '24') { echo ' selected'; } ?>>24</option>
                  <option<?php if ($_GET["from_d"] == '25') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '25') { echo ' selected'; } ?>>25</option>
                  <option<?php if ($_GET["from_d"] == '26') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '26') { echo ' selected'; } ?>>26</option>
                  <option<?php if ($_GET["from_d"] == '27') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '27') { echo ' selected'; } ?>>27</option>
                  <option<?php if ($_GET["from_d"] == '28') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '28') { echo ' selected'; } ?>>28</option>
                  <option<?php if ($_GET["from_d"] == '29') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '29') { echo ' selected'; } ?>>29</option>
                  <option<?php if ($_GET["from_d"] == '30') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '30') { echo ' selected'; } ?>>30</option>
                  <option<?php if ($_GET["from_d"] == '31') { echo ' selected'; } elseif ($_GET["from_d"] == '' && $current_day == '31') { echo ' selected'; } ?>>31</option>
                </select>
                <select name="from_m">
                  <option<?php if ($_GET["from_m"] == '01') { echo ' selected'; } elseif ($_GET["from_m"] == '' && $current_month == '01') { echo ' selected'; } ?>>01</option>
                  <option<?php if ($_GET["from_m"] == '02') { echo ' selected'; } elseif ($_GET["from_m"] == '' && $current_month == '02') { echo ' selected'; } ?>>02</option>
                  <option<?php if ($_GET["from_m"] == '03') { echo ' selected'; } elseif ($_GET["from_m"] == '' && $current_month == '03') { echo ' selected'; } ?>>03</option>
                  <option<?php if ($_GET["from_m"] == '04') { echo ' selected'; } elseif ($_GET["from_m"] == '' && $current_month == '04') { echo ' selected'; } ?>>04</option>
                  <option<?php if ($_GET["from_m"] == '05') { echo ' selected'; } elseif ($_GET["from_m"] == '' && $current_month == '05') { echo ' selected'; } ?>>05</option>
                  <option<?php if ($_GET["from_m"] == '06') { echo ' selected'; } elseif ($_GET["from_m"] == '' && $current_month == '06') { echo ' selected'; } ?>>06</option>
                  <option<?php if ($_GET["from_m"] == '07') { echo ' selected'; } elseif ($_GET["from_m"] == '' && $current_month == '07') { echo ' selected'; } ?>>07</option>
                  <option<?php if ($_GET["from_m"] == '08') { echo ' selected'; } elseif ($_GET["from_m"] == '' && $current_month == '08') { echo ' selected'; } ?>>08</option>
                  <option<?php if ($_GET["from_m"] == '09') { echo ' selected'; } elseif ($_GET["from_m"] == '' && $current_month == '09') { echo ' selected'; } ?>>09</option>
                  <option<?php if ($_GET["from_m"] == '10') { echo ' selected'; } elseif ($_GET["from_m"] == '' && $current_month == '10') { echo ' selected'; } ?>>10</option>
                  <option<?php if ($_GET["from_m"] == '11') { echo ' selected'; } elseif ($_GET["from_m"] == '' && $current_month == '11') { echo ' selected'; } ?>>11</option>
                  <option<?php if ($_GET["from_m"] == '12') { echo ' selected'; } elseif ($_GET["from_m"] == '' && $current_month == '12') { echo ' selected'; } ?>>12</option>
                </select>
                <select name="from_y">
                  <option<?php if ($_GET["from_y"] == '2019') { echo ' selected'; } elseif ($_GET["from_y"] == '' && $current_year == '2019') { echo ' selected'; } ?>>2019</option>
                  <option<?php if ($_GET["from_y"] == '2020') { echo ' selected'; } elseif ($_GET["from_y"] == '' && $current_year == '2020') { echo ' selected'; } ?>>2020</option>
                  <option<?php if ($_GET["from_y"] == '2021') { echo ' selected'; } elseif ($_GET["from_y"] == '' && $current_year == '2021') { echo ' selected'; } ?>>2021</option>
                  <option<?php if ($_GET["from_y"] == '2022') { echo ' selected'; } elseif ($_GET["from_y"] == '' && $current_year == '2022') { echo ' selected'; } ?>>2022</option>
                  <option<?php if ($_GET["from_y"] == '2023') { echo ' selected'; } elseif ($_GET["from_y"] == '' && $current_year == '2023') { echo ' selected'; } ?>>2023</option>
                  <option<?php if ($_GET["from_y"] == '2024') { echo ' selected'; } elseif ($_GET["from_y"] == '' && $current_year == '2024') { echo ' selected'; } ?>>2024</option>
                  <option<?php if ($_GET["from_y"] == '2025') { echo ' selected'; } elseif ($_GET["from_y"] == '' && $current_year == '2025') { echo ' selected'; } ?>>2025</option>
                </select>
              </div>
              <div>
                <label>To</label>
                <select name="to_d">
                  <option<?php if ($_GET["to_d"] == '01') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '1') { echo ' selected'; } ?>>01</option>
                  <option<?php if ($_GET["to_d"] == '02') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '2') { echo ' selected'; } ?>>02</option>
                  <option<?php if ($_GET["to_d"] == '03') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '3') { echo ' selected'; } ?>>03</option>
                  <option<?php if ($_GET["to_d"] == '04') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '4') { echo ' selected'; } ?>>04</option>
                  <option<?php if ($_GET["to_d"] == '05') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '5') { echo ' selected'; } ?>>05</option>
                  <option<?php if ($_GET["to_d"] == '06') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '6') { echo ' selected'; } ?>>06</option>
                  <option<?php if ($_GET["to_d"] == '07') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '7') { echo ' selected'; } ?>>07</option>
                  <option<?php if ($_GET["to_d"] == '08') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '8') { echo ' selected'; } ?>>08</option>
                  <option<?php if ($_GET["to_d"] == '09') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '9') { echo ' selected'; } ?>>09</option>
                  <option<?php if ($_GET["to_d"] == '10') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '10') { echo ' selected'; } ?>>10</option>
                  <option<?php if ($_GET["to_d"] == '11') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '11') { echo ' selected'; } ?>>11</option>
                  <option<?php if ($_GET["to_d"] == '12') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '12') { echo ' selected'; } ?>>12</option>
                  <option<?php if ($_GET["to_d"] == '13') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '13') { echo ' selected'; } ?>>13</option>
                  <option<?php if ($_GET["to_d"] == '14') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '14') { echo ' selected'; } ?>>14</option>
                  <option<?php if ($_GET["to_d"] == '15') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '15') { echo ' selected'; } ?>>15</option>
                  <option<?php if ($_GET["to_d"] == '16') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '16') { echo ' selected'; } ?>>16</option>
                  <option<?php if ($_GET["to_d"] == '17') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '17') { echo ' selected'; } ?>>17</option>
                  <option<?php if ($_GET["to_d"] == '18') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '18') { echo ' selected'; } ?>>18</option>
                  <option<?php if ($_GET["to_d"] == '19') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '19') { echo ' selected'; } ?>>19</option>
                  <option<?php if ($_GET["to_d"] == '20') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '20') { echo ' selected'; } ?>>20</option>
                  <option<?php if ($_GET["to_d"] == '21') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '21') { echo ' selected'; } ?>>21</option>
                  <option<?php if ($_GET["to_d"] == '22') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '22') { echo ' selected'; } ?>>22</option>
                  <option<?php if ($_GET["to_d"] == '23') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '23') { echo ' selected'; } ?>>23</option>
                  <option<?php if ($_GET["to_d"] == '24') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '24') { echo ' selected'; } ?>>24</option>
                  <option<?php if ($_GET["to_d"] == '25') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '25') { echo ' selected'; } ?>>25</option>
                  <option<?php if ($_GET["to_d"] == '26') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '26') { echo ' selected'; } ?>>26</option>
                  <option<?php if ($_GET["to_d"] == '27') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '27') { echo ' selected'; } ?>>27</option>
                  <option<?php if ($_GET["to_d"] == '28') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '28') { echo ' selected'; } ?>>28</option>
                  <option<?php if ($_GET["to_d"] == '29') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '29') { echo ' selected'; } ?>>29</option>
                  <option<?php if ($_GET["to_d"] == '30') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '30') { echo ' selected'; } ?>>30</option>
                  <option<?php if ($_GET["to_d"] == '31') { echo ' selected'; } elseif ($_GET["to_d"] == '' && $current_day == '31') { echo ' selected'; } ?>>31</option>
                </select>
                <select name="to_m">
                  <option<?php if ($_GET["to_m"] == '01') { echo ' selected'; } elseif ($_GET["to_m"] == '' && $current_month == '01') { echo ' selected'; } ?>>01</option>
                  <option<?php if ($_GET["to_m"] == '02') { echo ' selected'; } elseif ($_GET["to_m"] == '' && $current_month == '02') { echo ' selected'; } ?>>02</option>
                  <option<?php if ($_GET["to_m"] == '03') { echo ' selected'; } elseif ($_GET["to_m"] == '' && $current_month == '03') { echo ' selected'; } ?>>03</option>
                  <option<?php if ($_GET["to_m"] == '04') { echo ' selected'; } elseif ($_GET["to_m"] == '' && $current_month == '04') { echo ' selected'; } ?>>04</option>
                  <option<?php if ($_GET["to_m"] == '05') { echo ' selected'; } elseif ($_GET["to_m"] == '' && $current_month == '05') { echo ' selected'; } ?>>05</option>
                  <option<?php if ($_GET["to_m"] == '06') { echo ' selected'; } elseif ($_GET["to_m"] == '' && $current_month == '06') { echo ' selected'; } ?>>06</option>
                  <option<?php if ($_GET["to_m"] == '07') { echo ' selected'; } elseif ($_GET["to_m"] == '' && $current_month == '07') { echo ' selected'; } ?>>07</option>
                  <option<?php if ($_GET["to_m"] == '08') { echo ' selected'; } elseif ($_GET["to_m"] == '' && $current_month == '08') { echo ' selected'; } ?>>08</option>
                  <option<?php if ($_GET["to_m"] == '09') { echo ' selected'; } elseif ($_GET["to_m"] == '' && $current_month == '09') { echo ' selected'; } ?>>09</option>
                  <option<?php if ($_GET["to_m"] == '10') { echo ' selected'; } elseif ($_GET["to_m"] == '' && $current_month == '10') { echo ' selected'; } ?>>10</option>
                  <option<?php if ($_GET["to_m"] == '11') { echo ' selected'; } elseif ($_GET["to_m"] == '' && $current_month == '11') { echo ' selected'; } ?>>11</option>
                  <option<?php if ($_GET["to_m"] == '12') { echo ' selected'; } elseif ($_GET["to_m"] == '' && $current_month == '12') { echo ' selected'; } ?>>12</option>
                </select>
                <select name="to_y">
                  <option<?php if ($_GET["to_y"] == '2019') { echo ' selected'; } elseif ($_GET["to_y"] == '' && $current_year == '2019') { echo ' selected'; } ?>>2019</option>
                  <option<?php if ($_GET["to_y"] == '2020') { echo ' selected'; } elseif ($_GET["to_y"] == '' && $current_year == '2020') { echo ' selected'; } ?>>2020</option>
                  <option<?php if ($_GET["to_y"] == '2021') { echo ' selected'; } elseif ($_GET["to_y"] == '' && $current_year == '2021') { echo ' selected'; } ?>>2021</option>
                  <option<?php if ($_GET["to_y"] == '2022') { echo ' selected'; } elseif ($_GET["to_y"] == '' && $current_year == '2022') { echo ' selected'; } ?>>2022</option>
                  <option<?php if ($_GET["to_y"] == '2023') { echo ' selected'; } elseif ($_GET["to_y"] == '' && $current_year == '2023') { echo ' selected'; } ?>>2023</option>
                  <option<?php if ($_GET["to_y"] == '2024') { echo ' selected'; } elseif ($_GET["to_y"] == '' && $current_year == '2024') { echo ' selected'; } ?>>2024</option>
                  <option<?php if ($_GET["to_y"] == '2025') { echo ' selected'; } elseif ($_GET["to_y"] == '' && $current_year == '2025') { echo ' selected'; } ?>>2025</option>
                </select><br />
              </div>
              <input type="submit" name="submit" value="Submit">
            </form>
            <form>
              <label>Last number of days</label>
              <div>
                <select name="days">
                  <option<?php if ($_GET["days"] == '1') { echo ' selected'; } ?>>1</option>
                  <option<?php if ($_GET["days"] == '2') { echo ' selected'; } ?>>2</option>
                  <option<?php if ($_GET["days"] == '3') { echo ' selected'; } ?>>3</option>
                  <option<?php if ($_GET["days"] == '7') { echo ' selected'; } ?>>7</option>
                  <option<?php if ($_GET["days"] == '30') { echo ' selected'; } elseif ($_GET["days"] == '') { echo ' selected';} ?>>30</option>
                  <option<?php if ($_GET["days"] == '60') { echo ' selected'; } ?>>60</option>
                  <option<?php if ($_GET["days"] == '90') { echo ' selected'; } ?>>90</option>
                  <option<?php if ($_GET["days"] == '120') { echo ' selected'; } ?>>120</option>
                  <option<?php if ($_GET["days"] == '365') { echo ' selected'; } ?>>365</option>
                </select>
              </div>
              <input type="submit" name="submit" value="Submit">
            </form>
          </div>
        <?php } ?>

        <!-- latest compiled and minified JavaScript versions, loaded from CDN's, now including Source Integrity hashes, just in case... -->
        <script src="https://code.jquery.com/jquery-2.2.4.min.js" integrity="sha384-rY/jv8mMhqDabXSo+UCggqKtdmBfd3qC2/KvyTDNQ6PcUJXaxK1tMepoQda4g5vB" crossorigin="anonymous"></script>
        <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js" integrity="sha384-Tc5IQib027qvyjSMfHjOMaLkfuWVxZxUPnCJA7l2mCWNIpG9mGCD8wGNIcPD7Txa" crossorigin="anonymous"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-jsonview/1.2.3/jquery.jsonview.min.js" integrity="sha384-DmFpgjLdJIZgLscJ9DpCHynOVhvNfaTvPJA+1ijsbkMKhI/e8eKnBZp1AmHDVjrT" crossorigin="anonymous"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/clipboard.js/2.0.1/clipboard.min.js" integrity="sha384-JbuAF+8+4FF8uR/7D07/5IWwIj2FoNw5jJQGg1s8GtGIfQXFxWPRdMNWYmU35KjP" crossorigin="anonymous"></script>
        <script>
            /**
             * populate some global Javascript variables with PHP output for cleaner code
             */
            var alert_message       = '<?php echo $alert_message ?>',
                show_login          = '<?php echo $show_login ?>',
                action              = '<?php echo $action ?>',
                site_id             = '<?php echo $site_id ?>',
                site_name           = '<?php echo htmlspecialchars($site_name) ?>',
                controller_id       = '<?php echo $controller_id ?>',
                output_format       = '<?php echo $output_format ?>',
                selection           = '<?php echo $selection ?>',
                objects_count       = '<?php echo $objects_count ?>',
                timing_login_perc   = '<?php echo $login_perc ?>',
                time_after_login    = '<?php echo $time_after_login ?>',
                timing_load_perc    = '<?php echo $load_perc ?>',
                time_for_load       = '<?php echo ($time_after_load - $time_after_login) ?>',
                timing_remain_perc  = '<?php echo $remain_perc ?>',
                timing_total_time   = '<?php echo $time_total ?>',
                php_version         = '<?php echo (phpversion()) ?>',
                memory_limit        = '<?php echo (ini_get('memory_limit')) ?>',
                memory_used         = '<?php echo round(memory_get_peak_usage(false) / 1024 / 1024, 2) . 'M' ?>',
                curl_version        = '<?php echo $curl_version ?>',
                openssl_version     = '<?php echo $openssl_version ?>',
                os_version          = '<?php echo (php_uname('s') . ' ' . php_uname('r')) ?>',
                api_browser_version = '<?php echo API_BROWSER_VERSION ?>',
                api_class_version   = '<?php echo API_CLASS_VERSION ?>',
                controller_user     = '<?php if (isset($controller['user'])) echo $controller['user'] ?>',
                controller_url      = '<?php if (isset($controller['url'])) echo $controller['url'] ?>',
                controller_version  = '<?php if (isset($detected_controller_version)) echo $detected_controller_version ?>';
                theme               = 'bootstrap';

            /**
             * check whether user has stored a custom theme, if yes we switch to the stored value
             */
            if (localStorage.getItem('API_browser_theme') == null || localStorage.getItem('API_browser_theme') === 'bootstrap') {
                $('#bootstrap').addClass('active').find('a').append(' <i class="fa fa-check"></i>');
            } else {
                var stored_theme = localStorage.getItem('API_browser_theme');
                switchCSS(stored_theme);
            }

            /**
             * process a Bootswatch CSS stylesheet change
             */
            function switchCSS(new_theme) {
                console.log('current theme: ' + theme + ' new theme: ' + new_theme);
                if (new_theme === 'bootstrap') {
                    $('#bootswatch_theme_stylesheet').attr('href', '');
                } else {
                    $('#bootswatch_theme_stylesheet').attr('href', 'https://maxcdn.bootstrapcdn.com/bootswatch/3.3.7/' + new_theme + '/bootstrap.min.css');
                }

                $('#' + theme).removeClass('active').find('a').children('i').remove();
                $('#' + new_theme).addClass('active').find('a').append(' <i class="fa fa-check"></i>');
                theme = new_theme;
                localStorage.setItem('API_browser_theme', theme);
            }

            $(document).ready(function () {
                /**
                 * we hide the loading div and show the output panel
                 */
                $('#output_panel_loading').hide();
                $('#output_panel').show();

                /**
                 * if needed we display the login form
                 */
                if (show_login == 1 || show_login == 'true') {
                    $('#login_alert_placeholder').html(alert_message);
                    $('#login_alert_placeholder').show();
                    $('#login_form').fadeIn(500);
                } else {
                    $('#alert_placeholder').html(alert_message);
                    $('#alert_placeholder').fadeIn(500);
                }

                /**
                 * update dynamic elements in the DOM using some of the above variables
                 */
                $('#span_site_id').html(site_id);
                $('#span_site_name').html(site_name);
                $('#span_output_format').html(output_format);
                $('#span_selection').html(selection);

                if (action.includes('(') || action.includes('(')) {
                    $('#span_function').html(action);
                } else {
                    $('#span_function').html(action + '()');
                }

                $('#span_objects_count').html(objects_count);
                $('#span_elapsed_time').html('total elapsed time: ' + timing_total_time + ' seconds');

                $('#timing_login_perc').attr('aria-valuenow', timing_login_perc);
                $('#timing_login_perc').css('width', timing_login_perc + '%');
                $('#timing_login_perc').attr('data-original-title', time_after_login + ' seconds');
                $('#timing_login_perc').html('API login time');
                $('#timing_load_perc').attr('aria-valuenow', timing_load_perc);
                $('#timing_load_perc').css('width', timing_load_perc + '%');
                $('#timing_load_perc').attr('data-original-title', time_for_load + ' seconds');
                $('#timing_load_perc').html('data load time');
                $('#timing_remain_perc').attr('aria-valuenow', timing_remain_perc);
                $('#timing_remain_perc').css('width', timing_remain_perc + '%');
                $('#timing_remain_perc').attr('data-original-title', 'PHP overhead: ' + timing_remain_perc + '%');
                $('#timing_remain_perc').html('PHP overhead');

                $('#span_api_browser_version').html(api_browser_version);
                $('#span_api_class_version').html(api_class_version);
                $('#span_controller_user').html(controller_user);
                $('#span_controller_url').html(controller_url);
                $('#span_controller_version').html(controller_version);
                $('#span_php_version').html(php_version);
                $('#span_curl_version').html(curl_version);
                $('#span_openssl_version').html(openssl_version);
                $('#span_os_version').html(os_version);
                $('#span_memory_limit').html(memory_limit);
                $('#span_memory_used').html(memory_used);

                /**
                 * highlight and mark the selected options in the dropdown menus for $controller_id, $action, $site_id, $theme and $output_format
                 *
                 * NOTE:
                 * these actions are performed conditionally
                 */
                (action != '') ? $('#' + action).addClass('active').find('a').append(' <i class="fa fa-check"></i>') : false;
                (site_id != '') ? $('#' + site_id).addClass('active').find('a').append(' <i class="fa fa-check"></i>') : false;
                (controller_id != '') ? $('#controller_' + controller_id).addClass('active').find('a').append(' <i class="fa fa-check"></i>') : false;

                /**
                 * these two options have default values so no tests needed here
                 */
                $('#' + output_format).addClass('active').find('a').append(' <i class="fa fa-check"></i>');

                /**
                 * initialise the jquery-jsonview library, only when required
                 */
                if (output_format == 'json_color') {
                    $('#toggle_buttons').show();
                    $('#pre_output').JSONView($('#pre_output').text());

                    /**
                     * the expand/collapse toggle buttons to control the json view
                     */
                    $('#toggle-btn').on('click', function () {
                        $('#pre_output').JSONView('toggle');
                        $('#i_toggle-btn').toggleClass('fa-plus').toggleClass('fa-minus');
                        $(this).blur();
                    });

                    $('#toggle-level2-btn').on('click', function () {
                        $('#pre_output').JSONView('toggle', 2);
                        $('#i_toggle-level2-btn').toggleClass('fa-plus').toggleClass('fa-minus');
                        $(this).blur();
                    });
                }

                /**
                 * only now do we display the output
                 */
                $('#output').show();

                /**
                 * check latest version of API browser tool and inform user when it's more recent than the current,
                 * but only when the "about" modal is opened
                 */
                $('#about_modal').on('shown.bs.modal', function (e) {
                    $.getJSON('https://api.github.com/repos/Art-of-WiFi/UniFi-API-browser/releases/latest', function (external) {
                        if (api_browser_version != '' && typeof(external.tag_name) !== 'undefined') {
                            if (api_browser_version < external.tag_name.substring(1)) {
                                $('#span_api_browser_update').html('an update is available: ' + external.tag_name.substring(1));
                                $('#span_api_browser_update').removeClass('label-success').addClass('label-warning');
                            } else if (api_browser_version == external.tag_name.substring(1)) {
                                $('#span_api_browser_update').html('up to date');
                            } else {
                                $('#span_api_browser_update').html('bleeding edge!');
                                $('#span_api_browser_update').removeClass('label-success').addClass('label-danger');
                            }
                        }
                    }).fail(function (d, textStatus, error) {
                        $('#span_api_browser_update').html('error checking updates');
                        $('#span_api_browser_update').removeClass('label-success').addClass('label-danger');
                        console.error('getJSON failed, status: ' + textStatus + ', error: ' + error);
                    });;
                })

                /**
                 * and reset the span again when the "about" modal is closed
                 */
                $('#about_modal').on('hidden.bs.modal', function (e) {
                    $('#span_api_browser_update').html('<i class="fa fa-spinner fa-spin fa-fw"></i> checking for updates</span>');
                    $('#span_api_browser_update').removeClass('label-warning').removeClass('label-danger').addClass('label-success');
                })

                /**
                 * initialize the "copy to clipboard" function, "borrowed" from the UserFrosting framework
                 */
                if (typeof $.uf === 'undefined') {
                    $.uf = {};
                }

                $.uf.copy = function (button) {
                    var _this = this;

                    var clipboard = new ClipboardJS(button, {
                        text: function (trigger) {
                            var el = $(trigger).closest('.js-copy-container').find('.js-copy-target');
                            if (el.is(':input')) {
                                return el.val();
                            } else {
                                return el.html();
                            }
                        }
                    });

                    clipboard.on('success', function (e) {
                        setTooltip(e.trigger, 'Copied!');
                        hideTooltip(e.trigger);
                    });

                    clipboard.on('error', function (e) {
                        setTooltip(e.trigger, 'Failed!');
                        hideTooltip(e.trigger);
                        console.log('Copy to clipboard failed, most probably the selection is too large');
                    });

                    function setTooltip(btn, message) {
                        $(btn)
                        .attr('data-original-title', message)
                        .tooltip('show');
                    }

                    function hideTooltip(btn) {
                        setTimeout(function () {
                            $(btn).tooltip('hide')
                            .attr('data-original-title', 'Copy to clipboard');
                        }, 500);
                    }

                    /**
                     * tooltip trigger
                     */
                    $(button).tooltip({
                        trigger: 'hover'
                    });
                };

                /**
                 * link the copy button
                 */
                $.uf.copy('.js-copy-trigger');

                /**
                 * hide "copy to clipboard" button if the ClipboardJS function isn't supported or the output format isn't supported
                 */
                var unsupported_formats = [
                    'json',
                    'php_array',
                    'php_var_dump',
                    'php_var_export'
                ];
                if (!ClipboardJS.isSupported() || $.inArray(output_format, unsupported_formats) === -1) {
                    $('.js-copy-trigger').hide();
                }

                /**
                 * manage display of the "back to top" button element
                 */
                $(window).scroll(function () {
                    if ($(this).scrollTop() > 50) {
                        $('#back-to-top').fadeIn();
                    } else {
                        $('#back-to-top').fadeOut();
                    }
                });

                /**
                 * scroll body to 0px (top) on click on the "back to top" button
                 */
                $('#back-to-top').click(function () {
                    $('#back-to-top').tooltip('hide');
                    $('body,html').animate({
                        scrollTop: 0
                    }, 500);
                    return false;
                });

                $('#back-to-top').tooltip('show');

                /**
                 * enable Bootstrap tooltips
                 */
                $(function () {
                    $('[data-toggle="tooltip"]').tooltip()
                })
            });
        </script>


        <script>
          $( document ).ready(function() {
            console.log( "ready!" );

            $grandTotal = 0;
            $grandTotal_raw = 0;
            obj = $data['data'];

            Object.keys(obj).forEach(function(key) {


                //console.log(key, obj[key]);

                $date_raw = obj[key]['time'];

                var formattedDate = new Date($date_raw);
                var d = formattedDate.getDate();
                var m =  formattedDate.getMonth();
                m += 1;  // JavaScript months are 0-11
                var y = formattedDate.getFullYear();
                $date = d + "/" + m + "/" + y;

                var start = new Date(formattedDate),
                  end   = new Date(),
                  diff  = new Date(end - start),
                  days  = Math.round(diff/1000/60/60/24);
                
                <?php
                  $days = $_GET["days"];
                  if (!$_GET["days"]) {
                    $days = 30;
                  }


                  $from_d = $_GET["from_d"];
                  $from_m = $_GET["from_m"];
                  $from_y = $_GET["from_y"];
                  $to_d = $_GET["to_d"];
                  $to_m = $_GET["to_m"];
                  $to_y = $_GET["to_y"];
                  $startDate = $from_y . '.' . $from_m . '.' . $from_d;
                  $endDate = $to_y . '.' . $to_m . '.' . $to_d;


                ?>
                <?php if ($_GET["from_d"] && $_GET["from_m"] && $_GET["from_y"]): ?>

                  
                  $startDate_raw = new Date('<?php echo $startDate; ?>').getTime();
                  //$startDate_raw = new Date($startDate_raw.toString().replace(' ', 'T'));
                  var $startDate = new Date($startDate_raw);
                  $endDate_raw = new Date('<?php echo $endDate; ?>').getTime();
                  //$endDate_raw = new Date($endDate_raw.toString().replace(' ', 'T'));
                  var $endDate = new Date($endDate_raw);

                  if ($startDate <= formattedDate && $endDate >= formattedDate) {
                    writeData();
                  }
                <?php else: ?>
                  if (days <= <?php echo $days; ?>) {
                    writeData();
                  }
                <?php endif; ?>


                function writeData() {
                  $down_raw = obj[key]['wan-rx_bytes'] / 1073741824;
                  $down = Math.round( $down_raw * 100 ) / 100;
                  $up_raw = obj[key]['wan-tx_bytes'] / 1073741824;
                  $up = Math.round( $up_raw * 100 ) / 100;

                  $total_raw = $down_raw + $up_raw;
                  $total = Math.round( $total_raw * 100 ) / 100;

                  $usage_line = '';
                  $usage_line += '<div class="usage-line">';
                    $usage_line += '<div>' + $date + ' <span>(' + days + ' days ago)</span></div>';
                    $usage_line += '<div>' + $up + 'GB</div>';
                    $usage_line += '<div>' + $down + 'GB</div>';
                    $usage_line += '<div>' + $total + 'GB</div>';
                  $usage_line += '</div>';
                  $('.usage').append($usage_line);

                  $grandTotal_raw += $up_raw;
                  $grandTotal_raw += $down_raw;
                }
                
            });
            
            $grandTotal = Math.round( $grandTotal_raw * 100 ) / 100;
            $('.usage').prepend('<h2>Total: ' + $grandTotal + 'GB</h2>');



            <?php if ($from_d && $from_m && $from_y && $to_d && $to_m && $to_y): ?>
              $('.usage').prepend('<hr></hr><h5>Usage from <?php echo $from_d . '/' . $from_m . '/' . $from_y . ' to ' . $to_d . '/' . $to_m . '/' . $to_y; ?></h5>');
            <?php elseif ($days == ''): ?>
              $('.usage').prepend('<hr></hr><h5>Usage over the last the 30 days</h5>');
            <?php else: ?>
              $('.usage').prepend('<hr></hr><h5>Usage over the last the <?php echo $_GET["days"]; ?> days</h5>');
            <?php endif; ?>


            // console.log( $dataObj );
          });
        </script>
        <!-- <script type="application/javascript" src="vendor/jquery-dateformat.min.js" /> -->
        
        <?php if (isset($_SESSION['unificookie'])) : ?>
          <div class="usage">
            <div class="usage-headings">
              <div>Date</div>
              <div>Uploads</div>
              <div>Downloads</div>
              <div>Total</div>
            </div>
          </div>
        <?php endif; ?>

        <style>
          .usage {
            padding: 0 2rem;
            box-sizing: border-box;
          }
          .usage div {
            padding: 0.1rem;
            box-sizing: border-box;
          }
          .usage-headings {
            font-size: 2rem;
            border-bottom: 1px solid #DDDDDD;
          }
          .usage > div {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            text-align: left;
          }
          .usage-form {
            padding: 0 2rem;
            box-sizing: border-box;
          }
          .usage-form form {
            display: flex;
            align-items: center;
          }
          .usage-form label {
            margin: 0 1rem 0 0;
          }
          .usage-form form div {
            margin: 0 2rem 0 0;
          }
          h2 {
            margin: 0;
            padding: 0 0 20px;
            border-bottom: 1px solid #DDDDDD;
          }
          hr {
            margin: 10px 0 20px;
          }
          @media screen and (max-width: 680px) {
            .usage-line span {
              display: none;
            }
            .usage-headings {
              font-size: 1.4rem;
              font-weight: bold;
            }
            .usage-form form {
              flex-direction: column;
              align-items: flex-start;
            }
            .usage-form form > div {
              padding: 0.5rem 0;
            }
          }
        </style>

    </body>
</html>
